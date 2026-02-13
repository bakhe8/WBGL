<?php
/**
 * API: Get Release Letter Preview for Single Guarantee
 * Returns HTML of letter for display in guarantee details page
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;
use App\Repositories\GuaranteeRepository;
use App\Repositories\BankRepository;
use App\Repositories\SupplierRepository;
use App\Services\LetterBuilder;

header('Content-Type: text/html; charset=utf-8');

try {
    $guaranteeId = $_GET['id'] ?? null;
    
    if (!$guaranteeId) {
        throw new Exception('معرف الضمان مطلوب');
    }
    
    $db = Database::connect();
    $guaranteeRepo = new GuaranteeRepository($db);
    $bankRepo = new BankRepository();
    $supplierRepo = new SupplierRepository();
    
    // Fetch guarantee
    $guarantee = $guaranteeRepo->find((int)$guaranteeId);
    if (!$guarantee) {
        throw new Exception('الضمان غير موجود');
    }
    
    // Load decision data
    $decisionStmt = $db->prepare("SELECT * FROM guarantee_decisions WHERE guarantee_id = ? LIMIT 1");
    $decisionStmt->execute([$guaranteeId]);
    $decision = $decisionStmt->fetch(PDO::FETCH_ASSOC);
    
    // Check if released
    if (!$decision || $decision['status'] !== 'released') {
        throw new Exception('الضمان غير مُفرج عنه');
    }
    
    // Get last action from history
    $stmt = $db->prepare("
        SELECT CASE
                   WHEN gh.event_subtype IN ('extension', 'reduction', 'release') THEN gh.event_subtype
                   WHEN gh.event_type = 'release' THEN 'release'
                   ELSE NULL
               END AS last_action
        FROM guarantee_history gh
        WHERE gh.guarantee_id = ?
          AND (gh.event_subtype IN ('extension', 'reduction', 'release') OR gh.event_type = 'release')
        ORDER BY gh.created_at DESC, gh.id DESC
        LIMIT 1
    ");
    $stmt->execute([$guaranteeId]);
    $lastAction = $stmt->fetchColumn();
    
    if (!$lastAction) {
        $lastAction = 'release'; // Fallback
    }
    
    // Prepare data array
    $record = [
        'guarantee_number' => $guarantee->guaranteeNumber,
        'contract_number' => $guarantee->rawData['contract_number'] ?? '',
        'amount' => $guarantee->rawData['amount'] ?? 0,
        'expiry_date' => $guarantee->rawData['expiry_date'] ?? '',
        'type' => $guarantee->rawData['type'] ?? '',
        'related_to' => $guarantee->rawData['related_to'] ?? 'contract',
        'supplier_name' => $guarantee->rawData['supplier'] ?? 'غير محدد',
        'bank_name' => $guarantee->rawData['bank'] ?? 'غير محدد',
        'active_action' => $lastAction,
    ];
    
    // Enrich with supplier name
    if ($decision && $decision['supplier_id']) {
        $supplier = $supplierRepo->find((int)$decision['supplier_id']);
        if ($supplier) {
            $record['supplier_name'] = $supplier->officialName;
        }
    }
    
    // Enrich with bank details
    if ($decision && $decision['bank_id']) {
        $bank = $bankRepo->getBankDetails((int)$decision['bank_id']);
        if ($bank) {
            $record['bank_name'] = $bank['official_name'];
            $record['bank_center'] = $bank['department'];
            $record['bank_po_box'] = $bank['po_box'];
            $record['bank_email'] = $bank['email'];
        }
    }
    
    // Use letter-renderer partial (same as batch-print)
    $showPlaceholder = false;
    include __DIR__ . '/../partials/letter-renderer.php';
    
} catch (Exception $e) {
    echo '<div style="padding: 20px; text-align: center; color: #dc2626; font-family: Tajawal, sans-serif;">';
    echo '<p>⚠️ ' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '</div>';
}
