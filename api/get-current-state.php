<?php
/**
 * API Endpoint: Get Current State
 * Returns current (non-historical) state of a guarantee as HTML partial
 * Used by timeline controller when clicking "العودة للوضع الحالي"
 * 
 * Architecture: Server-Driven
 * - No client-side state
 * - Server is the single source of truth
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;
use App\Repositories\GuaranteeRepository;
use App\Repositories\GuaranteeDecisionRepository;
use App\Repositories\SupplierLearningRepository;
use App\Repositories\SupplierRepository;
use App\Repositories\BankRepository;

header('Content-Type: application/json; charset=utf-8');

// Validate input
$guaranteeId = $_GET['id'] ?? null;
if (!$guaranteeId || !is_numeric($guaranteeId)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'معرف الضمان مطلوب ويجب أن يكون رقم'
    ]);
    exit;
}

try {
    // Connect to database
    // Connect to database
    $db = Database::connect();
    $guaranteeRepo = new GuaranteeRepository($db);
    $decisionRepo = new GuaranteeDecisionRepository($db);
    $learningRepo = new SupplierLearningRepository($db);
    $supplierRepo = new SupplierRepository();
    // Removed deprecated LearningService
    $bankRepo = new BankRepository();
    
    // Load guarantee
    $guarantee = $guaranteeRepo->find($guaranteeId);
    if (!$guarantee) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'الضمان غير موجود'
        ]);
        exit;
    }
    
    // Build record data (same logic as index.php lines 135-210)
    $raw = $guarantee->rawData;
    
    $record = [
        'id' => $guarantee->id,
        'session_id' => $raw['session_id'] ?? 0,
        'guarantee_number' => $guarantee->guaranteeNumber ?? 'N/A',
        'supplier_name' => htmlspecialchars($raw['supplier'] ?? '', ENT_QUOTES),
        'bank_name' => htmlspecialchars($raw['bank'] ?? '', ENT_QUOTES),
        'amount' => is_numeric($raw['amount'] ?? 0) ? floatval($raw['amount'] ?? 0) : 0,
        'expiry_date' => $raw['expiry_date'] ?? '',
        'issue_date' => $raw['issue_date'] ?? '',
        'contract_number' => htmlspecialchars($raw['contract_number'] ?? '', ENT_QUOTES),
        'type' => htmlspecialchars($raw['type'] ?? 'ابتدائي', ENT_QUOTES),
        'status' => 'pending',
        'excel_supplier' => htmlspecialchars($raw['supplier'] ?? '', ENT_QUOTES),
        'excel_bank' => htmlspecialchars($raw['bank'] ?? '', ENT_QUOTES),
        'supplier_id' => null,
        'bank_id' => null,
        'decision_source' => null,
        'confidence_score' => null,
        'decided_at' => null,
        'decided_by' => null,
        'is_locked' => false,
        'locked_reason' => null
    ];
    
    // Load decision if exists
    $decision = $decisionRepo->findByGuarantee($guarantee->id);
    if ($decision) {
        $record['status'] = $decision->status;
        $record['supplier_id'] = $decision->supplierId;
        $record['bank_id'] = $decision->bankId;
        $record['decision_source'] = $decision->decisionSource;
        $record['confidence_score'] = $decision->confidenceScore;
        $record['decided_at'] = $decision->decidedAt;
        $record['decided_by'] = $decision->decidedBy;
        $record['is_locked'] = (bool)$decision->isLocked;
        $record['locked_reason'] = $decision->lockedReason;
        
        // Get official supplier name
        if ($decision->supplierId) {
            try {
                $supplier = $supplierRepo->find($decision->supplierId);
                if ($supplier) {
                    $record['supplier_name'] = $supplier->officialName;
                }
            } catch (\Exception $e) {
                // Keep Excel name
            }
        }
        
        // Get official bank name
        if ($decision->bankId) {
            try {
                $stmt = $db->prepare('SELECT arabic_name as official_name FROM banks WHERE id = ?');
                $stmt->execute([$decision->bankId]);
                $bank = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($bank) {
                    $record['bank_name'] = $bank['official_name'];
                }
            } catch (\Exception $e) {
                // Keep Excel name
            }
        }
    }

    // ADR-007: Timeline is audit-only, not UI data source
    $latestSubtype = null; // Removed Timeline read
    
    $supplierMatch = null;
    if (empty($record['supplier_id']) && $record['supplier_name']) {
        // ✅ PHASE 4: Using UnifiedLearningAuthority
        $authority = \App\Services\Learning\AuthorityFactory::create();
        $suggestionDTOs = $authority->getSuggestions($record['supplier_name']);

        $suggestions = array_map(function($dto) {
            return [
                'id' => $dto->supplier_id,
                'name' => $dto->official_name,
                'score' => $dto->confidence
            ];
        }, $suggestionDTOs);

        $supplierMatch = [
            'suggestions' => $suggestions,
            'score' => 0
        ];

        if (!empty($suggestions)) {
            $supplierMatch['score'] = $suggestions[0]['score'] ?? 0;
        }
    }
    
    // Get bank match - if decision exists, use it
    $bankName = '';
    $bankId = null;
    if ($decision) {
        $bankId = $decision->bankId ?? null;
        if ($bankId) {
            $bankStmt = $db->prepare("SELECT arabic_name FROM banks WHERE id = ?");
            $bankStmt->execute([$bankId]);
            $bankRow = $bankStmt->fetch(PDO::FETCH_ASSOC);
            $bankName = $bankRow['arabic_name'] ?? '';
        }
    }
    
    // Get supplier name from decision
    $supplierName = '';
    $supplierId = null;
    if ($decision) {
        $supplierId = $decision->supplierId ?? null;
        if ($supplierId) {
            $supplierStmt = $db->prepare("SELECT official_name FROM suppliers WHERE id = ?");
            $supplierStmt->execute([$supplierId]);
            $supplierRow = $supplierStmt->fetch(PDO::FETCH_ASSOC);
            $supplierName = $supplierRow['official_name'] ?? '';
        }
    }
    
    // Create current state snapshot
    $snapshot = [
        'supplier_name' => $supplierName,
        'supplier_id' => $supplierId,
        'bank_name' => $bankName,
        'bank_id' => $bankId,
        'amount' => $record['amount'],
        'expiry_date' => $record['expiry_date'],
        'issue_date' => $record['issue_date'],
        'guarantee_number' => $record['guarantee_number'],
        'contract_number' => $record['contract_number'],
        'type' => $record['type'],
        'status' => $decision->status ?? 'pending',
        'raw_supplier_name' => $raw['supplier'] ?? '' // Fallback for unmatched guarantees
    ];
    
    // Return success with snapshot data
    $response = [
        'success' => true,
        'snapshot' => $snapshot,
        'latest_event_subtype' => $latestSubtype // Send to frontend
    ];

    if ($supplierMatch !== null) {
        $response['supplierMatch'] = $supplierMatch;
    }

    echo json_encode($response);
    
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'خطأ في السيرفر: ' . $e->getMessage()
    ]);
}
