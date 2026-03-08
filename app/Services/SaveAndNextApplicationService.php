<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Guarantee;
use App\Repositories\GuaranteeRepository;
use App\Repositories\LearningRepository;
use App\Services\Learning\AuthorityFactory;
use App\Support\BankNormalizer;
use App\Support\Logger;
use App\Support\TransactionBoundary;
use PDO;
use RuntimeException;

/**
 * Application-level orchestration for save-and-next response shaping.
 * Keeps API endpoint thin by centralizing next-record payload composition.
 */
class SaveAndNextApplicationService
{
    /**
     * Execute full save-and-next flow and return compat-ready payload/failure.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $policyContext
     * @param array<string,mixed> $surface
     * @return array<string,mixed>
     */
    public static function executeSaveAndNext(
        PDO $db,
        int $guaranteeId,
        ?int $supplierId,
        string $supplierName,
        string $decidedBy,
        string $statusFilter,
        bool $includeTestData,
        array $input,
        array $policyContext,
        array $surface
    ): array {
        $eligibility = self::ensureMutationAllowed(
            $db,
            $guaranteeId,
            $input,
            $decidedBy,
            $policyContext,
            $surface
        );
        if (!($eligibility['ok'] ?? false)) {
            return $eligibility;
        }

        $currentGuarantee = $eligibility['current_guarantee'] ?? null;
        if (!($currentGuarantee instanceof Guarantee)) {
            return self::buildFailure(500, 'invalid_guarantee_context', [
                'message' => 'Failed to resolve guarantee context.',
                'reason_code' => 'INVALID_GUARANTEE_CONTEXT',
                'policy' => $policyContext,
                'surface' => $surface,
                'reasons' => $policyContext['reasons'] ?? [],
            ], 'internal');
        }

        return TransactionBoundary::run($db, static function () use (
            $db,
            $currentGuarantee,
            $guaranteeId,
            $supplierId,
            $supplierName,
            $decidedBy,
            $statusFilter,
            $includeTestData,
            $policyContext,
            $surface
        ): array {
            $resolution = self::resolveDecisionInputs(
                $db,
                $currentGuarantee,
                $guaranteeId,
                $supplierId,
                $supplierName,
                $decidedBy,
                $policyContext,
                $surface
            );
            if (!($resolution['ok'] ?? false)) {
                return $resolution;
            }

            $resolvedSupplierId = (int)($resolution['supplier_id'] ?? 0);
            $resolvedBankId = (int)($resolution['bank_id'] ?? 0);
            $decisionSource = (string)($resolution['decision_source'] ?? 'manual');
            $wasAiMatch = (bool)($resolution['was_ai_match'] ?? false);
            $autoCreatedSupplierName = $resolution['auto_created_supplier_name'] ?? null;
            $now = (string)($resolution['now'] ?? date('Y-m-d H:i:s'));
            $changes = is_array($resolution['changes'] ?? null) ? $resolution['changes'] : [];
            $newSupplier = (string)($resolution['new_supplier'] ?? '');

            self::persistDecisionAndRecord(
                $db,
                $currentGuarantee,
                $guaranteeId,
                $resolvedSupplierId,
                $resolvedBankId,
                $decisionSource,
                $decidedBy,
                $now,
                $changes,
                $newSupplier,
                $wasAiMatch
            );

            $meta = [];
            if ($autoCreatedSupplierName) {
                $meta['created_supplier_name'] = $autoCreatedSupplierName;
            }

            return [
                'ok' => true,
                'payload' => self::buildPostSaveResponse(
                    $db,
                    $guaranteeId,
                    $statusFilter,
                    $includeTestData,
                    $policyContext,
                    $surface,
                    $meta
                ),
            ];
        });
    }

    public static function resolveCurrentWorkflowStep(PDO $db, int $guaranteeId): string
    {
        $stepStmt = $db->prepare('SELECT workflow_step FROM guarantee_decisions WHERE guarantee_id = ? LIMIT 1');
        $stepStmt->execute([$guaranteeId]);
        return (string)($stepStmt->fetchColumn() ?: 'unknown');
    }

