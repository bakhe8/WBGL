<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../app/Services/TimelineRecorder.php';

use App\Repositories\GuaranteeRepository;
use App\Repositories\BatchMetadataRepository;
use App\Services\DuplicateImportLifecycleService;
use App\Services\ImportService;
use App\Services\GuaranteeVisibilityService;
use App\Support\Database;
use App\Support\Input;
use App\Support\Settings;
use App\Support\TestDataVisibility;
use App\Support\TypeNormalizer;
use App\Models\Guarantee;

if (!function_exists('wbgl_is_duplicate_guarantee_constraint')) {
    function wbgl_is_duplicate_guarantee_constraint(\PDOException $e): bool
    {
        $message = strtolower((string)$e->getMessage());
        $sqlState = strtoupper((string)$e->getCode());
        $errorInfoState = strtoupper((string)($e->errorInfo[0] ?? ''));

        if ($sqlState === '23505' || $errorInfoState === '23505') {
            return true;
        }

        if (str_contains($message, 'unique constraint')) {
            return true;
        }

        return str_contains($message, 'duplicate key value violates unique constraint');
    }
}

wbgl_api_require_permission('manual_entry');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }

    // Production Mode: Block test data creation
    $settings = Settings::getInstance();
    $isTestDataRequested = !empty($input['is_test_data']);
    if ($isTestDataRequested && !TestDataVisibility::canCurrentUserAccessTestData()) {
        wbgl_api_compat_fail(403, 'إنشاء بيانات الاختبار متاح للمطور فقط', [], 'permission');
    }
    if ($isTestDataRequested && $settings->isProductionMode()) {
        wbgl_api_compat_fail(403, 'لا يمكن إنشاء بيانات اختبار في وضع الإنتاج', [], 'permission');
    }

    // Validate required fields
    $required = ['guarantee_number', 'supplier', 'bank', 'amount', 'contract_number', 'expiry_date'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            throw new \RuntimeException("الحقل مطلوب: $field");
        }
    }

    $amount = (string)$input['amount'];
    if (!preg_match('/^[0-9,.]+$/', $amount)) {
        throw new \RuntimeException("قيمة المبلغ غير صالحة");
    }

    $db = Database::connect();
    $repo = new GuaranteeRepository($db);
    $actor = wbgl_api_current_user_display();

    $guaranteeNumber = Input::string($input, 'guarantee_number', '');
    $supplier = Input::string($input, 'supplier', '');
    $bank = Input::string($input, 'bank', '');
    $contractNumber = Input::string($input, 'contract_number', '');
    $expiryDate = Input::string($input, 'expiry_date', '');
    $issueDate = Input::string($input, 'issue_date', '');
    $type = TypeNormalizer::normalize(Input::string($input, 'type', 'Initial'));
    $comment = Input::string($input, 'comment', '');
    $relatedTo = Input::string($input, 'related_to', 'contract');

    // 1. Prepare Raw Data
    $cleanAmount = str_replace(',', '', $amount);
    
    $rawData = [
        'bg_number' => $guaranteeNumber,
        'supplier' => $supplier,
        'bank' => $bank,
        'amount' => $cleanAmount,
        'contract_number' => $contractNumber,
        'expiry_date' => $expiryDate ?: null,
        'issue_date' => $issueDate ?: null,
        'type' => $type,
        'currency' => 'SAR',
        'details' => $comment,
        'source' => 'manual_entry',
        'related_to' => $relatedTo, // 🔥 NEW
    ];

    // 2. Create Guarantee Record (atomically with occurrence ledger write)
    $isTestData = $isTestDataRequested && TestDataVisibility::canCurrentUserAccessTestData();
    $batchPrefix = $isTestData ? 'test_paste_' : 'manual_paste_';
    $baseBatchId = $batchPrefix . date('Ymd');
    $guaranteeId = 0;
    $savedGuarantee = null;
    $wasDuplicate = false;
    $createdNew = false;

    $db->beginTransaction();
    try {
        // Resolve a batch identifier that cannot mix test/real records.
        $batchId = ImportService::resolveCompatibleBatchIdentifier($db, $baseBatchId, $isTestData);

        // ✅ ARABIC NAME LOGIC
        $arabicName = $isTestData
            ? 'دفعة اختبار: إدخال/لصق (' . date('Y/m/d') . ')'
            : 'دفعة إدخال يدوي/ذكي (' . date('Y/m/d') . ')';

        // Ensure metadata exists
        $metaRepo = new BatchMetadataRepository($repo->getDb());
        $metaRepo->ensureBatchName($batchId, $arabicName);

        $existing = $repo->findByNumber($guaranteeNumber);
        if ($existing && (int)$existing->id > 0) {
            $guaranteeId = (int)$existing->id;
            if (!GuaranteeVisibilityService::canAccessGuarantee($guaranteeId)) {
                throw new \RuntimeException('Permission Denied');
            }

            DuplicateImportLifecycleService::handle($guaranteeId, $batchId, 'manual', $db);
            $wasDuplicate = true;
        } else {
            $guaranteeModel = new Guarantee(
                id: null,
                guaranteeNumber: $guaranteeNumber,
                rawData: $rawData,
                importSource: $batchId,
                importedAt: date('Y-m-d H:i:s'),
                importedBy: $actor
            );

            $insertSavepoint = 'sp_api_manual_create_insert';
            $savepointActive = false;

            try {
                $db->exec("SAVEPOINT {$insertSavepoint}");
                $savepointActive = true;

                $savedGuarantee = $repo->create($guaranteeModel);
                $db->exec("RELEASE SAVEPOINT {$insertSavepoint}");
                $savepointActive = false;

                $guaranteeId = (int)$savedGuarantee->id;

                // Record occurrence through the shared schema-aware contract path.
                ImportService::recordOccurrence($guaranteeId, $batchId, 'manual', null, $db);

                // ✅ NEW: Handle test data marking (Phase 1)
                if ($isTestData) {
                    $repo->markAsTestData(
                        $guaranteeId,
                        $input['test_batch_id'] ?? null,
                        $input['test_note'] ?? null
                    );
                }

                // Record History Event (SmartProcessingService will handle all matching & decision creation!)
                // ✅ ARCHITECTURAL ENFORCEMENT: Use $savedGuarantee->rawData (Post-Persist State)
                \App\Services\TimelineRecorder::recordImportEvent($guaranteeId, 'manual', $savedGuarantee->rawData);
                $createdNew = true;
            } catch (\PDOException $e) {
                if ($savepointActive) {
                    $db->exec("ROLLBACK TO SAVEPOINT {$insertSavepoint}");
                    $db->exec("RELEASE SAVEPOINT {$insertSavepoint}");
                    $savepointActive = false;
                }

                if (!wbgl_is_duplicate_guarantee_constraint($e)) {
                    throw $e;
                }

                $existing = $repo->findByNumber($guaranteeNumber);
                if (!$existing || (int)$existing->id <= 0) {
                    throw $e;
                }

                $guaranteeId = (int)$existing->id;
                if (!GuaranteeVisibilityService::canAccessGuarantee($guaranteeId)) {
                    throw new \RuntimeException('Permission Denied');
                }

                DuplicateImportLifecycleService::handle($guaranteeId, $batchId, 'manual', $db);
                $wasDuplicate = true;
            }
        }

        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    // ✨ AUTO-MATCHING: Apply Smart Processing
    if ($createdNew) {
        try {
            $processor = new \App\Services\SmartProcessingService('manual', $actor);
            $autoMatchStats = $processor->processNewGuarantees(1);
            
            if ($autoMatchStats['auto_matched'] > 0) {
                error_log("✅ Manual entry auto-matched: Guarantee #$guaranteeId");
            }
        } catch (\Throwable $e) {
            error_log("Auto-matching failed (non-critical): " . $e->getMessage());
        }
    }

    wbgl_api_compat_success([
        'id' => $guaranteeId,
        'exists_before' => $wasDuplicate,
        'message' => $wasDuplicate
            ? 'الضمان موجود مسبقًا وتم ربطه بالدفعة الحالية'
            : 'تم إنشاء الضمان بنجاح',
    ]);

} catch (\Throwable $e) {
    wbgl_api_compat_fail(400, $e->getMessage());
}
