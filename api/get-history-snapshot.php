<?php
/**
 * API: Get History Snapshot
 * Fetches a specific history event and renders the record form as it was at that time.
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;

header('Content-Type: text/html; charset=utf-8');

try {
    if (!isset($_GET['history_id'])) {
        throw new Exception('History ID is required');
    }

    $rawHistoryId = $_GET['history_id'];
    $db = Database::connect();
    $event = null;

    // Check for "Synthetic Import" ID
    if (strpos($rawHistoryId, 'import_synthetic_') === 0) {
        // Extract Guarantee ID
        $gId = (int)str_replace('import_synthetic_', '', $rawHistoryId);
        
        // Fetch Guarantee Info for Import Event
        $stmtG = $db->prepare('SELECT id, imported_at, imported_by FROM guarantees WHERE id = ?');
        $stmtG->execute([$gId]);
        $gInfo = $stmtG->fetch(PDO::FETCH_ASSOC);
        
        if (!$gInfo) throw new Exception('Guarantee not found for import event');
        
        // Construct Fake Event
        $event = [
            'id' => 0, // Mock
            'guarantee_id' => $gInfo['id'],
            'created_at' => $gInfo['imported_at'],
            'change_reason' => 'Initial Import from ' . ($gInfo['import_source'] ?? 'Excel'),
            'action' => 'import',
            'snapshot_data' => '{}', // Base state = Raw Data
            'created_by' => $gInfo['imported_by'] ?? 'System'
        ];
        
    } else {
        // Normal History Lookup
        $historyId = (int)$rawHistoryId;
        $stmt = $db->prepare('SELECT * FROM guarantee_history WHERE id = ?');
        $stmt->execute([$historyId]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$event) {
        throw new Exception('History event not found');
    }

    // 2. Decode the snapshot data
    $snapshot = json_decode($event['snapshot_data'] ?? '{}', true);
    if (!is_array($snapshot)) {
        $snapshot = [];
        // Optional: We could log this or just show base record
    }

    // 3. Reconstruct the $record object
    // Fetch parent guarantee
    $stmtRec = $db->prepare('SELECT * FROM guarantees WHERE id = ?');
    $stmtRec->execute([$event['guarantee_id']]);
    $dbRow = $stmtRec->fetch(PDO::FETCH_ASSOC);
    
    if (!$dbRow) {
        throw new Exception('Parent guarantee record not found');
    }

    // Decode Raw Data (assuming column is 'raw_data' based on Repository pattern, or it might be specific columns)
    // Looking at get-record.php: $guarantee->rawData. 
    // Usually GuaranteeRepository decodes 'raw_data' column.
    $raw = json_decode($dbRow['raw_data'] ?? '{}', true);
    
    // Default Record Mapping (copied from get-record.php)
    $record = [
        'id' => $dbRow['id'],
        'guarantee_number' => $dbRow['guarantee_number'] ?? ($raw['guarantee_number'] ?? ''),
        'supplier_name' => $raw['supplier'] ?? '', // Original from Excel
        'bank_name' => $raw['bank'] ?? '',         // Original from Excel
        'bank_id' => null, 
        'supplier_id' => null,
        'amount' => $raw['amount'] ?? 0,
        'expiry_date' => $raw['expiry_date'] ?? '',
        'issue_date' => $raw['issue_date'] ?? '',
        'contract_number' => $raw['contract_number'] ?? '',
        'type' => $raw['type'] ?? 'Initial',
        'status' => 'pending' // Default, will be overwritten by snapshot
    ];
    
    // OVERWRITE with Snapshot Data (The specific state of this history limit)
    // Snapshot keys might be 'supplier_id', 'bank_id', 'status', etc.
    foreach ($snapshot as $key => $val) {
        // Only overwrite if key exists or is relevant
        $record[$key] = $val ?? '';
    }
    
    // Resolve IDs to Names if Snapshot only had IDs
    if (!empty($record['supplier_id']) && empty($snapshot['supplier_name'])) {
        $supStmt = $db->prepare('SELECT standardized_name FROM suppliers WHERE id = ?');
        $supStmt->execute([$record['supplier_id']]);
        $name = $supStmt->fetchColumn();
        if ($name) $record['supplier_name'] = $name;
    }
    
    if (!empty($record['bank_id']) && empty($snapshot['bank_name'])) {
        $bankStmt = $db->prepare('SELECT arabic_name FROM banks WHERE id = ?');
        $bankStmt->execute([$record['bank_id']]);
        $name = $bankStmt->fetchColumn();
        if ($name) $record['bank_name'] = $name;
    }
    
    // 4. Set Historical Flags
    $isHistorical = true;
    $bannerData = [
        'timestamp' => $event['created_at'],
        'reason' => $event['change_reason'] ?? $event['action']
    ];
    
    // DEPENDENCIES FOR PARTIALS
    // The partials/record-form.php expects $guarantee (object), $supplierMatch, $bankMatch, $banks.
    // We must provide them to avoid "Undefined variable" errors.
    
    // Mock Guarantee Object (Minimal)
    $guarantee = new stdClass();
    $guarantee->rawData = $raw ?? []; 
    
    // Mock Matches to empty/null since we don't want to show suggestions in history
    $supplierMatch = ['score' => 0, 'suggestions' => []];
    $bankMatch = ['id' => 0];
    
    // Empty banks array (dropdown not needed)
    $banks = []; 
    
    // 5. Wrap in the same ID container as the main page to ensure direct replacement
    $index = $_GET['index'] ?? 1;
    echo '<div id="record-form-section" class="decision-card" data-record-index="' . $index . '">';
    
    // 6. Include the partial
    require __DIR__ . '/../partials/record-form.php';
    
    echo '</div>';

} catch (Throwable $e) {
    http_response_code(500);
    ?>
    <div class="alert alert-error">
        <h4>فشل تحميل النسخة التاريخية</h4>
        <p><?= htmlspecialchars($e->getMessage()) ?></p>
        <button data-action="load-record" data-index="<?= $_GET['index'] ?? 1 ?>" class="btn btn-secondary">
            العودة للوضع الحالي
        </button>
    </div>
    <?php
}