    /**
     * Validate save-and-next actionability and mutation policy before data resolution.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $policyContext
     * @param array<string,mixed> $surface
     * @return array<string,mixed>
     */
    public static function ensureMutationAllowed(
        PDO $db,
        int $guaranteeId,
        array $input,
        string $decidedBy,
        array $policyContext,
        array $surface
    ): array {
        $guaranteeRepo = new GuaranteeRepository($db);
        $currentGuarantee = $guaranteeRepo->find($guaranteeId);
        if (!$currentGuarantee) {
            return self::buildFailure(404, 'guarantee_not_found', [
                'message' => 'السجل غير موجود.',
                'policy' => $policyContext,
                'surface' => $surface,
                'reasons' => $policyContext['reasons'] ?? [],
            ], 'not_found');
        }

        $policy = GuaranteeMutationPolicyService::evaluate(
            $guaranteeId,
            $input,
            'save_and_next_decision',
            $decidedBy
        );
        if (!($policy['allowed'] ?? false)) {
            return self::buildFailure(403, 'released_read_only', [
                'message' => (string)($policy['reason'] ?? 'Operation is not allowed'),
                'required_permission' => 'guarantee_save',
                'current_step' => self::resolveCurrentWorkflowStep($db, $guaranteeId),
                'reason_code' => 'MUTATION_POLICY_DENIED',
                'policy' => $policyContext,
                'surface' => $surface,
                'reasons' => $policyContext['reasons'] ?? [],
            ]);
        }

        return [
            'ok' => true,
            'current_guarantee' => $currentGuarantee,
        ];
    }

