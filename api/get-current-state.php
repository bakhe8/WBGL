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

require_once __DIR__ . '/_bootstrap.php';

use App\Support\Database;
use App\Repositories\GuaranteeRepository;
use App\Repositories\GuaranteeDecisionRepository;
use App\Repositories\SupplierLearningRepository;
use App\Repositories\SupplierRepository;
use App\Repositories\BankRepository;
use App\Services\GuaranteeVisibilityService;

header('Content-Type: application/json; charset=utf-8');
wbgl_api_require_login();

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

if (!GuaranteeVisibilityService::canAccessGuarantee((int)$guaranteeId)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Permission Denied'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Connect to database
    $db = Database::connect();
    $guaranteeRepo = new GuaranteeRepository($db);
    $decisionRepo = new GuaranteeDecisionRepository($db);
    $learningRepo = new SupplierLearningRepository($db);
    $supplierRepo = new SupplierRepository();
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

    // Build record data
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
        'locked_reason' => null,
        // Phase 3: Workflow
        'workflow_step' => 'draft',
        'signatures_received' => 0
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
        // Phase 3: Workflow
        $record['workflow_step'] = $decision->workflowStep;
        $record['signatures_received'] = $decision->signaturesReceived;

        // Get official supplier name
        if ($decision->supplierId) {
            try {
                $supplier = $supplierRepo->find($decision->supplierId);
                if ($supplier) {
                    $record['supplier_name'] = $supplier->officialName;
                }
            } catch (\Exception $e) {
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
            }
        }
    }

    // Create current state snapshot
    $snapshot = [
        'supplier_name' => $record['supplier_name'],
        'supplier_id' => $record['supplier_id'],
        'bank_name' => $record['bank_name'],
        'bank_id' => $record['bank_id'],
        'amount' => $record['amount'],
        'expiry_date' => $record['expiry_date'],
        'issue_date' => $record['issue_date'],
        'guarantee_number' => $record['guarantee_number'],
        'contract_number' => $record['contract_number'],
        'type' => $record['type'],
        'status' => $record['status'],
        'raw_supplier_name' => $raw['supplier'] ?? '',
        // Phase 3: Workflow
        'workflow_step' => $record['workflow_step'],
        'signatures_received' => $record['signatures_received']
    ];

    echo json_encode([
        'success' => true,
        'snapshot' => $snapshot
    ]);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'خطأ في السيرفر: ' . $e->getMessage()
    ]);
}
