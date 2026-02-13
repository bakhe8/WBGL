<?php
/**
 * V3 API - Release Guarantee (Server-Driven Partial HTML)
 */

require_once __DIR__ . '/../app/Support/autoload.php';
require_once __DIR__ . '/../app/Services/TimelineRecorder.php';

use App\Repositories\GuaranteeDecisionRepository;
use App\Repositories\GuaranteeRepository;
use App\Repositories\SupplierRepository;
use App\Support\Database;
use App\Support\Input;
use App\Services\LetterBuilder;

header('Content-Type: text/html; charset=utf-8');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }
    
    $guaranteeId = Input::int($input, 'guarantee_id');
    $reason = Input::string($input, 'reason', '') ?: null; // Optional
    $decidedBy = Input::string($input, 'decided_by', 'web_user');
    
    if (!$guaranteeId) {
        throw new \RuntimeException('Missing guarantee_id');
    }
    
    // Initialize services
    $db = Database::connect();
    $decisionRepo = new GuaranteeDecisionRepository($db);
    $guaranteeRepo = new GuaranteeRepository($db);
    
    // ===== LIFECYCLE GATE: Prevent release on pending guarantees =====
    $statusCheck = $db->prepare("
        SELECT status 
        FROM guarantee_decisions 
        WHERE guarantee_id = ?
    ");
    $statusCheck->execute([$guaranteeId]);
    $currentStatus = $statusCheck->fetchColumn();
    
    if ($currentStatus !== 'ready') {
        http_response_code(400);
        echo '<div id="record-form-section" class="card" data-current-event-type="current">';
        echo '<div class="card-body" style="color: red;">لا يمكن إفراج عن ضمان غير مكتمل. يجب اختيار المورد والبنك أولاً.</div>';
        echo '</div>';
        exit;
    }
    // ================================================================
    
    // --------------------------------------------------------------------
    // STRICT TIMELINE DISCIPLINE: Snapshot -> Update -> Record
    // --------------------------------------------------------------------

    // 1. SNAPSHOT: Capture state BEFORE release
    $oldSnapshot = \App\Services\TimelineRecorder::createSnapshot($guaranteeId);

    // 2. UPDATE: Execute system changes
    // Validate that supplier and bank are selected
    $decision = $decisionRepo->findByGuarantee($guaranteeId);
    if (!$decision || empty($decision->supplierId) || empty($decision->bankId)) {
        throw new \RuntimeException('لا يمكن تنفيذ الإفراج - يجب اختيار المورد والبنك أولاً');
    }
    
    // Check if already released
    if ($decision && $decision->status === 'released') {
        throw new \RuntimeException('تم إفراج هذا الضمان مسبقاً');
    }
    
    // Lock the guarantee (set status to 'released')
    $decisionRepo->lock($guaranteeId, 'released');
    $statusStmt = $db->prepare("
        UPDATE guarantee_decisions
        SET status = 'released',
            decision_source = 'manual',
            decided_by = ?,
            last_modified_by = ?,
            last_modified_at = CURRENT_TIMESTAMP
        WHERE guarantee_id = ?
    ");
    $statusStmt->execute([$decidedBy, $decidedBy, $guaranteeId]);

    // 3. NEW (Phase 3): Set Active Action
    $decisionRepo->setActiveAction($guaranteeId, 'release');

    // 4. RECORD: Strict Event Recording (UE-04 Release)
    \App\Services\TimelineRecorder::recordReleaseEvent($guaranteeId, $oldSnapshot, $reason);

    // --------------------------------------------------------------------
    
    // Get updated guarantee info for display
    $guarantee = $guaranteeRepo->find($guaranteeId);
    $raw = $guarantee->rawData;
    
    // Get supplier name (Arabic) from suppliers table
    $supplierRepo = new SupplierRepository($db);
    $supplier_name = $raw['supplier'] ?? ''; // Default to Excel name
    if ($decision && $decision->supplierId) {
        try {
            $supplier = $supplierRepo->find($decision->supplierId);
            if ($supplier) {
                $supplier_name = $supplier->officialName; // ✅ Use Arabic official name
            }
        } catch (\Exception $e) {
            // Keep Excel name if supplier not found
        }
    }
    
    // Prepare record data
    $record = [
        'id' => $guarantee->id,
        'guarantee_number' => $guarantee->guaranteeNumber,
        'supplier_name' => $supplier_name, // ✅ Now using Arabic name
        'bank_name' => $raw['bank'] ?? '',
        'bank_id' => null,
        'amount' => $raw['amount'] ?? 0,
        'expiry_date' => $raw['expiry_date'] ?? '',
        'issue_date' => $raw['issue_date'] ?? '',
        'contract_number' => $raw['contract_number'] ?? '',
        'type' => $raw['type'] ?? 'Initial',
        'status' => 'released',
        'related_to' => $raw['related_to'] ?? 'contract' // ✅ For LetterBuilder
    ];
    
    // 🆕 Generate Release Letter using LetterBuilder
    $letterData = LetterBuilder::prepare($record, 'release');
    $letterHtml = LetterBuilder::render($letterData);
    
    // Get banks for dropdown
    $banksStmt = $db->query('SELECT id, arabic_name as official_name FROM banks ORDER BY arabic_name');
    $banks = $banksStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Include partial template
    echo '<div id="record-form-section" class="decision-card" data-current-event-type="current">';
    include __DIR__ . '/../partials/record-form.php';
    
    // 🆕 Display Letter Preview Section
    echo '<div class="letter-preview-section" style="margin-top: 24px; background: white; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden;">';
    echo '<div style="padding: 16px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; font-weight: bold;">📄 خطاب الإفراج</div>';
    echo $letterHtml;
    echo '</div>';
    echo '</div>';
    
} catch (\Throwable $e) {
    http_response_code(400);
    echo '<div id="record-form-section" class="card" data-current-event-type="current">';
    echo '<div class="card-body" style="color: red;">خطأ: ' . htmlspecialchars($e->getMessage()) . '</div>';
    echo '</div>';
}