    /**
     * Resolve supplier/bank context and change tracking for save-and-next mutation.
     *
     * @param array<string,mixed> $policyContext
     * @param array<string,mixed> $surface
     * @return array<string,mixed>
     */
    public static function resolveDecisionInputs(
        PDO $db,
        Guarantee $currentGuarantee,
        int $guaranteeId,
        ?int $supplierId,
        string $supplierName,
        string $decidedBy,
        array $policyContext,
        array $surface
    ): array {
        $decisionSource = null;
        $wasAiMatch = false;
        $autoCreatedSupplierName = null;
        $supplierError = '';

        // Safeguard: stale supplier id while user changed supplier name manually.
        if ($supplierId && $supplierName !== '') {
            $chkStmt = $db->prepare('SELECT official_name FROM suppliers WHERE id = ?');
            $chkStmt->execute([$supplierId]);
            $dbName = $chkStmt->fetchColumn();

            if ($dbName && mb_strtolower(trim((string)$dbName)) !== mb_strtolower(trim($supplierName))) {
                $supplierId = null;
            } elseif ($dbName) {
                $decisionSource = 'ai_match';
                $wasAiMatch = true;
            }
        }

        if (!$supplierId && $supplierName !== '') {
            $normStub = mb_strtolower($supplierName);

            $stmt = $db->prepare('SELECT id FROM suppliers WHERE official_name = ?');
            $stmt->execute([$supplierName]);
            $supplierId = (int)($stmt->fetchColumn() ?: 0);

            if (!$supplierId) {
                $stmt = $db->prepare('SELECT id FROM suppliers WHERE normalized_name = ?');
                $stmt->execute([$normStub]);
                $supplierId = (int)($stmt->fetchColumn() ?: 0);
            }

            if (!$supplierId) {
                try {
                    $originalImportedName = (string)($currentGuarantee->rawData['supplier'] ?? '');
                    $englishNameCandidate = null;

                    if (preg_match('/\p{Arabic}/u', $supplierName)) {
                        if ($originalImportedName !== '' && !preg_match('/\p{Arabic}/u', $originalImportedName)) {
                            $englishNameCandidate = $originalImportedName;
                        }
                    } else {
                        $englishNameCandidate = $supplierName;
                    }

                    $createResult = SupplierManagementService::create($db, [
                        'official_name' => $supplierName,
                        'english_name' => $englishNameCandidate,
                    ]);

                    $supplierId = (int)($createResult['supplier_id'] ?? 0);
                    $decisionSource = 'auto_create_on_save';
                    $autoCreatedSupplierName = (string)($createResult['official_name'] ?? $supplierName);
                } catch (\Exception $e) {
                    return self::buildFailure(400, 'creation_failed', [
                        'message' => 'فشل إنشاء المورد تلقائياً: ' . $e->getMessage(),
                        'supplier_name' => $supplierName,
                        'required_permission' => 'guarantee_save',
                        'current_step' => self::resolveCurrentWorkflowStep($db, $guaranteeId),
                        'reason_code' => 'AUTO_SUPPLIER_CREATE_FAILED',
                        'policy' => $policyContext,
                        'surface' => $surface,
                        'reasons' => $policyContext['reasons'] ?? [],
                    ]);
                }
            }
        } elseif ($supplierId && !$wasAiMatch) {
            $decisionSource = 'manual';
        }

        if (!$decisionSource) {
            $decisionSource = 'manual';
        }

        if (!$guaranteeId || !$supplierId) {
            $missing = [];
            if (!$guaranteeId) {
                $missing[] = 'Guarantee ID';
            }
            if (!$supplierId) {
                $missing[] = "Supplier (Unknown" . ($supplierError ? ": $supplierError" : '') . ")";
            }

            return self::buildFailure(400, 'Missing required fields', [
                'message' => 'حقول مطلوبة مفقودة: ' . implode(', ', $missing),
                'missing_fields' => $missing,
                'required_permission' => 'guarantee_save',
                'current_step' => self::resolveCurrentWorkflowStep($db, $guaranteeId),
                'reason_code' => 'MISSING_REQUIRED_FIELDS',
                'policy' => $policyContext,
                'surface' => $surface,
                'reasons' => $policyContext['reasons'] ?? [],
            ]);
        }

        $now = date('Y-m-d H:i:s');
        $changes = [];

        $lastDecStmt = $db->prepare('SELECT supplier_id FROM guarantee_decisions WHERE guarantee_id = ? LIMIT 1');
        $lastDecStmt->execute([$guaranteeId]);
        $prevDecision = $lastDecStmt->fetch(PDO::FETCH_ASSOC);

        if ($prevDecision && !empty($prevDecision['supplier_id'])) {
            $stmt = $db->prepare('SELECT official_name FROM suppliers WHERE id = ?');
            $stmt->execute([$prevDecision['supplier_id']]);
            $oldSupplier = (string)($stmt->fetchColumn() ?: '');
        } else {
            $oldSupplier = (string)($currentGuarantee->rawData['supplier'] ?? '');
        }

        $bankStmt = $db->prepare('SELECT bank_id FROM guarantee_decisions WHERE guarantee_id = ?');
        $bankStmt->execute([$guaranteeId]);
        $bankIdRaw = $bankStmt->fetchColumn();
        $bankId = ($bankIdRaw && (int)$bankIdRaw > 0) ? (int)$bankIdRaw : null;

        if (!$bankId) {
            $rawBank = (string)($currentGuarantee->rawData['bank'] ?? '');
            $guaranteeLabel = (string)($currentGuarantee->guaranteeNumber ?? '');
            $resolvedBankId = self::resolveBankId($db, [$rawBank, $guaranteeLabel]);

            if ($resolvedBankId) {
                $upsertDecisionStmt = $db->prepare('SELECT id FROM guarantee_decisions WHERE guarantee_id = ? LIMIT 1');
                $upsertDecisionStmt->execute([$guaranteeId]);
                $decisionId = $upsertDecisionStmt->fetchColumn();

                if ($decisionId) {
                    $upd = $db->prepare(
                        'UPDATE guarantee_decisions SET bank_id = ?, last_modified_at = CURRENT_TIMESTAMP, last_modified_by = ? WHERE guarantee_id = ?'
                    );
                    $upd->execute([$resolvedBankId, $decidedBy, $guaranteeId]);
                } else {
                    $ins = $db->prepare(
                        'INSERT INTO guarantee_decisions (guarantee_id, supplier_id, bank_id, status, decided_at, decision_source, decided_by, created_at)
                         VALUES (?, NULL, ?, ?, ?, ?, ?, ?)'
                    );
                    $nowTmp = date('Y-m-d H:i:s');
                    $ins->execute([$guaranteeId, $resolvedBankId, 'pending', $nowTmp, 'auto_bank_resolve', $decidedBy, $nowTmp]);
                }

                $bankId = (int)$resolvedBankId;
                $matchedBankNameStmt = $db->prepare('SELECT arabic_name FROM banks WHERE id = ? LIMIT 1');
                $matchedBankNameStmt->execute([$bankId]);
                $matchedBankName = (string)($matchedBankNameStmt->fetchColumn() ?: $rawBank);
                self::updateRawBankName($db, $guaranteeId, $matchedBankName);
                self::ensureBankMatchTimeline($db, $guaranteeId, $currentGuarantee->rawData, $rawBank, $matchedBankName);
            }
        }

        if (!$bankId) {
            return self::buildFailure(400, 'bank_required', [
                'message' => 'لم يتم تحديد البنك - يجب مطابقة البنك تلقائياً أولاً. يرجى التأكد من وجود البنك في قاعدة البيانات.',
                'details' => 'Bank ID is missing from guarantee_decisions',
                'required_permission' => 'guarantee_save',
                'current_step' => self::resolveCurrentWorkflowStep($db, $guaranteeId),
                'reason_code' => 'BANK_REQUIRED_FOR_DECISION',
                'policy' => $policyContext,
                'surface' => $surface,
                'reasons' => $policyContext['reasons'] ?? [],
            ]);
        }

        if ($bankId) {
            $stmt = $db->prepare('SELECT arabic_name FROM banks WHERE id = ?');
            $stmt->execute([$bankId]);
            $oldBank = (string)($stmt->fetchColumn() ?: '');
        } else {
            $oldBank = (string)($currentGuarantee->rawData['bank'] ?? '');
        }

        $supStmt = $db->prepare('SELECT official_name FROM suppliers WHERE id = ?');
        $supStmt->execute([$supplierId]);
        $newSupplier = (string)($supStmt->fetchColumn() ?: '');

        if (trim($oldSupplier) !== trim($newSupplier)) {
            $changes[] = "تغيير المورد من [{$oldSupplier}] إلى [{$newSupplier}]";
        }

        $bankStmt = $db->prepare('SELECT bank_id FROM guarantee_decisions WHERE guarantee_id = ?');
        $bankStmt->execute([$guaranteeId]);
        $currentBankId = $bankStmt->fetchColumn() ?: null;

        if ($currentBankId) {
            $bnkStmt = $db->prepare('SELECT arabic_name FROM banks WHERE id = ?');
            $bnkStmt->execute([$currentBankId]);
            $newBank = (string)($bnkStmt->fetchColumn() ?: '');

            if (trim($oldBank) !== trim($newBank)) {
                $changes[] = "تغيير البنك من [{$oldBank}] إلى [{$newBank}]";
            }
        }

        return [
            'ok' => true,
            'guarantee_id' => $guaranteeId,
            'supplier_id' => (int)$supplierId,
            'bank_id' => (int)$bankId,
            'decision_source' => (string)$decisionSource,
            'was_ai_match' => (bool)$wasAiMatch,
            'auto_created_supplier_name' => $autoCreatedSupplierName,
            'now' => $now,
            'changes' => $changes,
            'new_supplier' => $newSupplier,
        ];
    }

