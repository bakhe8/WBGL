<?php
/**
 * V3 API - Reduce Guarantee (Server-Driven Partial HTML)
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../app/Services/TimelineRecorder.php';

use App\Repositories\GuaranteeDecisionRepository;
use App\Repositories\GuaranteeRepository;
use App\Services\GuaranteeMutationPolicyService;
use App\Support\Database;
use App\Support\Guard;
use App\Support\Input;

wbgl_api_require_login();
$policyContext = null;
$surface = null;

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }
    
    $guaranteeId = Input::int($input, 'guarantee_id');
    $decidedBy = Input::string($input, 'decided_by', wbgl_api_current_user_display());
    $newAmountRaw = Input::string($input, 'new_amount', '');
    $newAmount = is_numeric($newAmountRaw) ? (float) $newAmountRaw : null;
    
    if (!$guaranteeId) {
        throw new \RuntimeException('Missing guarantee_id');
    }

    wbgl_api_require_guarantee_visibility((int)$guaranteeId);
    wbgl_api_require_permission('guarantee_reduce');
    
    if ($newAmount === null) {
        throw new \RuntimeException('المبلغ غير صحيح');
    }
    
    // Validate positive amount
    if ($newAmount <= 0) {
        throw new \RuntimeException('المبلغ يجب أن يكون أكبر من صفر');
    }
    
    // Initialize services
    $db = Database::connect();
    $context = wbgl_api_policy_surface_for_guarantee($db, (int)$guaranteeId);
    $policyContext = $context['policy'];
    $surface = $context['surface'];
    $stepStmt = $db->prepare("SELECT workflow_step FROM guarantee_decisions WHERE guarantee_id = ? LIMIT 1");
    $stepStmt->execute([$guaranteeId]);
    $currentStep = (string)($stepStmt->fetchColumn() ?: 'unknown');

    if (!($surface['can_execute_actions'] ?? false) && !Guard::has('manage_data')) {
        wbgl_api_compat_fail(403, 'Permission Denied', [
            'message' => 'لا يمكن تنفيذ التخفيض على هذا السجل في حالته الحالية.',
            'required_permission' => 'guarantee_reduce',
            'current_step' => $currentStep,
            'reason_code' => 'SURFACE_NOT_GRANTED_CAN_EXECUTE_ACTIONS',
            'policy' => $policyContext,
            'surface' => $surface,
            'reasons' => $policyContext['reasons'] ?? [],
        ]);
    }
    $decisionRepo = new GuaranteeDecisionRepository($db);
    $guaranteeRepo = new GuaranteeRepository($db);

    $policy = GuaranteeMutationPolicyService::evaluate(
        (int)$guaranteeId,
        $input,
        'guarantee_reduce',
        $decidedBy
    );
    $isBreakGlass = !empty($policy['break_glass']);
    if (!$policy['allowed']) {
        wbgl_api_compat_fail(403, 'released_read_only', [
            'message' => (string)($policy['reason'] ?? 'Operation is not allowed'),
            'required_permission' => 'guarantee_reduce',
            'current_step' => $currentStep,
            'reason_code' => 'MUTATION_POLICY_DENIED',
            'policy' => $policyContext,
            'surface' => $surface,
            'reasons' => $policyContext['reasons'] ?? [],
        ]);
    }
    
    // ===== CRITICAL FIX: Validate new amount is LESS than current amount =====
    $currentAmountCheck = $db->prepare("SELECT raw_data FROM guarantees WHERE id = ?");
    $currentAmountCheck->execute([$guaranteeId]);
    $guaranteeData = $currentAmountCheck->fetch(PDO::FETCH_ASSOC);
    
    if ($guaranteeData) {
        $rawData = json_decode($guaranteeData['raw_data'], true);
        $currentAmount = (float)($rawData['amount'] ?? 0);
        
        if ($newAmount >= $currentAmount) {
            throw new \RuntimeException('المبلغ الجديد يجب أن يكون أقل من المبلغ الحالي (' . number_format($currentAmount, 2) . ' ر.س)');
        }
    }
    // =========================================================================
    
    // ===== LIFECYCLE GATE: Prevent reduction on pending/locked guarantees =====
    $statusCheck = $db->prepare("
        SELECT status, is_locked, locked_reason
        FROM guarantee_decisions 
        WHERE guarantee_id = ?
    ");
    $statusCheck->execute([$guaranteeId]);
    $decision = $statusCheck->fetch(PDO::FETCH_ASSOC);
    
    if (!$decision) {
        throw new \RuntimeException('لا يوجد قرار لهذا الضمان.');
    }
    
    // Check if locked (released)
    if ($decision['is_locked'] && !$isBreakGlass) {
        throw new \RuntimeException(
            'لا يمكن تخفيض ضمان مُفرَج عنه. الضمان مقفل بسبب: ' .
            (string)($decision['locked_reason'] ?? 'غير محدد')
        );
    }
    
    // Check if ready
    if ($decision['status'] !== 'ready' && !$isBreakGlass) {
        throw new \RuntimeException('لا يمكن تخفيض ضمان غير مكتمل. يجب اختيار المورد والبنك أولاً.');
    }
    // ================================================================
    
    $mutation = \App\Support\TransactionBoundary::run($db, function () use ($db, $guaranteeRepo, $guaranteeId, $newAmount, $decidedBy): array {
        // --------------------------------------------------------------------
        // STRICT TIMELINE DISCIPLINE: Snapshot -> Update -> Record
        // --------------------------------------------------------------------
        $oldSnapshot = \App\Services\TimelineRecorder::createSnapshot($guaranteeId);

        $guarantee = $guaranteeRepo->find($guaranteeId);
        if (!$guarantee) {
            throw new \RuntimeException('Record not found');
        }

        $raw = $guarantee->rawData;
        $previousAmount = $raw['amount'] ?? 0;
        $raw['amount'] = (float)$newAmount;
        $guaranteeRepo->updateRawData($guaranteeId, json_encode($raw));

        $actionStmt = $db->prepare("
            UPDATE guarantee_decisions
            SET active_action = 'reduction',
                active_action_set_at = CURRENT_TIMESTAMP,
                workflow_step = 'draft',
                signatures_received = 0
            WHERE guarantee_id = ?
        ");
        $actionStmt->execute([$guaranteeId]);

        $decisionUpdate = $db->prepare("
            UPDATE guarantee_decisions
            SET decision_source = 'manual',
                decided_by = ?,
                last_modified_by = ?,
                last_modified_at = CURRENT_TIMESTAMP
            WHERE guarantee_id = ?
        ");
        $decisionUpdate->execute([$decidedBy, $decidedBy, $guaranteeId]);

        \App\Services\TimelineRecorder::recordReductionEvent(
            $guaranteeId,
            $oldSnapshot,
            (float)$newAmount,
            (float)$previousAmount
        );

        return [
            'guarantee' => $guarantee,
            'raw' => $raw,
        ];
    });

    /** @var \App\Models\Guarantee $guarantee */
    $guarantee = $mutation['guarantee'];
    $raw = is_array($mutation['raw'] ?? null) ? $mutation['raw'] : [];

    // Prepare record data for form
    $record = [
        'id' => $guarantee->id,
        'guarantee_number' => $guarantee->guaranteeNumber,
        'supplier_name' => $raw['supplier'] ?? '',
        'bank_name' => $raw['bank'] ?? '',
        'bank_id' => null,
        'amount' => (float)$newAmount,  // 🆕 Use parameter value
        'expiry_date' => $raw['expiry_date'] ?? '',
        'issue_date' => $raw['issue_date'] ?? '',
        'contract_number' => $raw['contract_number'] ?? '',
        'type' => $raw['type'] ?? 'Initial',
        'status' => 'reduced'
    ];
    
    // Get banks for dropdown
    $banksStmt = $db->query('SELECT id, arabic_name as official_name FROM banks ORDER BY arabic_name');
    $banks = $banksStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Include partial template
    ob_start();
    echo '<div id="record-form-section" class="decision-card" data-current-event-type="current">';
    include __DIR__ . '/../partials/record-form.php';
    echo '</div>';
    $html = (string)ob_get_clean();

    wbgl_api_compat_success([
        'html' => $html,
        'guarantee_id' => (int)$guaranteeId,
        'status' => 'reduced',
        'active_action' => 'reduction',
        'amount' => (float)$newAmount,
        'policy' => $policyContext,
        'surface' => $surface,
        'reasons' => $policyContext['reasons'] ?? [],
    ]);
    
} catch (\Throwable $e) {
    wbgl_api_compat_fail(400, $e->getMessage(), [
        'reason_code' => 'REDUCE_OPERATION_FAILED',
        'policy' => $policyContext,
        'surface' => $surface,
        'reasons' => is_array($policyContext) ? ($policyContext['reasons'] ?? []) : [],
    ], 'validation');
}
