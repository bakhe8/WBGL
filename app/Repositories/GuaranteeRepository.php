<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Models\Guarantee;
use App\Services\HistoryArchiveService;
use PDO;

/**
 * GuaranteeRepository (V3)
 * 
 * Manages guarantees table - raw data storage only
 */
class GuaranteeRepository
{
    private PDO $db;
    
    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function getDb(): PDO
    {
        return $this->db;
    }
    
    /**
     * Find guarantee by ID
     */
    public function find(int $id): ?Guarantee
    {
        $stmt = $this->db->prepare("
            SELECT * FROM guarantees WHERE id = ?
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $row ? $this->hydrate($row) : null;
    }
    
    /**
     * Find guarantee by guarantee_number
     */
    public function findByNumber(string $guaranteeNumber): ?Guarantee
    {
        $stmt = $this->db->prepare("
            SELECT * FROM guarantees WHERE guarantee_number = ?
        ");
        $stmt->execute([$guaranteeNumber]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $row ? $this->hydrate($row) : null;
    }
    
    /**
     * Create new guarantee
     */
    public function create(Guarantee $guarantee): Guarantee
    {
        // Extract and normalize supplier name for indexed column
        // UPDATED: Learning Merge 2026-01-04
        $supplierName = $guarantee->rawData['supplier'] ?? null;
        $normalized = $supplierName ? \App\Support\ArabicNormalizer::normalize($supplierName) : null;
        
        $stmt = $this->db->prepare("
            INSERT INTO guarantees (
                guarantee_number,
                raw_data,
                normalized_supplier_name,
                import_source,
                imported_at,
                imported_by
            ) VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $guarantee->guaranteeNumber,
            json_encode($guarantee->rawData),
            $normalized,
            $guarantee->importSource,
            $guarantee->importedAt ?? date('Y-m-d H:i:s'),
            $guarantee->importedBy
        ]);
        
        $id = (int)$this->db->lastInsertId();
        
        // ✅ STRICT INTEGRITY: Re-fetch from DB to ensure returned object 
        // reflects exactly what was stored (Post-Persist State).
        // This captures any DB-side normalization or encoding effects.
        $persisted = $this->find($id);
        
        if (!$persisted) {
             // Fallback (should never happen in normal operation)
             $guarantee->id = $id;
             return $guarantee;
        }
        
        return $persisted;
    }
    
    /**
     * Update guarantee raw_data (P2: Mutation Isolation)
     * 
     * Centralized mutation point for raw_data updates (e.g., extend, reduce actions)
     * Ensures all raw_data changes go through repository layer
     * 
     * @param int $guaranteeId Guarantee ID
     * @param string $rawData Updated JSON raw_data
     * @return void
     */
    public function updateRawData(int $guaranteeId, string $rawData): void
    {
        $stmt = $this->db->prepare('
            UPDATE guarantees 
            SET raw_data = ? 
            WHERE id = ?
        ');
        $stmt->execute([$rawData, $guaranteeId]);
    }    
    
    /**
     * Get all guarantees with filters
     */
    public function getAll(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        [$whereClause, $params] = $this->buildWhereClause($filters);
        
        $sql = "
            SELECT * FROM guarantees
            {$whereClause}
            ORDER BY imported_at DESC
            LIMIT ? OFFSET ?
        ";
        
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        $guarantees = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $guarantees[] = $this->hydrate($row);
        }
        
        return $guarantees;
    }
    
    /**
     * Count guarantees
     */
    public function count(array $filters = []): int
    {
        [$whereClause, $params] = $this->buildWhereClause($filters);
        
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM guarantees {$whereClause}");
        $stmt->execute($params);
        
        return (int)$stmt->fetchColumn();
    }
    
    /**
     * Build WHERE clause from filters
     */
    private function buildWhereClause(array $filters): array
    {
        $where = [];
        $params = [];
        
        // NEW: Handle test data filtering with correct priority
        if (isset($filters['test_data_only']) && $filters['test_data_only'] === true) {
            // ONLY test data
            $where[] = 'COALESCE(is_test_data, 0) = 1';
        } elseif (!isset($filters['include_test_data']) || $filters['include_test_data'] === false) {
            // Default: exclude test data.
            $where[] = 'COALESCE(is_test_data, 0) = 0';
        }
        // If include_test_data = true, don't add any filter (show all)
        
        if (!empty($filters['import_source'])) {
            $where[] = 'import_source = ?';
            $params[] = $filters['import_source'];
        }
        
        if (!empty($filters['date_from'])) {
            $where[] = 'DATE(imported_at) >= ?';
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $where[] = 'DATE(imported_at) <= ?';
            $params[] = $filters['date_to'];
        }
        
        $whereClause = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);
        
        return [$whereClause, $params];
    }
    
    /**
     * Hydrate Guarantee from DB row
     */
    private function hydrate(array $row): Guarantee
    {
        return new Guarantee(
            id: $row['id'],
            guaranteeNumber: $row['guarantee_number'],
            rawData: json_decode($row['raw_data'], true),
            importSource: $row['import_source'],
            importedAt: $row['imported_at'],
            importedBy: $row['imported_by']
        );
    }
    
    // ==========================================
    // TEST DATA ISOLATION METHODS (Phase 1)
    // ==========================================
    
    /**
     * Mark a guarantee as test data
     * 
     * @param int $id Guarantee ID
     * @param string|null $batchId Optional batch identifier for grouped deletion
     * @param string|null $note Optional note about this test data
     * @return bool Success status
     */
    public function markAsTestData(int $id, ?string $batchId = null, ?string $note = null): bool
    {
        $stmt = $this->db->prepare('
            UPDATE guarantees 
            SET is_test_data = 1,
                test_batch_id = ?,
                test_note = ?
            WHERE id = ?
        ');
        
        return $stmt->execute([$batchId, $note, $id]);
    }
    
    /**
     * Convert a test guarantee to real guarantee
     * (Remove test data markers)
     * 
     * @param int $id Guarantee ID
     * @return bool Success status
     */
    public function convertToReal(int $id): bool
    {
        $this->db->beginTransaction();
        try {
            // When converting a single record from a test-only batch that contains
            // multiple guarantees, move this record to an isolated real batch first.
            // This preserves batch-purity guarantees (no mixed test/real batches).
            $moveCandidates = $this->db->prepare('
                SELECT
                    o.id AS occurrence_id,
                    o.batch_identifier,
                    stats.guarantee_count
                FROM guarantee_occurrences o
                JOIN (
                    SELECT batch_identifier, COUNT(DISTINCT guarantee_id) AS guarantee_count
                    FROM guarantee_occurrences
                    GROUP BY batch_identifier
                ) stats ON stats.batch_identifier = o.batch_identifier
                WHERE o.guarantee_id = ?
            ');
            $moveCandidates->execute([$id]);
            $rows = $moveCandidates->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $occurrenceId = (int)($row['occurrence_id'] ?? 0);
                $batchIdentifier = (string)($row['batch_identifier'] ?? '');
                $batchCount = (int)($row['guarantee_count'] ?? 0);
                if ($occurrenceId <= 0 || $batchIdentifier === '' || $batchCount <= 1) {
                    continue;
                }

                $suffix = substr(sha1($batchIdentifier . '|' . $id), 0, 10);
                $isolatedBatch = 'realized_' . $id . '_' . $suffix;

                $updateOccurrence = $this->db->prepare('
                    UPDATE guarantee_occurrences
                    SET batch_identifier = ?,
                        batch_type = ?
                    WHERE id = ?
                ');
                $updateOccurrence->execute([$isolatedBatch, 'manual_realization', $occurrenceId]);

                $upsertMetadata = $this->db->prepare('
                    INSERT INTO batch_metadata (import_source, batch_name, batch_notes, status)
                    VALUES (?, ?, ?, ?)
                    ON CONFLICT (import_source) DO NOTHING
                ');
                $upsertMetadata->execute([
                    $isolatedBatch,
                    'دفعة تحويل إلى حقيقي',
                    'Generated automatically while converting test guarantee to real data',
                    'completed',
                ]);
            }

            $stmt = $this->db->prepare('
                UPDATE guarantees
                SET is_test_data = 0,
                    test_batch_id = NULL,
                    test_note = NULL
                WHERE id = ?
            ');
            $stmt->execute([$id]);
            $updated = $stmt->rowCount() > 0;

            $this->db->commit();
            return $updated;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
    
    /**
     * Delete test data safely
     * 
     * @param string|null $batchId Delete only specific batch (null = all test data)
     * @param string|null $olderThan Delete only test data older than this date (Y-m-d format)
     * @return int Number of deleted records
     */
    public function deleteTestData(?string $batchId = null, ?string $olderThan = null): int
    {
        $where = ['is_test_data = 1'];
        $params = [];
        
        if ($batchId !== null) {
            $where[] = '(test_batch_id = ? OR import_source = ?)';
            $params[] = $batchId;
            $params[] = $batchId;
        }
        
        if ($olderThan !== null) {
            $where[] = 'DATE(imported_at) < ?';
            $params[] = $olderThan;
        }
        
        $whereClause = implode(' AND ', $where);
        
        // First, get the IDs to delete (we'll need them for cascade deletion)
        $stmt = $this->db->prepare("SELECT id FROM guarantees WHERE {$whereClause}");
        $stmt->execute($params);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($ids)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $deletedCount = 0;

        $this->db->beginTransaction();
        try {
            // Archive timeline before deletion to prevent permanent audit loss.
            HistoryArchiveService::archiveForGuarantees(
                $this->db,
                $ids,
                'delete_test_data',
                'GuaranteeRepository::deleteTestData'
            );

            // Delete from related tables first (cascade)
            // 1. Delete guarantee_history (timeline events)
            $stmt = $this->db->prepare("
                DELETE FROM guarantee_history 
                WHERE guarantee_id IN ({$placeholders})
            ");
            $stmt->execute($ids);

            // 2. Delete guarantee_decisions
            $stmt = $this->db->prepare("
                DELETE FROM guarantee_decisions 
                WHERE guarantee_id IN ({$placeholders})
            ");
            $stmt->execute($ids);

            // 3. Delete guarantee_metadata (optional table)
            if ($this->tableExists('guarantee_metadata')) {
                $stmt = $this->db->prepare("
                    DELETE FROM guarantee_metadata 
                    WHERE guarantee_id IN ({$placeholders})
                ");
                $stmt->execute($ids);
            }

            // 4. Delete learning_confirmations (optional table)
            if ($this->tableExists('learning_confirmations')) {
                $stmt = $this->db->prepare("
                    DELETE FROM learning_confirmations 
                    WHERE guarantee_id IN ({$placeholders})
                ");
                $stmt->execute($ids);
            }

            // 5. Delete guarantee_occurrences (optional table)
            if ($this->tableExists('guarantee_occurrences')) {
                $stmt = $this->db->prepare("
                    DELETE FROM guarantee_occurrences 
                    WHERE guarantee_id IN ({$placeholders})
                ");
                $stmt->execute($ids);
            }

            // 6. Finally, delete the guarantees themselves
            $stmt = $this->db->prepare("DELETE FROM guarantees WHERE {$whereClause}");
            $stmt->execute($params);
            $deletedCount = $stmt->rowCount();

            // 7. ORPHAN CLEANUP: Remove batch metadata if no guarantees remain for that batch
            // This ensures the Batches list is clean after deleting test data.
            $this->db->exec("
                DELETE FROM batch_metadata 
                WHERE import_source NOT IN (SELECT DISTINCT import_source FROM guarantees)
            ");

            $this->db->commit();
            return $deletedCount;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
    
    /**
     * Get test data statistics
     * 
     * @return array Statistics array
     */
    public function getTestDataStats(): array
    {
        $stmt = $this->db->query('
            SELECT 
                COUNT(*) as total_test_guarantees,
                (
                    SELECT COUNT(*) FROM (
                        SELECT o.batch_identifier
                        FROM guarantee_occurrences o
                        JOIN guarantees g2 ON g2.id = o.guarantee_id
                        GROUP BY o.batch_identifier
                        HAVING COUNT(DISTINCT CASE WHEN COALESCE(g2.is_test_data, 0) = 1 THEN o.guarantee_id END) > 0
                    ) test_batches
                ) as unique_batches,
                COUNT(*) FILTER (
                    WHERE NOT EXISTS (
                        SELECT 1
                        FROM guarantee_occurrences o2
                        WHERE o2.guarantee_id = guarantees.id
                    )
                ) as orphan_test_guarantees,
                MIN(imported_at) as oldest_test_data,
                MAX(imported_at) as newest_test_data
            FROM guarantees
            WHERE COALESCE(is_test_data, 0) = 1
        ');
        
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [
            'total_test_guarantees' => 0,
            'unique_batches' => 0,
            'orphan_test_guarantees' => 0,
            'oldest_test_data' => null,
            'newest_test_data' => null
        ];
    }

    /**
     * Get system-wide operational counts using the same workflow semantics as index navigation:
     * - open_total: not released
     * - ready_total: open + ready
     * - pending_total: open + pending or no decision
     * - released_total: locked/released
     * - absolute_total: all guarantees
     *
     * @return array<string,int>
     */
    public function getOperationalStats(): array
    {
        $stmt = $this->db->query('
            SELECT
                COUNT(*) AS absolute_total,
                COUNT(*) FILTER (
                    WHERE (d.is_locked IS NULL OR d.is_locked = FALSE)
                ) AS open_total,
                COUNT(*) FILTER (
                    WHERE (d.is_locked IS NULL OR d.is_locked = FALSE) AND d.status = \'ready\'
                ) AS ready_total,
                COUNT(*) FILTER (
                    WHERE (d.is_locked IS NULL OR d.is_locked = FALSE)
                      AND (d.id IS NULL OR d.status = \'pending\')
                ) AS pending_total,
                COUNT(*) FILTER (
                    WHERE d.is_locked = TRUE
                ) AS released_total
            FROM guarantees g
            LEFT JOIN guarantee_decisions d ON d.guarantee_id = g.id
        ');

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return [
                'absolute_total' => 0,
                'open_total' => 0,
                'ready_total' => 0,
                'pending_total' => 0,
                'released_total' => 0,
            ];
        }

        return [
            'absolute_total' => (int)($row['absolute_total'] ?? 0),
            'open_total' => (int)($row['open_total'] ?? 0),
            'ready_total' => (int)($row['ready_total'] ?? 0),
            'pending_total' => (int)($row['pending_total'] ?? 0),
            'released_total' => (int)($row['released_total'] ?? 0),
        ];
    }

    private function tableExists(string $tableName): bool
    {
        $stmt = $this->db->prepare("
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = 'public'
              AND table_name = ?
            LIMIT 1
        ");
        $stmt->execute([$tableName]);
        return (bool)$stmt->fetchColumn();
    }
}