    /**
     * Persist supplier decision mutation and record timeline/learning side effects.
     *
     * @param array<int,string> $changes
     */
    public static function persistDecisionAndRecord(
        PDO $db,
        Guarantee $currentGuarantee,
        int $guaranteeId,
        int $supplierId,
        int $bankId,
        string $decisionSource,
        string $decidedBy,
        string $now,
        array $changes,
        string $newSupplier,
        bool $wasAiMatch
    ): void {
        $oldSnapshot = TimelineRecorder::createSnapshot($guaranteeId);
        $statusToSave = StatusEvaluator::evaluate($supplierId, $bankId);

        $chkDec = $db->prepare('SELECT id FROM guarantee_decisions WHERE guarantee_id = ?');
        $chkDec->execute([$guaranteeId]);
        $existingId = $chkDec->fetchColumn();

        if ($existingId) {
            $stmt = $db->prepare(
                'UPDATE guarantee_decisions
                 SET supplier_id = ?, status = ?, decided_at = ?, decision_source = ?, decided_by = ?, last_modified_by = ?, last_modified_at = CURRENT_TIMESTAMP
                 WHERE guarantee_id = ?'
            );
            $stmt->execute([
                $supplierId,
                $statusToSave,
                $now,
                $decisionSource,
                $decidedBy,
                $decidedBy,
                $guaranteeId,
            ]);
        } else {
            $supplierIdSafe = ($supplierId > 0) ? $supplierId : null;
            $bankIdSafe = ($bankId > 0) ? $bankId : null;

            $stmt = $db->prepare(
                'INSERT INTO guarantee_decisions (guarantee_id, supplier_id, bank_id, status, decided_at, decision_source, decided_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $guaranteeId,
                $supplierIdSafe,
                $bankIdSafe,
                $statusToSave,
                $now,
                $decisionSource,
                $decidedBy,
                $now,
            ]);
        }

        Logger::info('save_and_next_decision', [
            'guarantee_id' => $guaranteeId,
            'supplier_id' => $supplierId,
            'bank_id' => $bankId,
            'has_bank' => (bool)$bankId,
            'status' => $statusToSave,
            'decision_source' => $decisionSource,
            'decided_by' => $decidedBy,
        ]);

