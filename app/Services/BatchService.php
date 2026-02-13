<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\Logger;
use PDO;

/**
 * Batch Operations Service
 * Handles all batch-level operations on groups of guarantees
 * 
 * Decision #4: Reuse individual guarantee logic, don't create new business logic
 */
class BatchService
{
    private PDO $db;
    
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connect();
    }
    
    /**
     * Check if batch is closed
     */
    public function isBatchClosed(string $importSource): bool
    {
        $stmt = $this->db->prepare("
            SELECT status FROM batch_metadata WHERE import_source = ?
        ");
        $stmt->execute([$importSource]);
        $batch = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // If no metadata, batch is implicit (active)
        if (!$batch) {
            return false;
        }
        
        return $batch['status'] === 'completed';
    }
    
    /**
     * Get all guarantees in a batch (based on occurrences)
     *
     * @param array<int, int|string>|null $guaranteeIds
     */
    public function getBatchGuarantees(string $importSource, ?array $guaranteeIds = null): array
    {
        $sql = "
            SELECT g.*, 
                   d.id as decision_id,
                   d.status,
                   d.supplier_id,
                   d.bank_id,
                   d.active_action,
                   d.is_locked,
                   d.locked_reason
            FROM guarantees g
            JOIN guarantee_occurrences o ON o.guarantee_id = g.id
            LEFT JOIN guarantee_decisions d ON d.guarantee_id = g.id
            WHERE o.batch_identifier = ?
        ";
        $params = [$importSource];

        if (!empty($guaranteeIds)) {
            $placeholders = implode(',', array_fill(0, count($guaranteeIds), '?'));
            $sql .= " AND g.id IN ($placeholders)";
            $params = array_merge($params, $guaranteeIds);
        }

        $sql .= " ORDER BY g.id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Extend guarantees in batch (each guarantee evaluated independently)
     */
    public function extendBatch(string $importSource, ?string $newExpiryDate, string $userId = 'system', ?array $guaranteeIds = null): array
    {
        // Check if closed
        if ($this->isBatchClosed($importSource)) {
            return [
                'success' => false,
                'error' => 'الدفعة مغلقة - لا يمكن التمديد الجماعي'
            ];
        }

        $ids = $this->normalizeIds($guaranteeIds);
        $hasSelection = $guaranteeIds !== null;
        if ($hasSelection && empty($ids)) {
            return [
                'success' => false,
                'error' => 'لا توجد ضمانات محددة'
            ];
        }
        // Get all guarantees (optionally filtered)
        $guarantees = $this->getBatchGuarantees($importSource, $hasSelection ? $ids : null);
        
        if (empty($guarantees)) {
            return [
                'success' => false,
                'error' => 'الدفعة فارغة'
            ];
        }

        $foundIds = array_map('intval', array_column($guarantees, 'id'));
        $invalidIds = $hasSelection ? array_values(array_diff($ids, $foundIds)) : [];

        if ($newExpiryDate !== null && $newExpiryDate !== '' && !$this->isValidDate($newExpiryDate)) {
            return [
                'success' => false,
                'error' => 'تاريخ التمديد غير صالح'
            ];
        }
        
        // All ready - extend using INDIVIDUAL logic from extend.php
        $extended = [];
        $errors = [];
        $blocked = [];
        
        $guaranteeRepo = new \App\Repositories\GuaranteeRepository($this->db);
        $decisionRepo = new \App\Repositories\GuaranteeDecisionRepository($this->db);
        
        foreach ($guarantees as $g) {
            try {
                if (empty($g['decision_id'])) {
                    $blocked[] = [
                        'guarantee_id' => $g['id'],
                        'guarantee_number' => $g['guarantee_number'],
                        'reason' => 'لا يوجد قرار لهذا الضمان'
                    ];
                    continue;
                }
                if (!empty($g['is_locked'])) {
                    $blocked[] = [
                        'guarantee_id' => $g['id'],
                        'guarantee_number' => $g['guarantee_number'],
                        'reason' => 'مقفل'
                    ];
                    continue;
                }
                if (!empty($g['active_action'])) {
                    $blocked[] = [
                        'guarantee_id' => $g['id'],
                        'guarantee_number' => $g['guarantee_number'],
                        'reason' => 'تم تنفيذ إجراء سابق'
                    ];
                    continue;
                }
                if (($g['status'] ?? '') !== 'ready' || !$g['supplier_id']) {
                    $blocked[] = [
                        'guarantee_id' => $g['id'],
                        'guarantee_number' => $g['guarantee_number'],
                        'reason' => 'غير جاهز'
                    ];
                    continue;
                }

                $guarantee = $guaranteeRepo->find($g['id']);
                if (!$guarantee) {
                    $blocked[] = [
                        'guarantee_id' => $g['id'],
                        'guarantee_number' => $g['guarantee_number'],
                        'reason' => 'الضمان غير موجود'
                    ];
                    continue;
                }

                $raw = $guarantee->rawData;
                $oldExpiry = $raw['expiry_date'] ?? '';
                $newExpiry = $this->resolveExpiryDate($oldExpiry, $newExpiryDate);

                if (!$newExpiry) {
                    $blocked[] = [
                        'guarantee_id' => $g['id'],
                        'guarantee_number' => $g['guarantee_number'],
                        'reason' => 'تاريخ التمديد غير صالح'
                    ];
                    continue;
                }

                if (!$this->isExpiryAfterOld($oldExpiry, $newExpiry)) {
                    $blocked[] = [
                        'guarantee_id' => $g['id'],
                        'guarantee_number' => $g['guarantee_number'],
                        'reason' => 'تاريخ التمديد يجب أن يكون بعد التاريخ الحالي'
                    ];
                    continue;
                }

                $this->db->beginTransaction();
                try {
                    // 1. Snapshot
                    $oldSnapshot = \App\Services\TimelineRecorder::createSnapshot($g['id']);
                    if (!$oldSnapshot) {
                        throw new \RuntimeException('تعذر إنشاء Snapshot');
                    }

                    // 2. Update - Apply new expiry
                    $raw['expiry_date'] = $newExpiry;
                    $guaranteeRepo->updateRawData($g['id'], json_encode($raw));
                    
                    // 3. Set Active Action
                    $decisionRepo->setActiveAction($g['id'], 'extension');

                    // 3.1 Track user-driven decision source
                    $decisionUpdate = $this->db->prepare("
                        UPDATE guarantee_decisions
                        SET decision_source = 'manual',
                            decided_by = ?,
                            last_modified_by = ?,
                            last_modified_at = CURRENT_TIMESTAMP
                        WHERE guarantee_id = ?
                    ");
                    $decisionUpdate->execute([$userId, $userId, $g['id']]);
                    
                    // 4. Record in Timeline
                    $eventId = \App\Services\TimelineRecorder::recordExtensionEvent(
                        $g['id'],
                        $oldSnapshot,
                        $newExpiry
                    );
                    if (!$eventId) {
                        throw new \RuntimeException('لم يتم تسجيل حدث التمديد');
                    }

                    $this->db->commit();
                    $extended[] = $g['id'];
                } catch (\Throwable $e) {
                    if ($this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    throw $e;
                }
            } catch (\Exception $e) {
                $errors[] = [
                    'guarantee_id' => $g['id'],
                    'guarantee_number' => $g['guarantee_number'],
                    'error' => $e->getMessage()
                ];
            }
        }
        
        $success = count($extended) > 0;
        Logger::info('batch_extend', [
            'import_source' => $importSource,
            'user_id' => $userId,
            'selected_ids' => $hasSelection ? $ids : null,
            'processed_ids' => $extended,
            'invalid_ids' => $invalidIds,
            'blocked' => $blocked,
            'errors' => $errors,
            'success' => $success
        ]);
        return [
            'success' => $success,
            'error' => $success ? null : 'لا توجد ضمانات مؤهلة للتمديد',
            'extended_count' => count($extended),
            'extended_ids' => $extended,
            'blocked_count' => count($blocked),
            'blocked' => $blocked,
            'invalid_count' => count($invalidIds),
            'invalid_ids' => $invalidIds,
            'errors' => $errors
        ];
    }
    
    /**
     * Release guarantees in batch (each guarantee evaluated independently)
     */
    public function releaseBatch(string $importSource, ?string $reason = null, string $userId = 'system', ?array $guaranteeIds = null): array
    {
        // Check if closed
        if ($this->isBatchClosed($importSource)) {
            return [
                'success' => false,
                'error' => 'الدفعة مغلقة - لا يمكن الإفراج الجماعي'
            ];
        }
        
        $ids = $this->normalizeIds($guaranteeIds);
        $hasSelection = $guaranteeIds !== null;
        if ($hasSelection && empty($ids)) {
            return [
                'success' => false,
                'error' => 'لا توجد ضمانات محددة'
            ];
        }
        // Get all guarantees (optionally filtered)
        $guarantees = $this->getBatchGuarantees($importSource, $hasSelection ? $ids : null);
        
        if (empty($guarantees)) {
            return [
                'success' => false,
                'error' => 'الدفعة فارغة'
            ];
        }

        $foundIds = array_map('intval', array_column($guarantees, 'id'));
        $invalidIds = $hasSelection ? array_values(array_diff($ids, $foundIds)) : [];
        
        // All ready - release using INDIVIDUAL logic from release.php
        $released = [];
        $errors = [];
        $blocked = [];
        
        $decisionRepo = new \App\Repositories\GuaranteeDecisionRepository($this->db);
        
        foreach ($guarantees as $g) {
            try {
                if (empty($g['decision_id'])) {
                    $blocked[] = [
                        'guarantee_id' => $g['id'],
                        'guarantee_number' => $g['guarantee_number'],
                        'reason' => 'لا يوجد قرار لهذا الضمان'
                    ];
                    continue;
                }
                if (!empty($g['is_locked'])) {
                    $blocked[] = [
                        'guarantee_id' => $g['id'],
                        'guarantee_number' => $g['guarantee_number'],
                        'reason' => 'مقفل'
                    ];
                    continue;
                }
                if (!empty($g['active_action'])) {
                    $blocked[] = [
                        'guarantee_id' => $g['id'],
                        'guarantee_number' => $g['guarantee_number'],
                        'reason' => 'تم تنفيذ إجراء سابق'
                    ];
                    continue;
                }
                if (($g['status'] ?? '') !== 'ready' || !$g['supplier_id'] || !$g['bank_id']) {
                    $blocked[] = [
                        'guarantee_id' => $g['id'],
                        'guarantee_number' => $g['guarantee_number'],
                        'reason' => 'غير جاهز'
                    ];
                    continue;
                }

                $this->db->beginTransaction();
                try {
                    // 1. Snapshot
                    $oldSnapshot = \App\Services\TimelineRecorder::createSnapshot($g['id']);
                    if (!$oldSnapshot) {
                        throw new \RuntimeException('تعذر إنشاء Snapshot');
                    }
                    
                    // 2. Lock the guarantee + status
                    $decisionRepo->lock($g['id'], 'released');
                    $statusStmt = $this->db->prepare("
                        UPDATE guarantee_decisions
                        SET status = 'released',
                            decision_source = 'manual',
                            decided_by = ?,
                            last_modified_by = ?,
                            last_modified_at = CURRENT_TIMESTAMP
                        WHERE guarantee_id = ?
                    ");
                    $statusStmt->execute([$userId, $userId, $g['id']]);
                    
                    // 3. Set Active Action
                    $decisionRepo->setActiveAction($g['id'], 'release');
                    
                    // 4. Record in Timeline
                    $eventId = \App\Services\TimelineRecorder::recordReleaseEvent($g['id'], $oldSnapshot, $reason);
                    if (!$eventId) {
                        throw new \RuntimeException('لم يتم تسجيل حدث الإفراج');
                    }

                    $this->db->commit();
                    $released[] = $g['id'];
                } catch (\Throwable $e) {
                    if ($this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    throw $e;
                }
            } catch (\Exception $e) {
                $errors[] = [
                    'guarantee_id' => $g['id'],
                    'guarantee_number' => $g['guarantee_number'],
                    'error' => $e->getMessage()
                ];
            }
        }
        
        $success = count($released) > 0;
        Logger::info('batch_release', [
            'import_source' => $importSource,
            'user_id' => $userId,
            'selected_ids' => $hasSelection ? $ids : null,
            'processed_ids' => $released,
            'invalid_ids' => $invalidIds,
            'blocked' => $blocked,
            'errors' => $errors,
            'success' => $success
        ]);
        return [
            'success' => $success,
            'error' => $success ? null : 'لا توجد ضمانات مؤهلة للإفراج',
            'released_count' => count($released),
            'released_ids' => $released,
            'blocked_count' => count($blocked),
            'blocked' => $blocked,
            'invalid_count' => count($invalidIds),
            'invalid_ids' => $invalidIds,
            'errors' => $errors
        ];
    }

    /**
     * Reduce guarantees in batch (each guarantee evaluated independently)
     */
    public function reduceBatch(string $importSource, ?float $newAmount, string $userId = 'system', ?array $guaranteeIds = null, ?array $amountsById = null): array
    {
        if ($this->isBatchClosed($importSource)) {
            return [
                'success' => false,
                'error' => 'الدفعة مغلقة - لا يمكن التخفيض الجماعي'
            ];
        }

        $amountsById = $this->normalizeReductionMap($amountsById);
        $usePerItemAmounts = !empty($amountsById);

        if (!$usePerItemAmounts && ($newAmount === null || $newAmount <= 0)) {
            return [
                'success' => false,
                'error' => 'المبلغ غير صحيح'
            ];
        }

        if ($usePerItemAmounts && $guaranteeIds === null) {
            $guaranteeIds = array_keys($amountsById);
        }

        $ids = $this->normalizeIds($guaranteeIds);
        $hasSelection = $guaranteeIds !== null;
        if ($hasSelection && empty($ids)) {
            return [
                'success' => false,
                'error' => 'لا توجد ضمانات محددة'
            ];
        }

        $guarantees = $this->getBatchGuarantees($importSource, $hasSelection ? $ids : null);
        if (empty($guarantees)) {
            return [
                'success' => false,
                'error' => 'الدفعة فارغة'
            ];
        }

        $foundIds = array_map('intval', array_column($guarantees, 'id'));
        $invalidIds = $hasSelection ? array_values(array_diff($ids, $foundIds)) : [];

        $reduced = [];
        $errors = [];
        $blocked = [];

        $guaranteeRepo = new \App\Repositories\GuaranteeRepository($this->db);
        $decisionRepo = new \App\Repositories\GuaranteeDecisionRepository($this->db);

        foreach ($guarantees as $g) {
            try {
                if (empty($g['decision_id'])) {
                    $blocked[] = [
                        'guarantee_id' => $g['id'],
                        'guarantee_number' => $g['guarantee_number'],
                        'reason' => 'لا يوجد قرار لهذا الضمان'
                    ];
                    continue;
                }
                if (!empty($g['is_locked'])) {
                    $blocked[] = [
                        'guarantee_id' => $g['id'],
                        'guarantee_number' => $g['guarantee_number'],
                        'reason' => 'مقفل'
                    ];
                    continue;
                }
                if (!empty($g['active_action'])) {
                    $blocked[] = [
                        'guarantee_id' => $g['id'],
                        'guarantee_number' => $g['guarantee_number'],
                        'reason' => 'تم تنفيذ إجراء سابق'
                    ];
                    continue;
                }
                if (($g['status'] ?? '') !== 'ready') {
                    $blocked[] = [
                        'guarantee_id' => $g['id'],
                        'guarantee_number' => $g['guarantee_number'],
                        'reason' => 'غير جاهز'
                    ];
                    continue;
                }

                $guarantee = $guaranteeRepo->find($g['id']);
                if (!$guarantee) {
                    $blocked[] = [
                        'guarantee_id' => $g['id'],
                        'guarantee_number' => $g['guarantee_number'],
                        'reason' => 'الضمان غير موجود'
                    ];
                    continue;
                }

                $raw = $guarantee->rawData;
                $targetAmount = $usePerItemAmounts ? ($amountsById[(int) $g['id']] ?? null) : $newAmount;
                if ($targetAmount === null || $targetAmount <= 0) {
                    $blocked[] = [
                        'guarantee_id' => $g['id'],
                        'guarantee_number' => $g['guarantee_number'],
                        'reason' => 'لم يتم تحديد مبلغ التخفيض'
                    ];
                    continue;
                }

                $previousAmount = (float)($raw['amount'] ?? 0);
                if ($previousAmount === (float)$targetAmount) {
                    $blocked[] = [
                        'guarantee_id' => $g['id'],
                        'guarantee_number' => $g['guarantee_number'],
                        'reason' => 'المبلغ لم يتغير'
                    ];
                    continue;
                }

                $this->db->beginTransaction();
                try {
                    $oldSnapshot = \App\Services\TimelineRecorder::createSnapshot($g['id']);
                    if (!$oldSnapshot) {
                        throw new \RuntimeException('تعذر إنشاء Snapshot');
                    }

                    $raw['amount'] = (float)$targetAmount;
                    $guaranteeRepo->updateRawData($g['id'], json_encode($raw));

                    $decisionRepo->setActiveAction($g['id'], 'reduction');
                    $decisionUpdate = $this->db->prepare("
                        UPDATE guarantee_decisions
                        SET decision_source = 'manual',
                            decided_by = ?,
                            last_modified_by = ?,
                            last_modified_at = CURRENT_TIMESTAMP
                        WHERE guarantee_id = ?
                    ");
                    $decisionUpdate->execute([$userId, $userId, $g['id']]);

                    $eventId = \App\Services\TimelineRecorder::recordReductionEvent(
                        $g['id'],
                        $oldSnapshot,
                        (float)$targetAmount,
                        $previousAmount
                    );
                    if (!$eventId) {
                        throw new \RuntimeException('لم يتم تسجيل حدث التخفيض');
                    }

                    $this->db->commit();
                    $reduced[] = $g['id'];
                } catch (\Throwable $e) {
                    if ($this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    throw $e;
                }
            } catch (\Exception $e) {
                $errors[] = [
                    'guarantee_id' => $g['id'],
                    'guarantee_number' => $g['guarantee_number'],
                    'error' => $e->getMessage()
                ];
            }
        }

        $success = count($reduced) > 0;
        Logger::info('batch_reduce', [
            'import_source' => $importSource,
            'user_id' => $userId,
            'selected_ids' => $hasSelection ? $ids : null,
            'processed_ids' => $reduced,
            'invalid_ids' => $invalidIds,
            'blocked' => $blocked,
            'errors' => $errors,
            'success' => $success
        ]);
        return [
            'success' => $success,
            'error' => $success ? null : 'لا توجد ضمانات مؤهلة للتخفيض',
            'reduced_count' => count($reduced),
            'reduced_ids' => $reduced,
            'blocked_count' => count($blocked),
            'blocked' => $blocked,
            'invalid_count' => count($invalidIds),
            'invalid_ids' => $invalidIds,
            'errors' => $errors
        ];
    }

    /**
     * Normalize and validate guarantee IDs from request payload.
     *
     * @param array<int, int|string>|null $guaranteeIds
     * @return array<int, int>
     */
    private function normalizeIds(?array $guaranteeIds): array
    {
        if (empty($guaranteeIds)) {
            return [];
        }

        $ids = array_filter($guaranteeIds, fn($id) => is_numeric($id));
        $ids = array_map('intval', $ids);
        $ids = array_values(array_unique($ids));

        return $ids;
    }

    /**
     * @param array<int|string, mixed>|null $amountsById
     * @return array<int, float>
     */
    private function normalizeReductionMap(?array $amountsById): array
    {
        if (empty($amountsById)) {
            return [];
        }

        $normalized = [];
        foreach ($amountsById as $id => $amount) {
            if (!is_numeric($id) || !is_numeric($amount)) {
                continue;
            }
            $normalized[(int) $id] = (float) $amount;
        }

        return $normalized;
    }

    private function isValidDate(?string $date): bool
    {
        if ($date === null || $date === '') {
            return false;
        }
        $dt = \DateTime::createFromFormat('Y-m-d', $date);
        return $dt !== false && $dt->format('Y-m-d') === $date;
    }

    private function resolveExpiryDate(string $oldExpiry, ?string $newExpiryDate): ?string
    {
        if ($newExpiryDate !== null && $newExpiryDate !== '') {
            return $newExpiryDate;
        }
        $candidate = trim($oldExpiry) === '' ? ' +1 year' : $oldExpiry . ' +1 year';
        $timestamp = strtotime($candidate);
        if ($timestamp === false) {
            return null;
        }
        return date('Y-m-d', $timestamp);
    }

    private function isExpiryAfterOld(string $oldExpiry, string $newExpiry): bool
    {
        if (!$this->isValidDate($oldExpiry) || !$this->isValidDate($newExpiry)) {
            return true;
        }
        $old = \DateTime::createFromFormat('Y-m-d', $oldExpiry);
        $new = \DateTime::createFromFormat('Y-m-d', $newExpiry);
        if (!$old || !$new) {
            return true;
        }
        return $new > $old;
    }

    
    /**
     * Update batch metadata
     * Decision #2: Allowed even on completed batches
     */
    public function updateMetadata(string $importSource, ?string $batchName, ?string $batchNotes): array
    {        // Get or create metadata
        $stmt = $this->db->prepare("
            SELECT id FROM batch_metadata WHERE import_source = ?
        ");
        $stmt->execute([$importSource]);
        $batch = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$batch) {
            // Create new metadata record (Decision #12: manual creation only)
            $stmt = $this->db->prepare("
                INSERT INTO batch_metadata (import_source, batch_name, batch_notes) 
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$importSource, $batchName, $batchNotes]);
        } else {
            // Update existing (only update non-null values)
            $updates = [];
            $params = [];
            
            if ($batchName !== null) {
                $updates[] = "batch_name = ?";
                $params[] = $batchName;
            }
            
            if ($batchNotes !== null) {
                $updates[] = "batch_notes = ?";
                $params[] = $batchNotes;
            }
            
            if (!empty($updates)) {
                $params[] = $importSource;
                $sql = "UPDATE batch_metadata SET " . implode(', ', $updates) . " WHERE import_source = ?";
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);
            }
        }
        
        return ['success' => true];
    }
    
    /**
     * Close batch
     * Decision #8: Disables group operations, allows individual work
     */
    public function closeBatch(string $importSource, string $closedBy = 'system'): array
    {
        // Get or create metadata
        $stmt = $this->db->prepare("
            SELECT id FROM batch_metadata WHERE import_source = ?
        ");
        $stmt->execute([$importSource]);
        $batch = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$batch) {
            // Create metadata with completed status
            $stmt = $this->db->prepare("
                INSERT INTO batch_metadata (import_source, status) VALUES (?, 'completed')
            ");
            $stmt->execute([$importSource]);
        } else {
            // Update status
            $stmt = $this->db->prepare("
                UPDATE batch_metadata SET status = 'completed' WHERE import_source = ?
            ");
            $stmt->execute([$importSource]);
        }
        
        return ['success' => true];
    }
    
    /**
     * Reopen batch
     * Decision #7: Allowed with explicit user action
     */
    public function reopenBatch(string $importSource, string $reopenedBy = 'system'): array
    {
        $stmt = $this->db->prepare("
            UPDATE batch_metadata SET status = 'active' 
            WHERE import_source = ? AND status = 'completed'
        ");
        $stmt->execute([$importSource]);
        
        if ($stmt->rowCount() === 0) {
            return [
                'success' => false,
                'error' => 'الدفعة غير موجودة أو غير مغلقة'
            ];
        }
        
        return ['success' => true];
    }
    
    /**
     * Get batch summary with derived data
     * Decision #2: Derive don't store
     */
    public function getBatchSummary(string $importSource): ?array
    {
        // Get metadata if exists
        $stmt = $this->db->prepare("
            SELECT * FROM batch_metadata WHERE import_source = ?
        ");
        $stmt->execute([$importSource]);
        $metadata = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get derived data from guarantees
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as guarantee_count,
                MIN(imported_at) as created_at,
                GROUP_CONCAT(DISTINCT imported_by) as imported_by
            FROM guarantees 
            WHERE import_source = ?
        ");
        $stmt->execute([$importSource]);
        $derived = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($derived['guarantee_count'] == 0) {
            return null;  // Batch doesn't exist or is empty
        }
        
        // Parse source type
        $sourceType = 'unknown';
        if (strpos($importSource, 'excel_') === 0) {
            $sourceType = 'excel';
        } elseif (strpos($importSource, 'manual_paste_') === 0) {
            $sourceType = 'manual_paste';
        } elseif (strpos($importSource, 'manual_') === 0) {
            $sourceType = 'manual';
        }
        
        return [
            'import_source' => $importSource,
            'batch_name' => $metadata['batch_name'] ?? null,
            'batch_notes' => $metadata['batch_notes'] ?? null,
            'status' => $metadata['status'] ?? 'active',
            'guarantee_count' => (int)$derived['guarantee_count'],
            'created_at' => $derived['created_at'],
            'created_by' => $derived['imported_by'],
            'source_type' => $sourceType
        ];
    }
}
