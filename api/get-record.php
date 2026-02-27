<?php
/**
 * V3 API - Get Record by Index (Server-Driven Partial HTML)
 * Returns HTML fragment for record form section
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../app/Services/TimelineRecorder.php';

use App\Support\Database;
use App\Repositories\GuaranteeRepository;
use App\Support\Settings;
use App\Support\Logger;

header('Content-Type: text/html; charset=utf-8');
wbgl_api_require_login();

try {
    $index = isset($_GET['index']) ? intval($_GET['index']) : 1;
    $statusFilter = isset($_GET['filter']) ? trim((string)$_GET['filter']) : (isset($_GET['status_filter']) ? trim((string)$_GET['status_filter']) : 'all');
    $searchTerm = isset($_GET['search']) ? trim((string)$_GET['search']) : null;
    if ($searchTerm === '') {
        $searchTerm = null;
    }
    
    if ($index < 1) {
        throw new \RuntimeException('Invalid index');
    }
    
    $db = Database::connect();
    $settings = new Settings();
    $autoThreshold = $settings->get('MATCH_AUTO_THRESHOLD', 90);
    $guaranteeRepo = new GuaranteeRepository($db);

    $upsertDecision = function (
        int $guaranteeId,
        ?int $supplierId,
        ?int $bankId,
        string $status,
        string $decisionSource
    ) use ($db): void {
        $now = date('Y-m-d H:i:s');
        $chk = $db->prepare('SELECT id FROM guarantee_decisions WHERE guarantee_id = ?');
        $chk->execute([$guaranteeId]);
        $exists = $chk->fetchColumn();

        if ($exists) {
            $stmt = $db->prepare('
                UPDATE guarantee_decisions
                SET supplier_id = ?,
                    bank_id = ?,
                    status = ?,
                    decision_source = ?,
                    decided_at = ?,
                    last_modified_at = CURRENT_TIMESTAMP
                WHERE guarantee_id = ?
            ');
            $stmt->execute([
                $supplierId,
                $bankId,
                $status,
                $decisionSource,
                $now,
                $guaranteeId
            ]);
            return;
        }

        $stmt = $db->prepare('
            INSERT INTO guarantee_decisions (guarantee_id, supplier_id, bank_id, status, decided_at, decision_source, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $guaranteeId,
            $supplierId,
            $bankId,
            $status,
            $now,
            $decisionSource,
            $now
        ]);
    };
    
    $guaranteeId = \App\Services\NavigationService::getIdByIndex(
        $db,
        $index,
        $statusFilter,
        $searchTerm
    );

    if (!$guaranteeId) {
        throw new \RuntimeException('Index out of range');
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
    
    // Check for decision row (single-row policy)
    $stmtDec = $db->prepare('SELECT status, supplier_id, bank_id, active_action FROM guarantee_decisions WHERE guarantee_id = ? LIMIT 1');
    $stmtDec->execute([$guaranteeId]);
    $lastDecision = $stmtDec->fetch(PDO::FETCH_ASSOC);
    
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
                
                // Auto-fill if confidence is high and no decision yet
                if ($record['status'] === 'pending' && ((int)($top['confidence'] ?? 0) >= $autoThreshold)) {
                    try {
                        // 1. Capture snapshot BEFORE change
                        $oldSnapshot = \App\Services\TimelineRecorder::createSnapshot($guaranteeId);
                        
                        // 2. Update record data
                        $record['supplier_name'] = (string)($top['official_name'] ?? '');
                        $record['supplier_id'] = (int)($top['supplier_id'] ?? 0);
                        
                        // 3. Prepare change data
                        $newData = [
                            'supplier_id' => (int)($top['supplier_id'] ?? 0),
                            'supplier_name' => (string)($top['official_name'] ?? ''),
                            'supplier_trigger' => 'ai_match',
                            'supplier_confidence' => (int)($top['confidence'] ?? 0)
                        ];
                        
                        // 4. Detect changes from canonical patch
                        $changes = \App\Services\TimelineRecorder::createPatch((array)$oldSnapshot, (array)$newData);
                        
                        // 5. Save to guarantee_decisions
                        if (!empty($changes)) {
                            // Check if Bank implies status change
                            $decStmt = $db->prepare('SELECT bank_id FROM guarantee_decisions WHERE guarantee_id = ?');
                            $decStmt->execute([$guaranteeId]);
                            $currentDec = $decStmt->fetch(PDO::FETCH_ASSOC);
                            $bankId = $currentDec['bank_id'] ?? null;
                            
                            $newStatus = (((int)($top['supplier_id'] ?? 0) > 0) && $bankId) ? 'ready' : 'pending';

                            $upsertDecision($guaranteeId, (int)($top['supplier_id'] ?? 0), $bankId, $newStatus, 'auto');

                            Logger::info('auto_match_decision', [
                                'guarantee_id' => $guaranteeId,
                                'supplier_id' => (int)($top['supplier_id'] ?? 0),
                                'bank_id' => $bankId,
                                'confidence' => (int)($top['confidence'] ?? 0),
                                'threshold' => $autoThreshold,
                                'source' => 'get-record-supplier'
                            ]);
                            
                            // 6. Save timeline event (Strict UE-01)
                            \App\Services\TimelineRecorder::recordDecisionEvent($guaranteeId, $oldSnapshot, $newData, true, (int)($top['confidence'] ?? 0));
                            
                            // 7. Save Status Transition (SE-01) if changed
                            \App\Services\TimelineRecorder::recordStatusTransitionEvent($guaranteeId, $oldSnapshot, $newStatus, 'ai_completeness_check');
                        }
                    } catch (\Throwable $e) { /* Ignore match error */ }
                }
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
                
                // Auto-select bank if no decision yet
                if ($record['status'] === 'pending') {
                    try {
                        // 1. Capture snapshot BEFORE change
                        $oldSnapshot = \App\Services\TimelineRecorder::createSnapshot($guaranteeId);
                        
                        // 2. Update record data
                        $record['bank_id'] = $bank['id'];
                        
                        // 3. Prepare change data
                        $newData = [
                            'bank_id' => $bank['id'],
                            'bank_name' => $bank['bank_name'],
                            'bank_trigger' => 'direct_match'
                        ];
                        
                        // 4. Detect changes from canonical patch
                        $changes = \App\Services\TimelineRecorder::createPatch((array)$oldSnapshot, (array)$newData);
                        
                        // 5. Update guarantee_decisions
                        if (!empty($changes)) {
                            // Fetch current decision to preserve supplier_id
                            $decStmt = $db->prepare('SELECT supplier_id FROM guarantee_decisions WHERE guarantee_id = ?');
                            $decStmt->execute([$guaranteeId]);
                            $currentDec = $decStmt->fetch(PDO::FETCH_ASSOC);
                            $supplierId = $currentDec['supplier_id'] ?? null;
                            
                            $newStatus = ($supplierId && $bank['id']) ? 'ready' : 'pending';
                            
                            $upsertDecision($guaranteeId, $supplierId, $bank['id'], $newStatus, 'auto');

                            Logger::info('auto_match_decision', [
                                'guarantee_id' => $guaranteeId,
                                'supplier_id' => $supplierId,
                                'bank_id' => $bank['id'],
                                'confidence' => 100,
                                'threshold' => $autoThreshold,
                                'source' => 'get-record-bank'
                            ]);
                            
                            // 6. Save timeline event (Note: no longer recording bank events in timeline)
                            // Bank matching is now deterministic, so timeline events removed
                            
                            // 7. Save Status Transition (SE-01) if changed
                            \App\Services\TimelineRecorder::recordStatusTransitionEvent($guaranteeId, $oldSnapshot, $newStatus, 'ai_completeness_check');
                        }
                    } catch (\Throwable $e) { /* Ignore match error */ }
                }
            }
        }
    } catch (\Throwable $e) { /* Ignore matching errors */ }
    
    // Include only record form (timeline is separate in sidebar)
    ob_start();
    include __DIR__ . '/../partials/record-form.php';
    $html = ob_get_clean();
    
    // Wrap in container div
    echo '<div id="record-form-section" class="decision-card" data-current-event-type="current">';
    echo $html;
    echo '</div>';
    
} catch (\Throwable $e) {
    http_response_code(500);
    echo '<div id="record-form-section" class="card" data-current-event-type="current">';
    echo '<div class="card-body" style="color: red;">خطأ: ' . htmlspecialchars($e->getMessage()) . '</div>';
    echo '</div>';
}