        if (!empty($changes)) {
            $statusStmt = $db->prepare('SELECT status FROM guarantee_decisions WHERE guarantee_id = ?');
            $statusStmt->execute([$guaranteeId]);
            $currentStatus = $statusStmt->fetchColumn();

            if ($currentStatus === 'ready') {
                $clearActiveStmt = $db->prepare(
                    'UPDATE guarantee_decisions
                     SET active_action = NULL, active_action_set_at = NULL
                     WHERE guarantee_id = ?'
                );
                $clearActiveStmt->execute([$guaranteeId]);
                error_log("ADR-007: Cleared active_action for guarantee {$guaranteeId} due to data changes");
            }
        }

        $newData = [
            'supplier_id' => $supplierId,
            'supplier_name' => $newSupplier,
        ];

        $wasReadyStmt = $db->prepare(
            "SELECT 1 FROM guarantee_history WHERE guarantee_id = ? AND event_subtype = 'reopened' ORDER BY id DESC LIMIT 1"
        );
        $wasReadyStmt->execute([$guaranteeId]);
        $isCorrection = (bool)$wasReadyStmt->fetchColumn();
        $decisionSubtype = $isCorrection ? 'correction' : ($wasAiMatch ? 'ai_match' : 'manual_edit');

        TimelineRecorder::recordDecisionEvent($guaranteeId, $oldSnapshot, $newData, false, null, $decisionSubtype);
        TimelineRecorder::recordStatusTransitionEvent($guaranteeId, $oldSnapshot, $statusToSave, 'data_completeness_check');

