<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Support/autoload.php';
require_once __DIR__ . '/../app/Services/TimelineRecorder.php';

use App\Repositories\GuaranteeRepository;
use App\Repositories\GuaranteeDecisionRepository;
use App\Repositories\SupplierRepository;
use App\Support\Database;
use App\Support\Input;
use App\Support\Settings;
use App\Models\Guarantee;

header('Content-Type: application/json; charset=utf-8');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new \RuntimeException("Invalid JSON input");
    }

    // Validate ID
    $guaranteeId = Input::int($input, 'guarantee_id', 0);
    if (!$guaranteeId) {
        throw new \RuntimeException("معرف الضمان مطلوب");
    }

    // Validate required fields
    $required = ['guarantee_number', 'supplier', 'bank', 'amount', 'contract_number'];
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
    
    // Check existence
    $existing = $repo->find($guaranteeId);
    if (!$existing) {
        throw new \RuntimeException("الضمان غير موجود");
    }

    $guaranteeNumber = Input::string($input, 'guarantee_number', '');
    $supplier = Input::string($input, 'supplier', '');
    $bank = Input::string($input, 'bank', '');
    $contractNumber = Input::string($input, 'contract_number', '');
    $expiryDate = Input::string($input, 'expiry_date', '');
    $issueDate = Input::string($input, 'issue_date', '');
    $type = Input::string($input, 'type', 'Initial');
    $comment = Input::string($input, 'comment', '');
    $relatedTo = Input::string($input, 'related_to', 'contract');

    // Check duplication if number changed
    if ($guaranteeNumber !== $existing->guaranteeNumber) {
        if ($repo->findByNumber($guaranteeNumber)) {
            throw new \RuntimeException("رقم الضمان موجود بالفعل: " . $guaranteeNumber);
        }
    }

    // Prepare Raw Data for RAW_DATA column
    $cleanAmount = str_replace(',', '', $amount);
    
    // Merge with existing raw data to preserve other fields
    $currentRaw = $existing->rawData ?? [];
    $newRaw = array_merge($currentRaw, [
        'bg_number' => $guaranteeNumber,
        'supplier' => $supplier,
        'bank' => $bank,
        'amount' => $cleanAmount,
        'contract_number' => $contractNumber,
        'expiry_date' => $expiryDate ?: null,
        'issue_date' => $issueDate ?: null,
        'type' => $type,
        'details' => $comment,
        'related_to' => $relatedTo,
    ]);

    // Update DB
    $updateSql = "UPDATE guarantees SET raw_data = ?, imported_at = CURRENT_TIMESTAMP";
    $params = [json_encode($newRaw, JSON_UNESCAPED_UNICODE), $guaranteeId];
    
    $settings = Settings::getInstance();
    if (!$settings->isProductionMode()) {
        $updateSql .= ", is_test_data = 1";
    }
    
    $updateSql .= " WHERE id = ?";
    
    $db->prepare($updateSql)->execute($params);

    // Record Event
    \App\Services\TimelineRecorder::recordManualEditEvent(
        $guaranteeId,
        $newRaw
    );

    echo json_encode(['success' => true, 'message' => 'تم تعديل الضمان بنجاح']);

} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
