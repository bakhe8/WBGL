<?php
/**
 * V3 API - Extend Guarantee (Server-Driven Partial HTML)
 * Returns HTML fragment for updated record section
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
    $decidedBy = Input::string($input, 'decided_by', 'web_user');
    
    if (!$guaranteeId) {
        throw new \RuntimeException('Missing guarantee_id');
    }

    wbgl_api_require_guarantee_visibility((int)$guaranteeId);
    wbgl_api_require_permission('guarantee_extend');
    
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
            'message' => 'لا يمكن تنفيذ التمديد على هذا السجل في حالته الحالية.',
            'required_permission' => 'guarantee_extend',
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
        'guarantee_extend',
        $decidedBy
    );
    $isBreakGlass = !empty($policy['break_glass']);
    if (!$policy['allowed']) {
        wbgl_api_compat_fail(403, 'released_read_only', [
            'message' => (string)($policy['reason'] ?? 'Operation is not allowed'),
            'required_permission' => 'guarantee_extend',
            'current_step' => $currentStep,
            'reason_code' => 'MUTATION_POLICY_DENIED',
            'policy' => $policyContext,
            'surface' => $surface,
            'reasons' => $policyContext['reasons'] ?? [],
        ]);
    }
    
    // ===== LIFECYCLE GATE: Prevent extension on pending guarantees =====
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
            'لا يمكن تمديد ضمان مُفرَج عنه. الضمان مقفل بسبب: ' .
            (string)($decision['locked_reason'] ?? 'غير محدد')
        );
    }
    
    // Check if ready
    if ($decision['status'] !== 'ready' && !$isBreakGlass) {
        throw new \RuntimeException('لا يمكن تمديد ضمان غير مكتمل. يجب اختيار المورد والبنك أولاً.');
    }
    // ================================================================
    
    // --------------------------------------------------------------------
    // STRICT TIMELINE DISCIPLINE: Snapshot -> Update -> Record
    // --------------------------------------------------------------------

    // 1. SNAPSHOT: Capture state BEFORE any modification
    $oldSnapshot = \App\Services\TimelineRecorder::createSnapshot($guaranteeId);
    
    // 2. UPDATE: Execute system changes
    // 🆕 Calculate new expiry date directly (always +1 year)
    $guarantee = $guaranteeRepo->find($guaranteeId);
    $raw = $guarantee->rawData;
    $oldExpiry = $raw['expiry_date'] ?? '';
    $newExpiry = date('Y-m-d', strtotime($oldExpiry . ' +1 year'));
    
    // Update source of truth (Raw Data)
    $raw['expiry_date'] = $newExpiry;
    
    // Update raw_data through repository
    $guaranteeRepo->updateRawData($guaranteeId, json_encode($raw));

    // 3. NEW (Phase 3): Set Active Action
    // Locked action setter re-enabled per user request to fix Batch Detail discrepancy
    $actionStmt = $db->prepare("
        UPDATE guarantee_decisions
        SET active_action = 'extension',
            active_action_set_at = CURRENT_TIMESTAMP
        WHERE guarantee_id = ?
    ");
    $actionStmt->execute([$guaranteeId]);

    
    // 3.1 Track manual decision source for user-triggered action
    $decisionUpdate = $db->prepare("
        UPDATE guarantee_decisions
        SET decision_source = 'manual',
            decided_by = ?,
            last_modified_by = ?,
            last_modified_at = CURRENT_TIMESTAMP
        WHERE guarantee_id = ?
    ");
    $decisionUpdate->execute([$decidedBy, $decidedBy, $guaranteeId]);

    // 4. RECORD: Strict Event Recording (UE-02 Extend)
    // 🆕 Record ONLY in guarantee_history (no guarantee_actions)
    \App\Services\TimelineRecorder::recordExtensionEvent(
        $guaranteeId, 
        $oldSnapshot, 
        $newExpiry
    );

    // --------------------------------------------------------------------
    
    // Prepare record data
    $record = [
        'id' => $guarantee->id,
        'guarantee_number' => $guarantee->guaranteeNumber,
        'supplier_name' => $raw['supplier'] ?? '',
        'bank_name' => $raw['bank'] ?? '',
        'bank_id' => null,
        'amount' => $raw['amount'] ?? 0,
        'expiry_date' => $newExpiry,  // 🆕 Use calculated value
        'issue_date' => $raw['issue_date'] ?? '',
        'contract_number' => $raw['contract_number'] ?? '',
        'type' => $raw['type'] ?? 'Initial',
        'status' => 'extended'
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
        'status' => 'extended',
        'active_action' => 'extension',
        'expiry_date' => $newExpiry,
        'policy' => $policyContext,
        'surface' => $surface,
        'reasons' => $policyContext['reasons'] ?? [],
    ]);
    
    
} catch (\Throwable $e) {
    wbgl_api_compat_fail(400, $e->getMessage(), [
        'reason_code' => 'EXTEND_OPERATION_FAILED',
        'policy' => $policyContext,
        'surface' => $surface,
        'reasons' => is_array($policyContext) ? ($policyContext['reasons'] ?? []) : [],
    ], 'validation');
}
