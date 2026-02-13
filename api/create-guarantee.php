<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Support/autoload.php';
require_once __DIR__ . '/../app/Services/TimelineRecorder.php';

use App\Repositories\GuaranteeRepository;
use App\Repositories\GuaranteeDecisionRepository;
use App\Repositories\SupplierRepository;
use App\Repositories\BatchMetadataRepository;
use App\Support\Database;
use App\Support\Input;
use App\Support\Settings;
use App\Models\Guarantee;
use App\Models\GuaranteeDecision;

header('Content-Type: application/json; charset=utf-8');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }

    // Production Mode: Block test data creation
    $settings = Settings::getInstance();
    if (!empty($input['is_test_data']) && $settings->isProductionMode()) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'لا يمكن إنشاء بيانات اختبار في وضع الإنتاج'
        ]);
        exit;
    }

    // Validate required fields
    $required = ['guarantee_number', 'supplier', 'bank', 'amount', 'contract_number', 'expiry_date'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            throw new \RuntimeException("الحقل مطلوب: $field");
        }
    }

    $db = Database::connect();
    $repo = new GuaranteeRepository($db);
    $decisionRepo = new GuaranteeDecisionRepository($db);
    $supplierRepo = new SupplierRepository();

    $guaranteeNumber = Input::string($input, 'guarantee_number', '');
    $supplier = Input::string($input, 'supplier', '');
    $bank = Input::string($input, 'bank', '');
    $amount = Input::string($input, 'amount', '');
    $contractNumber = Input::string($input, 'contract_number', '');
    $expiryDate = Input::string($input, 'expiry_date', '');
    $issueDate = Input::string($input, 'issue_date', '');
    $type = Input::string($input, 'type', 'Initial');
    $comment = Input::string($input, 'comment', '');
    $relatedTo = Input::string($input, 'related_to', 'contract');

    // 1. Prepare Raw Data
    $rawData = [
        'bg_number' => $guaranteeNumber,
        'supplier' => $supplier,
        'bank' => $bank,
        'amount' => $amount,
        'contract_number' => $contractNumber,
        'expiry_date' => $expiryDate ?: null,
        'issue_date' => $issueDate ?: null,
        'type' => $type,
        'currency' => 'SAR',
        'details' => $comment,
        'source' => 'manual_entry',
        'related_to' => $relatedTo, // 🔥 NEW
    ];

    // 2. Create Guarantee Record
    // Check duplication first
    if ($repo->findByNumber($guaranteeNumber)) {
        throw new \RuntimeException("رقم الضمان موجود بالفعل: " . $guaranteeNumber);
    }

    // Create Model Instance
    // ✅ BATCH LOGIC: Daily Separated Batches (Real vs Test)
    $isTestData = !empty($input['is_test_data']);
    $batchPrefix = $isTestData ? 'test_paste_' : 'manual_paste_';
    $batchId = $batchPrefix . date('Ymd');

    $guaranteeModel = new Guarantee(
        id: null,
        guaranteeNumber: $guaranteeNumber,
        rawData: $rawData,
        importSource: $batchId,
        importedAt: date('Y-m-d H:i:s'),
        importedBy: 'Web User'
    );

    // ✅ ARABIC NAME LOGIC
    $arabicName = $isTestData 
        ? 'دفعة اختبار: إدخال/لصق (' . date('Y/m/d') . ')' 
        : 'دفعة إدخال يدوي/ذكي (' . date('Y/m/d') . ')';

    // Ensure metadata exists
    $metaRepo = new BatchMetadataRepository($repo->getDb());
    $metaRepo->ensureBatchName($batchId, $arabicName);

    $savedGuarantee = $repo->create($guaranteeModel);
    $guaranteeId = $savedGuarantee->id;
    
    // ✅ NEW: Handle test data marking (Phase 1)
    if (!empty($input['is_test_data'])) {
        $repo->markAsTestData(
            $guaranteeId,
            $input['test_batch_id'] ?? null,
            $input['test_note'] ?? null
        );
    }
    
    // Record History Event (SmartProcessingService will handle all matching & decision creation!)
    $snapshot = \App\Services\TimelineRecorder::createSnapshot($guaranteeId);
    // ✅ ARCHITECTURAL ENFORCEMENT: Use $savedGuarantee->rawData (Post-Persist State)
    \App\Services\TimelineRecorder::recordImportEvent($guaranteeId, 'manual', $savedGuarantee->rawData);

    // ✨ AUTO-MATCHING: Apply Smart Processing
    try {
        $processor = new \App\Services\SmartProcessingService('manual', 'web_user');
        $autoMatchStats = $processor->processNewGuarantees(1);
        
        if ($autoMatchStats['auto_matched'] > 0) {
            error_log("✅ Manual entry auto-matched: Guarantee #$guaranteeId");
        }
    } catch (\Throwable $e) {
        error_log("Auto-matching failed (non-critical): " . $e->getMessage());
    }

    echo json_encode(['success' => true, 'id' => $guaranteeId, 'message' => 'تم إنشاء الضمان بنجاح']);

} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