        try {
            $rawSupplierName = (string)($currentGuarantee->rawData['supplier'] ?? '');
            if ($rawSupplierName === '' || $supplierId <= 0) {
                return;
            }

            $learningRepo = new LearningRepository($db);

            try {
                $learningRepo->logDecision([
                    'guarantee_id' => $guaranteeId,
                    'raw_supplier_name' => $rawSupplierName,
                    'supplier_id' => $supplierId,
                    'action' => $isCorrection ? 'correction' : 'confirm',
                    'confidence' => 100,
                    'decision_time_seconds' => 0,
                ]);
            } catch (\Exception $e) {
                error_log('[LEARNING ERROR] Failed to log decision: ' . $e->getMessage());
            }

            $authority = AuthorityFactory::create();
            $suggestions = $authority->getSuggestions($rawSupplierName);
            if (empty($suggestions)) {
                return;
            }

            $topSuggestion = $suggestions[0];
            if ($topSuggestion->supplier_id == $supplierId) {
                return;
            }

            $learningRepo->logDecision([
                'guarantee_id' => $guaranteeId,
                'raw_supplier_name' => $rawSupplierName,
                'supplier_id' => $topSuggestion->supplier_id,
                'action' => 'reject',
                'confidence' => $topSuggestion->confidence,
                'matched_anchor' => $topSuggestion->official_name,
                'decision_time_seconds' => 0,
            ]);
        } catch (\Throwable $e) {
            error_log('Learning log error: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string,mixed> $policyContext
     * @param array<string,mixed> $surface
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     */
    public static function buildFinishedResponse(
        array $policyContext,
        array $surface,
        array $meta = [],
        ?string $nextFilter = null,
        int $nextFilterCount = 0,
        bool $returnToHome = false
    ): array {
        $response = [
            'finished' => true,
            'message' => 'تم الانتهاء من جميع السجلات',
            'policy' => $policyContext,
            'surface' => $surface,
            'reasons' => $policyContext['reasons'] ?? [],
        ];

        if (!empty($meta)) {
            $response['meta'] = $meta;
        }
        if (is_string($nextFilter) && trim($nextFilter) !== '') {
            $response['next_filter'] = $nextFilter;
            $response['next_filter_count'] = max(0, $nextFilterCount);
        }
        if ($returnToHome) {
            $response['return_to_home'] = true;
        }

        return $response;
    }

    /**
     * @param array<string,mixed> $policyContext
     * @param array<string,mixed> $surface
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     */
    public static function buildNextRecordResponse(
        PDO $db,
        int $nextGuaranteeId,
        string $statusFilter,
        bool $includeTestData,
        array $policyContext,
        array $surface,
        array $meta = [],
        ?string $nextFilter = null
    ): array {
        $guaranteeRepo = new GuaranteeRepository($db);
        $guarantee = $guaranteeRepo->find($nextGuaranteeId);
        if (!$guarantee) {
            throw new RuntimeException('Next record not found');
        }

        $raw = $guarantee->rawData;
        $record = [
            'id' => $guarantee->id,
            'guarantee_number' => $guarantee->guaranteeNumber,
            'supplier_name' => $raw['supplier'] ?? '',
            'bank_name' => $raw['bank'] ?? '',
            'bank_id' => null,
            'amount' => $raw['amount'] ?? 0,
            'expiry_date' => $raw['expiry_date'] ?? '',
            'issue_date' => $raw['issue_date'] ?? '',
            'contract_number' => $raw['contract_number'] ?? '',
            'type' => $raw['type'] ?? 'Initial',
            'status' => 'pending',
        ];

        $stmtDec = $db->prepare('SELECT status, supplier_id, bank_id FROM guarantee_decisions WHERE guarantee_id = ? LIMIT 1');
        $stmtDec->execute([$nextGuaranteeId]);
        $lastDecision = $stmtDec->fetch(PDO::FETCH_ASSOC);
        if ($lastDecision) {
            $record['status'] = $lastDecision['status'];
            $record['bank_id'] = $lastDecision['bank_id'];
        }

        $banksStmt = $db->query('SELECT id, arabic_name as official_name FROM banks ORDER BY arabic_name');
        $banks = $banksStmt ? $banksStmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $nextNavInfo = NavigationService::getNavigationInfo(
            $db,
            $nextGuaranteeId,
            $statusFilter,
            null,
            null,
            $includeTestData
        );

        $response = [
            'finished' => false,
            'record' => $record,
            'banks' => $banks,
            'currentIndex' => $nextNavInfo['currentIndex'],
            'totalRecords' => $nextNavInfo['totalRecords'],
            'policy' => $policyContext,
            'surface' => $surface,
            'reasons' => $policyContext['reasons'] ?? [],
        ];

        if (!empty($meta)) {
            $response['meta'] = $meta;
        }
        $response['next_filter'] = is_string($nextFilter) && trim($nextFilter) !== ''
            ? $nextFilter
            : $statusFilter;

        return $response;
    }

    /**
     * Build the final API payload after current record save (finished or next-record payload).
     *
     * @param array<string,mixed> $policyContext
     * @param array<string,mixed> $surface
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     */
    public static function buildPostSaveResponse(
        PDO $db,
        int $currentGuaranteeId,
        string $statusFilter,
        bool $includeTestData,
        array $policyContext,
        array $surface,
        array $meta = []
    ): array {
        $normalizedFilter = strtolower(trim($statusFilter));
        $batchIdentifier = self::resolveLatestBatchIdentifier($db, $currentGuaranteeId);

        // Batch-focused flow for data-entry completion:
        // pending (same batch) -> data_entry (same batch) -> back to home.
        if ($normalizedFilter === 'pending' && $batchIdentifier !== null && $batchIdentifier !== '') {
            $nextPendingInBatch = self::findNextGuaranteeIdInBatchByFilter(
                $db,
                $batchIdentifier,
                'pending',
                $includeTestData,
                $currentGuaranteeId
            );
            if ($nextPendingInBatch !== null) {
                return self::buildNextRecordResponse(
                    $db,
                    $nextPendingInBatch,
                    'pending',
                    $includeTestData,
                    $policyContext,
                    $surface,
                    ['batch_identifier' => $batchIdentifier],
                    'pending'
                );
            }

            $nextActionInBatch = self::findFirstGuaranteeIdInBatchByFilter(
                $db,
                $batchIdentifier,
                'data_entry',
                $includeTestData
            );
            if ($nextActionInBatch !== null) {
                return self::buildNextRecordResponse(
                    $db,
                    $nextActionInBatch,
                    'data_entry',
                    $includeTestData,
                    $policyContext,
                    $surface,
                    ['batch_identifier' => $batchIdentifier],
                    'data_entry'
                );
            }

            return self::buildFinishedResponse(
                $policyContext,
                $surface,
                ['batch_identifier' => $batchIdentifier],
                null,
                0,
                true
            );
        }

        $navInfo = NavigationService::getNavigationInfo($db, $currentGuaranteeId, $statusFilter, null, null, $includeTestData);
        $nextGuaranteeId = (int)($navInfo['nextId'] ?? 0);

        if ($nextGuaranteeId <= 0) {
            [$nextFilter, $nextFilterCount] = self::suggestFallbackFilter(
                $db,
                $statusFilter,
                $includeTestData
            );
            return self::buildFinishedResponse(
                $policyContext,
                $surface,
                $meta,
                $nextFilter,
                $nextFilterCount
            );
        }

        return self::buildNextRecordResponse(
            $db,
            $nextGuaranteeId,
            $statusFilter,
            $includeTestData,
            $policyContext,
            $surface,
            $meta,
            $statusFilter
        );
    }

    /**
     * @param array<int,string> $candidates
     */
    private static function resolveBankId(PDO $db, array $candidates): ?int
    {
        $stmtByAlt = $db->prepare(
            "SELECT DISTINCT b.id
             FROM banks b
             LEFT JOIN bank_alternative_names a ON b.id = a.bank_id
             WHERE a.normalized_name = ? OR LOWER(b.short_name) = LOWER(?)
             LIMIT 1"
        );

        $stmtByArabic = $db->query('SELECT id, arabic_name FROM banks');
        $banks = $stmtByArabic ? $stmtByArabic->fetchAll(PDO::FETCH_ASSOC) : [];

        foreach ($candidates as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate === '') {
                continue;
            }

            $normalized = BankNormalizer::normalize($candidate);
            if ($normalized === '') {
                continue;
            }

            $stmtByAlt->execute([$normalized, $candidate]);
            $matched = $stmtByAlt->fetchColumn();
            if ($matched) {
                return (int)$matched;
            }

            foreach ($banks as $bank) {
                $bankArabic = (string)($bank['arabic_name'] ?? '');
                if ($bankArabic !== '' && BankNormalizer::normalize($bankArabic) === $normalized) {
                    return (int)$bank['id'];
                }
            }
        }

        return null;
    }

    /**
     * Suggest next non-empty filter when current filter is exhausted.
     *
     * @return array{0:?string,1:int}
     */
    private static function suggestFallbackFilter(PDO $db, string $currentFilter, bool $includeTestData): array
    {
        $current = strtolower(trim($currentFilter));
        $priority = match ($current) {
            // Data entry flow: after finishing pending matching, move to action-selection bucket.
            'pending' => ['data_entry', 'actionable', 'ready', 'all'],
            'data_entry' => ['actionable', 'ready', 'all', 'pending'],
            'actionable' => ['data_entry', 'ready', 'all', 'pending'],
            default => ['data_entry', 'actionable', 'ready', 'pending', 'all'],
        };

        foreach ($priority as $filter) {
            $count = NavigationService::countByFilter(
                $db,
                $filter,
                null,
                null,
                $includeTestData
            );
            if ($count > 0) {
                return [$filter, (int)$count];
            }
        }

        return [null, 0];
    }

    private static function resolveLatestBatchIdentifier(PDO $db, int $guaranteeId): ?string
    {
        if ($guaranteeId <= 0) {
            return null;
        }

        $stmt = $db->prepare(
            'SELECT batch_identifier
             FROM guarantee_occurrences
             WHERE guarantee_id = ?
             ORDER BY occurred_at DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute([$guaranteeId]);
        $batchIdentifier = $stmt->fetchColumn();
        if (!is_string($batchIdentifier)) {
            return null;
        }
        $batchIdentifier = trim($batchIdentifier);
        return $batchIdentifier !== '' ? $batchIdentifier : null;
    }

    private static function findNextGuaranteeIdInBatchByFilter(
        PDO $db,
        string $batchIdentifier,
        string $statusFilter,
        bool $includeTestData,
        int $currentGuaranteeId
    ): ?int {
        if ($batchIdentifier === '' || $currentGuaranteeId <= 0) {
            return null;
        }

        $filter = NavigationService::buildFilterConditions($statusFilter, null, null, $includeTestData);
        $sql = '
            SELECT g.id
            FROM guarantees g
            INNER JOIN guarantee_occurrences o ON o.guarantee_id = g.id
            LEFT JOIN guarantee_decisions d ON d.guarantee_id = g.id
            LEFT JOIN suppliers s ON d.supplier_id = s.id
            WHERE o.batch_identifier = :batch_identifier
              AND g.id > :current_id
              ' . $filter['sql'] . '
            GROUP BY g.id
            ORDER BY g.id ASC
            LIMIT 1
        ';
        $stmt = $db->prepare($sql);
        $params = array_merge([
            'batch_identifier' => $batchIdentifier,
            'current_id' => $currentGuaranteeId,
        ], $filter['params']);
        $stmt->execute($params);
        $nextId = $stmt->fetchColumn();
        if ($nextId === false || $nextId === null) {
            return null;
        }
        $resolved = (int)$nextId;
        return $resolved > 0 ? $resolved : null;
    }

    private static function findFirstGuaranteeIdInBatchByFilter(
        PDO $db,
        string $batchIdentifier,
        string $statusFilter,
        bool $includeTestData
    ): ?int {
        if ($batchIdentifier === '') {
            return null;
        }

        $filter = NavigationService::buildFilterConditions($statusFilter, null, null, $includeTestData);
        $sql = '
            SELECT g.id
            FROM guarantees g
            INNER JOIN guarantee_occurrences o ON o.guarantee_id = g.id
            LEFT JOIN guarantee_decisions d ON d.guarantee_id = g.id
            LEFT JOIN suppliers s ON d.supplier_id = s.id
            WHERE o.batch_identifier = :batch_identifier
              ' . $filter['sql'] . '
            GROUP BY g.id
            ORDER BY g.id ASC
            LIMIT 1
        ';
        $stmt = $db->prepare($sql);
        $params = array_merge([
            'batch_identifier' => $batchIdentifier,
        ], $filter['params']);
        $stmt->execute($params);
        $firstId = $stmt->fetchColumn();
        if ($firstId === false || $firstId === null) {
            return null;
        }
        $resolved = (int)$firstId;
        return $resolved > 0 ? $resolved : null;
    }

    private static function updateRawBankName(PDO $db, int $guaranteeId, string $officialBankName): void
    {
        if (trim($officialBankName) === '') {
            return;
        }

        $stmt = $db->prepare('SELECT raw_data FROM guarantees WHERE id = ? LIMIT 1');
        $stmt->execute([$guaranteeId]);
        $rawData = json_decode((string)$stmt->fetchColumn(), true);
        if (!is_array($rawData)) {
            return;
        }

        if (trim((string)($rawData['bank'] ?? '')) === trim($officialBankName)) {
            return;
        }

        $rawData['bank'] = $officialBankName;
        $update = $db->prepare('UPDATE guarantees SET raw_data = ? WHERE id = ?');
        $update->execute([json_encode($rawData, JSON_UNESCAPED_UNICODE), $guaranteeId]);
    }

    /**
     * @param array<string,mixed> $rawData
     */
    private static function ensureBankMatchTimeline(
        PDO $db,
        int $guaranteeId,
        array $rawData,
        string $rawBankName,
        string $matchedBankName
    ): void {
        $hasBankMatchStmt = $db->prepare(
            "SELECT 1 FROM guarantee_history WHERE guarantee_id = ? AND event_subtype = 'bank_match' LIMIT 1"
        );
        $hasBankMatchStmt->execute([$guaranteeId]);
        if ($hasBankMatchStmt->fetchColumn()) {
            return;
        }

        $importAtStmt = $db->prepare(
            "SELECT created_at
             FROM guarantee_history
             WHERE guarantee_id = ? AND event_type = 'import'
             ORDER BY created_at ASC, id ASC
             LIMIT 1"
        );
        $importAtStmt->execute([$guaranteeId]);
        $importAt = (string)($importAtStmt->fetchColumn() ?: date('Y-m-d H:i:s'));
        $eventAt = date('Y-m-d H:i:s', strtotime($importAt . ' +1 second'));

        $beforeSnapshot = [
            'guarantee_number' => $rawData['bg_number'] ?? $rawData['guarantee_number'] ?? '',
            'contract_number' => $rawData['contract_number'] ?? $rawData['document_reference'] ?? '',
            'amount' => $rawData['amount'] ?? 0,
            'expiry_date' => $rawData['expiry_date'] ?? '',
            'issue_date' => $rawData['issue_date'] ?? '',
            'type' => $rawData['type'] ?? '',
            'supplier_id' => null,
            'supplier_name' => $rawData['supplier'] ?? '',
            'raw_supplier_name' => $rawData['supplier'] ?? '',
            'bank_id' => null,
            'bank_name' => $rawBankName,
            'raw_bank_name' => $rawBankName,
            'status' => 'pending',
        ];

        $changes = [[
            'field' => 'bank_name',
            'old_value' => $rawBankName,
            'new_value' => $matchedBankName,
            'trigger' => 'auto',
        ]];
        $eventDetails = [
            'action' => 'Bank auto-matched',
            'result' => 'Automatically matched during save',
            'event_time' => $eventAt,
        ];
        $afterSnapshot = TimelineRecorder::createSnapshot($guaranteeId);

        TimelineRecorder::recordStructuredEvent(
            $guaranteeId,
            'auto_matched',
            'bank_match',
            $beforeSnapshot,
            $changes,
            'System AI',
            $eventDetails,
            null,
            is_array($afterSnapshot) ? $afterSnapshot : null
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function buildFailure(
        int $statusCode,
        string $error,
        array $payload,
        ?string $errorType = null
    ): array {
        return [
            'ok' => false,
            'status_code' => $statusCode,
            'error' => $error,
            'payload' => $payload,
            'error_type' => $errorType,
        ];
    }
}
