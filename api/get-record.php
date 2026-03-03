<?php
/**
 * V3 API - Get Record by Index (Server-Driven Partial HTML)
 * Returns HTML fragment for record form section
 */

require_once __DIR__ . '/_bootstrap.php';

use App\Support\Database;
use App\Repositories\GuaranteeRepository;
use App\Services\UiSurfacePolicyService;

header('Content-Type: text/html; charset=utf-8');
wbgl_api_require_login();

try {
    $index = isset($_GET['index']) ? intval($_GET['index']) : 1;
    $statusFilter = isset($_GET['filter']) ? trim((string)$_GET['filter']) : (isset($_GET['status_filter']) ? trim((string)$_GET['status_filter']) : 'all');
    $stageFilter = isset($_GET['stage']) ? trim((string)$_GET['stage']) : null;
    if ($stageFilter === '') {
        $stageFilter = null;
    }
    $searchTerm = isset($_GET['search']) ? trim((string)$_GET['search']) : null;
    if ($searchTerm === '') {
        $searchTerm = null;
    }
    
    if ($index < 1) {
        throw new \RuntimeException('Invalid index');
    }
    
    $db = Database::connect();
    $guaranteeRepo = new GuaranteeRepository($db);
    
    $guaranteeId = \App\Services\NavigationService::getIdByIndex(
        $db,
        $index,
        $statusFilter,
        $searchTerm,
        $stageFilter
    );

    if (!$guaranteeId) {
        echo '<div id="record-form-section" class="decision-card decision-card-empty-state"'
            . ' data-policy-visible="0"'
            . ' data-policy-actionable="0"'
            . ' data-policy-executable="0"'
            . ' data-surface-can-view-record="0"'
            . ' data-surface-can-view-preview="0"'
            . ' data-surface-can-execute-actions="0">';
        echo '<div class="card-body"><div class="empty-state-message" data-i18n="index.empty.no_record_in_scope">لا توجد سجلات ضمن نطاق العرض الحالي</div></div>';
        echo '</div>';
        return;
    }

    $policy = wbgl_api_policy_for_guarantee($db, (int)$guaranteeId);
    if (!$policy['visible']) {
        wbgl_api_fail(403, 'Permission Denied');
    }

    $stmtDec = $db->prepare('SELECT status, supplier_id, bank_id, active_action FROM guarantee_decisions WHERE guarantee_id = ? LIMIT 1');
    $stmtDec->execute([$guaranteeId]);
    $lastDecision = $stmtDec->fetch(PDO::FETCH_ASSOC) ?: null;

    $surfaceStatus = (string)($lastDecision['status'] ?? 'pending');
    $surface = UiSurfacePolicyService::forGuarantee(
        $policy,
        \App\Support\Guard::permissions(),
        $surfaceStatus
    );

    if (!($surface['can_view_record'] ?? false)) {
        echo '<div id="record-form-section" class="decision-card decision-card-empty-state"'
            . ' data-policy-visible="' . ($policy['visible'] ? '1' : '0') . '"'
            . ' data-policy-actionable="' . ($policy['actionable'] ? '1' : '0') . '"'
            . ' data-policy-executable="' . ($policy['executable'] ? '1' : '0') . '"'
            . ' data-surface-can-view-record="0"'
            . ' data-surface-can-view-preview="0"'
            . ' data-surface-can-execute-actions="0">';
        echo '<div class="card-body"><div class="empty-state-message" data-i18n="index.empty.no_record_in_scope">لا توجد سجلات ضمن نطاق العرض الحالي</div></div>';
        echo '</div>';
        return;
    }

    $guarantee = $guaranteeRepo->find($guaranteeId);
    
    if (!$guarantee) {
        throw new \RuntimeException('Record not found');
    }

    $raw = $guarantee->rawData;

    // Prepare record data
    $record = [
        'id' => $guarantee->id,
        'guarantee_number' => $guarantee->guaranteeNumber,
        'supplier_name' => $raw['supplier'] ?? '',
        'bank_name' => $raw['bank'] ?? '',
        'bank_id' => null, // Will be set from decision if exists
        'amount' => $raw['amount'] ?? 0,
        'expiry_date' => $raw['expiry_date'] ?? '',
        'issue_date' => $raw['issue_date'] ?? '',
        'contract_number' => $raw['contract_number'] ?? '',
        'type' => $raw['type'] ?? 'Initial',
        'related_to' => $raw['related_to'] ?? 'contract',
        'active_action' => null,
        'status' => 'pending'
    ];
    
    if ($lastDecision) {
        $record['status'] = $lastDecision['status'];
        $record['bank_id'] = $lastDecision['bank_id'];
        $record['supplier_id'] = $lastDecision['supplier_id']; // Ensure ID is set
        $record['active_action'] = $lastDecision['active_action'];

        // Resolve Supplier Name from ID
        if ($record['supplier_id']) {
            $sStmt = $db->prepare('SELECT official_name FROM suppliers WHERE id = ?');
            $sStmt->execute([$record['supplier_id']]);
            $sName = $sStmt->fetchColumn();
            if ($sName) {
                $record['supplier_name'] = $sName;
            }
        }
        
        // Resolve Bank Name from ID
        if ($record['bank_id']) {
            $bStmt = $db->prepare('
                SELECT 
                    arabic_name as bank_name,
                    department,
                    address_line1 as po_box,
                    contact_email as email
                FROM banks WHERE id = ?
            ');
            $bStmt->execute([$record['bank_id']]);
            $bankDetails = $bStmt->fetch(PDO::FETCH_ASSOC);
            if ($bankDetails) {
                $record['bank_name'] = $bankDetails['bank_name'];
                $record['bank_center'] = $bankDetails['department'];
                $record['bank_po_box'] = $bankDetails['po_box'];
                $record['bank_email'] = $bankDetails['email'];
            }
        }
    }
    
    // === UI LOGIC PROJECTION: Status Reasons (Phase 1) ===
    // Get WHY status is what it is for user transparency
    $statusReasons = \App\Services\StatusEvaluator::getReasons(
        $record['supplier_id'] ?? null,
        $record['bank_id'] ?? null,
        [] // Conflicts will be added later in Phase 3
    );
    $record['status_reasons'] = $statusReasons;
    
    // ADR-007: Timeline is audit-only, not a data source for record rendering.
    $latestEventSubtype = null; // Removed Timeline read
    
    // Get banks for dropdown
    $banksStmt = $db->query('
        SELECT 
            id, 
            arabic_name as official_name,
            department,
            address_line1 as po_box,
            contact_email as email
        FROM banks 
        ORDER BY arabic_name
    ');
    $banks = $banksStmt->fetchAll(PDO::FETCH_ASSOC);

    // --- SMART LEARNING INTEGRATION ---
    
    // 1. Supplier Matching
    $supplierMatch = ['score' => 0, 'id' => null, 'name' => '', 'suggestions' => []];
    try {
        // ✅ PHASE 4: Using UnifiedLearningAuthority
        $authority = \App\Services\Learning\AuthorityFactory::create();
        
        if (!empty($record['supplier_name'])) {
            $suggestionDTOs = $authority->getSuggestions($record['supplier_name']);
            $suggestions = array_map(static fn($dto) => $dto->toArray(), $suggestionDTOs);

            if (!empty($suggestions)) {
                $top = $suggestions[0];
                $supplierMatch = [
                    'score' => (int)($top['confidence'] ?? 0),
                    'id' => (int)($top['supplier_id'] ?? 0),
                    'name' => (string)($top['official_name'] ?? ''),
                    'suggestions' => $suggestions // Pass all suggestions for chips
                ];
            }
        }
    } catch (\Throwable $e) { /* Ignore learning errors */ }

    // 2. Bank Matching - Direct with BankNormalizer
    $bankMatch = ['score' => 0, 'id' => null, 'name' => ''];
    try {
        if (!empty($record['bank_name'])) {
            $normalized = \App\Support\BankNormalizer::normalize($record['bank_name']);
            $stmt = $db->prepare("
                SELECT b.id, b.arabic_name as bank_name
                FROM banks b
                JOIN bank_alternative_names a ON b.id = a.bank_id
                WHERE a.normalized_name = ?
                LIMIT 1
            ");
            $stmt->execute([$normalized]);
            $bank = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($bank) {
                $bankMatch = [
                    'score' => 100, // Direct match
                    'id' => $bank['id'],
                    'name' => $bank['bank_name']
                ];
            }
        }
    } catch (\Throwable $e) { /* Ignore matching errors */ }
    
    $surface = UiSurfacePolicyService::forGuarantee(
        $policy,
        \App\Support\Guard::permissions(),
        (string)($record['status'] ?? 'pending')
    );
    $recordCanExecuteActions = (bool)($surface['can_execute_actions'] ?? false);

    // Include only record form (timeline is separate in sidebar)
    ob_start();
    include __DIR__ . '/../partials/record-form.php';
    $html = ob_get_clean();
    
    // Wrap in container div
    echo '<div id="record-form-section" class="decision-card" data-current-event-type="current"'
        . ' data-policy-visible="' . ($policy['visible'] ? '1' : '0') . '"'
        . ' data-policy-actionable="' . ($policy['actionable'] ? '1' : '0') . '"'
        . ' data-policy-executable="' . ($policy['executable'] ? '1' : '0') . '"'
        . ' data-surface-can-view-record="' . (($surface['can_view_record'] ?? false) ? '1' : '0') . '"'
        . ' data-surface-can-view-preview="' . (($surface['can_view_preview'] ?? false) ? '1' : '0') . '"'
        . ' data-surface-can-execute-actions="' . (($surface['can_execute_actions'] ?? false) ? '1' : '0') . '">';
    echo $html;
    echo '</div>';
    
} catch (\Throwable $e) {
    http_response_code(500);
    echo '<div id="record-form-section" class="card" data-current-event-type="current">';
    echo '<div class="card-body" style="color: red;">خطأ: ' . htmlspecialchars($e->getMessage()) . '</div>';
    echo '</div>';
}
