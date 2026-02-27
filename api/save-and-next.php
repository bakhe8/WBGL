<?php
/**
 * V3 API - Save and Next (Server-Driven Partial HTML)
 * Saves current record decision and returns HTML for next record
 * Single endpoint = single decision
 */

require_once __DIR__ . '/_bootstrap.php';

use App\Support\Database;
use App\Services\GuaranteeMutationPolicyService;
use App\Repositories\GuaranteeRepository;
use App\Support\BankNormalizer;
use App\Support\Input;
use App\Support\Logger;

header('Content-Type: application/json; charset=utf-8');
wbgl_api_require_login();

try {
    // Get POST data
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }
    
    $guaranteeId = Input::int($input, 'guarantee_id');
    $supplierId = Input::int($input, 'supplier_id');
    $supplierName = Input::string($input, 'supplier_name', '');
    // Bank is no longer sent - it's set once during import/matching
    $currentIndex = Input::int($input, 'current_index', 1) ?? 1;
    
    if (!$guaranteeId) {
        echo json_encode(['success' => false, 'error' => 'guarantee_id is required']);
        exit;
    }
    
    $db = Database::connect();
    $guaranteeRepo = new GuaranteeRepository($db);
    $currentGuarantee = $guaranteeRepo->find($guaranteeId);
    $decidedBy = Input::string($input, 'decided_by', wbgl_api_current_user_display());

    $policy = GuaranteeMutationPolicyService::evaluate(
        (int)$guaranteeId,
        $input,
        'save_and_next_decision',
        $decidedBy
    );
    if (!$policy['allowed']) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'released_read_only',
            'message' => $policy['reason'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $resolveBankId = static function (PDO $db, array $candidates): ?int {
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
    };

    $updateRawBankName = static function (PDO $db, int $guaranteeId, string $officialBankName): void {
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
    };

    $ensureBankMatchTimeline = static function (PDO $db, int $guaranteeId, array $rawData, string $rawBankName, string $matchedBankName): void {
        $hasBankMatchStmt = $db->prepare("SELECT 1 FROM guarantee_history WHERE guarantee_id = ? AND event_subtype = 'bank_match' LIMIT 1");
        $hasBankMatchStmt->execute([$guaranteeId]);
        if ($hasBankMatchStmt->fetchColumn()) {
            return;
        }

        $importAtStmt = $db->prepare("SELECT created_at FROM guarantee_history WHERE guarantee_id = ? AND event_type = 'import' ORDER BY created_at ASC, id ASC LIMIT 1");
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
            'status' => 'pending'
        ];

        $changes = [[
            'field' => 'bank_name',
            'old_value' => $rawBankName,
            'new_value' => $matchedBankName,
            'trigger' => 'auto'
        ]];
        $eventDetails = [
            'action' => 'Bank auto-matched',
            'result' => 'Automatically matched during save',
            'event_time' => $eventAt,
        ];
        $afterSnapshot = \App\Services\TimelineRecorder::createSnapshot($guaranteeId);

        \App\Services\TimelineRecorder::recordStructuredEvent(
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
    };

    // Track decision source for AI success metrics
    $decisionSource = null;
    $wasAiMatch = false;
    $autoCreatedSupplierName = null;

    // SAFEGUARD: Check for ID/Name Mismatch
    // If frontend failed to clear ID, but user changed name, we trust the NAME.
    if ($supplierId && $supplierName) {
        $chkStmt = $db->prepare('SELECT official_name FROM suppliers WHERE id = ?');
        $chkStmt->execute([$supplierId]);
        $dbName = $chkStmt->fetchColumn();
        
        // Compare normalized
        if ($dbName && mb_strtolower(trim($dbName)) !== mb_strtolower(trim($supplierName))) {
            // Mismatch detected! User changed name. Ignore old ID.
            $supplierId = null; 
        } else if ($dbName) {
            // ID and name match - this was likely selected from AI suggestions
            $decisionSource = 'ai_match';
            $wasAiMatch = true;
        }
    }

    // 1. Resolve Supplier ID if missing (or cleared by safeguard)
    $supplierError = '';
    if (!$supplierId && $supplierName) {
        $normStub = mb_strtolower($supplierName);
        
        // Strategy A: Exact Match
        $stmt = $db->prepare('SELECT id FROM suppliers WHERE official_name = ?'); 
        $stmt->execute([$supplierName]);
        $supplierId = $stmt->fetchColumn();
        
        // Strategy B: Normalized Match (Case insensitive)
        if (!$supplierId) {
            $stmt = $db->prepare('SELECT id FROM suppliers WHERE normalized_name = ?');
            $stmt->execute([$normStub]);
            $supplierId = $stmt->fetchColumn();
        }

        // ✅ Smart Save: Auto-Create Supplier if not found
        if (!$supplierId) {
            try {
                // Get the original imported name from raw data (if available)
                $originalImportedName = $currentGuarantee->rawData['supplier'] ?? '';
                
                // Smart Logic:
                // If user entered Arabic Name (Official) AND Original was English (Imported)
                // Then use Original as "English Name"
                $englishNameCandidate = null;
                
                if (preg_match('/\p{Arabic}/u', $supplierName)) {
                    // User entered Arabic. Check if we have an English original.
                    if ($originalImportedName && !preg_match('/\p{Arabic}/u', $originalImportedName)) {
                        $englishNameCandidate = $originalImportedName;
                    }
                } else {
                    // User entered English. Use it as both (handled by service/logic downstream)
                    $englishNameCandidate = $supplierName;
                }

                $createResult = \App\Services\SupplierManagementService::create($db, [
                    'official_name' => $supplierName,
                    'english_name' => $englishNameCandidate
                ]);
                
                $supplierId = $createResult['supplier_id'];
                $decisionSource = 'auto_create_on_save';
                $autoCreatedSupplierName = $createResult['official_name'] ?? $supplierName;

            } catch (Exception $e) {
                // Creation failed (e.g. valid duplicate race condition?)
                // Fallback to error
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'creation_failed',
                    'message' => 'فشل إنشاء المورد تلقائياً: ' . $e->getMessage(),
                    'supplier_name' => $supplierName
                ]);
                exit;
            }
        }
    } else if ($supplierId && !$wasAiMatch) {
        // Supplier ID was provided from the start (likely from previous decision)
        $decisionSource = 'manual';
    }


    // Any user-triggered save is manual unless a source was already detected
    if (!$decisionSource) {
        $decisionSource = 'manual';
    }

    // Basic validation (bank_id will be validated later after fetching from DB)
    if (!$guaranteeId || !$supplierId) {
        $missing = [];
        if (!$guaranteeId) $missing[] = 'Guarantee ID';
        if (!$supplierId) $missing[] = "Supplier (Unknown" . ($supplierError ? ": $supplierError" : "") . ")";
        
        http_response_code(400); // Bad Request
        echo json_encode([
            'success' => false, 
            'error' => 'Missing required fields', 
            'message' => 'حقول مطلوبة مفقودة: ' . implode(', ', $missing),
            'missing_fields' => $missing
        ]);
        exit;
    }
    
    // --- DETECT CHANGES ---
    $now = date('Y-m-d H:i:s');
    $changes = [];
    
    // Determine old state (last decision > raw data)
    $lastDecStmt = $db->prepare('SELECT supplier_id FROM guarantee_decisions WHERE guarantee_id = ? LIMIT 1');
    $lastDecStmt->execute([$guaranteeId]);
    $prevDecision = $lastDecStmt->fetch(PDO::FETCH_ASSOC);
    

    // Resolve Old Supplier Name
    if ($prevDecision && $prevDecision['supplier_id']) {
        $stmt = $db->prepare('SELECT official_name FROM suppliers WHERE id = ?');
        $stmt->execute([$prevDecision['supplier_id']]);
        $oldSupplier = $stmt->fetchColumn() ?: '';
    } else {
        $oldSupplier = $currentGuarantee->rawData['supplier'] ?? '';
    }

    // Resolve Old Bank Name
    // Bank is set once during import/matching and not changed by this endpoint.
    // We only respect an existing bank_id from guarantee_decisions.
    $bankStmt = $db->prepare('SELECT bank_id FROM guarantee_decisions WHERE guarantee_id = ?');
    $bankStmt->execute([$guaranteeId]);
    $bankIdRaw = $bankStmt->fetchColumn();
    // ✅ FIX: Convert 0 or false to NULL for foreign key compliance
    $bankId = ($bankIdRaw && $bankIdRaw > 0) ? (int)$bankIdRaw : null;

    // Attempt to auto-resolve bank when decision row is missing bank_id
    if (!$bankId) {
        $rawBank = (string)($currentGuarantee->rawData['bank'] ?? '');
        $guaranteeLabel = (string)($currentGuarantee->guaranteeNumber ?? '');
        $resolvedBankId = $resolveBankId($db, [$rawBank, $guaranteeLabel]);

        if ($resolvedBankId) {
            $upsertDecisionStmt = $db->prepare('SELECT id FROM guarantee_decisions WHERE guarantee_id = ? LIMIT 1');
            $upsertDecisionStmt->execute([$guaranteeId]);
            $decisionId = $upsertDecisionStmt->fetchColumn();

            if ($decisionId) {
                $upd = $db->prepare('UPDATE guarantee_decisions SET bank_id = ?, last_modified_at = CURRENT_TIMESTAMP, last_modified_by = ? WHERE guarantee_id = ?');
                $upd->execute([$resolvedBankId, $decidedBy, $guaranteeId]);
            } else {
                $ins = $db->prepare('INSERT INTO guarantee_decisions (guarantee_id, supplier_id, bank_id, status, decided_at, decision_source, decided_by, created_at) VALUES (?, NULL, ?, ?, ?, ?, ?, ?)');
                $nowTmp = date('Y-m-d H:i:s');
                $ins->execute([$guaranteeId, $resolvedBankId, 'pending', $nowTmp, 'auto_bank_resolve', $decidedBy, $nowTmp]);
            }

            $bankId = (int)$resolvedBankId;
            $matchedBankNameStmt = $db->prepare('SELECT arabic_name FROM banks WHERE id = ? LIMIT 1');
            $matchedBankNameStmt->execute([$bankId]);
            $matchedBankName = (string)($matchedBankNameStmt->fetchColumn() ?: $rawBank);
            $updateRawBankName($db, $guaranteeId, $matchedBankName);
            $ensureBankMatchTimeline($db, $guaranteeId, $currentGuarantee->rawData, $rawBank, $matchedBankName);
        }
    }

    // ✅ VALIDATION: Ensure bank_id exists (after fetching/resolving)
    if (!$bankId) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'error' => 'bank_required',
            'message' => 'لم يتم تحديد البنك - يجب مطابقة البنك تلقائياً أولاً. يرجى التأكد من وجود البنك في قاعدة البيانات.',
            'details' => 'Bank ID is missing from guarantee_decisions'
        ]);
        exit;
    }

    if ($bankId) {
        $stmt = $db->prepare('SELECT arabic_name FROM banks WHERE id = ?');
        $stmt->execute([$bankId]);
        $oldBank = $stmt->fetchColumn() ?: '';
    } else {
        $oldBank = $currentGuarantee->rawData['bank'] ?? '';
    }
    
    // 1. Check Supplier Change
    // Fetch new supplier name
    $supStmt = $db->prepare('SELECT official_name FROM suppliers WHERE id = ?');
    $supStmt->execute([$supplierId]);
    $newSupplier = $supStmt->fetchColumn();
    
    // Normalize for comparison (Trim spaces)
    if (trim($oldSupplier) !== trim($newSupplier)) {
        $changes[] = "تغيير المورد من [{$oldSupplier}] إلى [{$newSupplier}]";
    }
    
    // 2. Check Bank Change
    // Get current bank_id (never changes after auto-match)
    $bankStmt = $db->prepare('SELECT bank_id FROM guarantee_decisions WHERE guarantee_id = ?');
    $bankStmt->execute([$guaranteeId]);
    $currentBankId = $bankStmt->fetchColumn() ?: null;
    
    if ($currentBankId) {
        // Fetch new bank name (which is the current bank name, as it's not being changed here)
        $bnkStmt = $db->prepare('SELECT arabic_name FROM banks WHERE id = ?');
        $bnkStmt->execute([$currentBankId]);
        $newBank = $bnkStmt->fetchColumn();
        
        if (trim($oldBank) !== trim($newBank)) {
            $changes[] = "تغيير البنك من [{$oldBank}] إلى [{$newBank}]";
        }
    }

    // ====================================================================
    // TIMELINE INTEGRATION - Track changes with new logic
    // ====================================================================
    
    require_once __DIR__ . '/../app/Services/TimelineRecorder.php';
    
    // 1. SNAPSHOT: Capture state BEFORE update
    $oldSnapshot = \App\Services\TimelineRecorder::createSnapshot($guaranteeId);
    
    // 2. UPDATE: Calculate status and save decision to DB
    $statusToSave = \App\Services\StatusEvaluator::evaluate($supplierId, $bankId);
    
    // UPDATE supplier only (bank remains unchanged from initial auto-match)
    // UPDATE supplier only (bank remains unchanged from initial auto-match)
    // Check if decision exists
    $chkDec = $db->prepare('SELECT id FROM guarantee_decisions WHERE guarantee_id = ?');
    $chkDec->execute([$guaranteeId]);
    $existingId = $chkDec->fetchColumn();

    if ($existingId) {
        $stmt = $db->prepare('
            UPDATE guarantee_decisions 
            SET supplier_id = ?, status = ?, decided_at = ?, decision_source = ?, decided_by = ?, last_modified_by = ?, last_modified_at = CURRENT_TIMESTAMP
            WHERE guarantee_id = ?
        ');
        $stmt->execute([
            $supplierId,
            $statusToSave,
            $now,
            $decisionSource,
            $decidedBy,
            $decidedBy,
            $guaranteeId
        ]);
     } else {
        // Create new decision
        // ✅ TYPE SAFETY: Ensure IDs are integers or NULL (not empty strings or 0)
        $supplierIdSafe = ($supplierId && $supplierId > 0) ? (int)$supplierId : null;
        $bankIdSafe = ($bankId && $bankId > 0) ? (int)$bankId : null;
        
        $stmt = $db->prepare('
            INSERT INTO guarantee_decisions (guarantee_id, supplier_id, bank_id, status, decided_at, decision_source, decided_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $guaranteeId,
            $supplierIdSafe,
            $bankIdSafe,
            $statusToSave,
            $now,
            $decisionSource,
            $decidedBy,
            $now
        ]);
    }

    Logger::info('save_and_next_decision', [
        'guarantee_id' => $guaranteeId,
        'supplier_id' => $supplierId,
        'bank_id' => $bankId,
        'has_bank' => (bool)$bankId,
        'status' => $statusToSave,
        'decision_source' => $decisionSource,
        'decided_by' => $decidedBy
    ]);

    // NOTE: guarantees table has NO status column
    // Status is derived from guarantee_decisions table in index.php
    // We set $mockRecord['status'] = 'ready' when decision exists (see index.php line 169)

    // Clear active_action logic
    if (!empty($changes)) {
        // ADR-007: Clear active_action when data changes
        // Rationale: Data changed → old letter snapshot no longer reflects current state
        $statusStmt = $db->prepare('SELECT status FROM guarantee_decisions WHERE guarantee_id = ?');
        $statusStmt->execute([$guaranteeId]);
        $currentStatus = $statusStmt->fetchColumn();
        
        if ($currentStatus === 'ready') {
            // Clear active_action since data has changed
            $clearActiveStmt = $db->prepare('
                UPDATE guarantee_decisions
                SET active_action = NULL, active_action_set_at = NULL
                WHERE guarantee_id = ?
            ');
            $clearActiveStmt->execute([$guaranteeId]);
            error_log("📝 ADR-007: Cleared active_action for guarantee {$guaranteeId} due to data changes");
        }
    }
    
    // 3. RECORD: Strict Event Recording (UE-01 Decision)
    $newData = [
        'supplier_id' => $supplierId,
        'supplier_name' => $newSupplier
    ];

    // Detect if this is a correction (previously was ready or re-opened)
    $wasReadyStmt = $db->prepare("SELECT 1 FROM guarantee_history WHERE guarantee_id = ? AND event_subtype = 'reopened' ORDER BY id DESC LIMIT 1");
    $wasReadyStmt->execute([$guaranteeId]);
    $isCorrection = (bool)$wasReadyStmt->fetchColumn();
    
    $decisionSubtype = $isCorrection ? 'correction' : ($wasAiMatch ? 'ai_match' : 'manual_edit');

    try {
        \App\Services\TimelineRecorder::recordDecisionEvent($guaranteeId, $oldSnapshot, $newData, false, null, $decisionSubtype);
        error_log("[TIMELINE] Decision event recorded for guarantee #$guaranteeId as $decisionSubtype");
    } catch (\Throwable $e) {
        error_log("[TIMELINE ERROR] Failed to record decision event: " . $e->getMessage());
    }
    
    // 4. RECORD: Status Transition Event (SE-01/SE-02) - Separate Event
    // 🔧 FIX: Use oldSnapshot for status detection (comparing Old vs New)
    // Previously used newSnapshot which prevented detection because status was already updated in DB
    
    try {
        \App\Services\TimelineRecorder::recordStatusTransitionEvent($guaranteeId, $oldSnapshot, $statusToSave, 'data_completeness_check');
        error_log("[TIMELINE] Status transition event recorded for guarantee #$guaranteeId: $statusToSave");
    } catch (\Throwable $e) {
        error_log("[TIMELINE ERROR] Failed to record status transition: " . $e->getMessage());
    }
    
    // --- SMART LEARNING FEEDBACK LOOP ---
    // 🧠 Learn from this decision (both confirmations AND rejections)
    try {
        // We need the raw data to learn the mapping (Raw Name -> Chosen ID)
        $guaranteeRepo = new GuaranteeRepository($db);
        $currentGuarantee = $guaranteeRepo->find($guaranteeId);
        
        if ($currentGuarantee && isset($currentGuarantee->rawData['supplier']) && $supplierId) {
            $learningRepo = new \App\Repositories\LearningRepository($db);
            $rawSupplierName = $currentGuarantee->rawData['supplier'];
            
            // ✅ Step 1: Log CONFIRMATION/CORRECTION for chosen supplier
            try {
                $learningRepo->logDecision([
                    'guarantee_id' => $guaranteeId,
                    'raw_supplier_name' => $rawSupplierName,
                    'supplier_id' => $supplierId,
                    'action' => $isCorrection ? 'correction' : 'confirm',
                    'confidence' => 100, // Manual = 100%
                    'decision_time_seconds' => 0
                ]);
            } catch (\Exception $e) {
                error_log("[LEARNING ERROR] Failed to log decision: " . $e->getMessage());
            }

            // ✅ Step 2: Log REJECTION for ignored top suggestion (implicit learning)
            // Get current suggestions to identify what user ignored
            $authority = \App\Services\Learning\AuthorityFactory::create();
            $suggestions = $authority->getSuggestions($rawSupplierName);
            
            if (!empty($suggestions)) {
                $topSuggestion = $suggestions[0];
                
                // If user chose DIFFERENT supplier than top suggestion → implicit rejection
                if ($topSuggestion->supplier_id != $supplierId) {
                    $learningRepo->logDecision([
                        'guarantee_id' => $guaranteeId,
                        'raw_supplier_name' => $rawSupplierName,
                        'supplier_id' => $topSuggestion->supplier_id,
                        'action' => 'reject',
                        'confidence' => $topSuggestion->confidence,
                        'matched_anchor' => $topSuggestion->official_name,
                        'decision_time_seconds' => 0
                    ]);
                }
            }
        }
    } catch (\Throwable $e) { 
        error_log("Learning log error: " . $e->getMessage());
    }
    
    // ✅ FIX: Use NavigationService for consistent ordering (same as index.php)
    // Get status filter from request (default to 'all')
    $statusFilter = Input::string($input, 'status_filter', 'all');
    
    // Get navigation info using NavigationService
    $navInfo = \App\Services\NavigationService::getNavigationInfo(
        $db,
        $guaranteeId, // Current guarantee ID
        $statusFilter
    );
    
    $nextGuaranteeId = $navInfo['nextId'];
    
    $meta = [];
    if ($autoCreatedSupplierName) {
        $meta['created_supplier_name'] = $autoCreatedSupplierName;
    }

    if (!$nextGuaranteeId) {
        // No more records - finished
        $response = [
            'success' => true,
            'finished' => true,
            'message' => 'تم الانتهاء من جميع السجلات'
        ];
        if (!empty($meta)) {
            $response['meta'] = $meta;
        }
        echo json_encode($response);
        exit;
    }
    
    // Get next record
    $guaranteeRepo = new GuaranteeRepository($db);
    $guarantee = $guaranteeRepo->find($nextGuaranteeId);
    
    if (!$guarantee) {
        throw new \RuntimeException('Next record not found');
    }

    $raw = $guarantee->rawData;

    // Prepare record data
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
        'status' => 'pending'
    ];
    
    // Check for decision row (single-row policy)
    $stmtDec = $db->prepare('SELECT status, supplier_id, bank_id FROM guarantee_decisions WHERE guarantee_id = ? LIMIT 1');
    $stmtDec->execute([$nextGuaranteeId]);
    $lastDecision = $stmtDec->fetch(PDO::FETCH_ASSOC);
    
    if ($lastDecision) {
        $record['status'] = $lastDecision['status'];
        $record['bank_id'] = $lastDecision['bank_id'];
    }
    
    // Get banks for dropdown
    $banksStmt = $db->query('SELECT id, arabic_name as official_name FROM banks ORDER BY arabic_name');
    $banks = $banksStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get updated navigation info for the next record
    $nextNavInfo = \App\Services\NavigationService::getNavigationInfo(
        $db,
        $nextGuaranteeId,
        $statusFilter
    );
    
    // Include partial template to render HTML for next record
    // Return data for next record as JSON
    $response = [
        'success' => true,
        'finished' => false,
        'record' => $record,
        'banks' => $banks,
        'currentIndex' => $nextNavInfo['currentIndex'],
        'totalRecords' => $nextNavInfo['totalRecords']
    ];
    if (!empty($meta)) {
        $response['meta'] = $meta;
    }
    echo json_encode($response);
    
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
