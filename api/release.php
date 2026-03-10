<?php
/**
 * V3 API - Start Release Workflow
 *
 * IMPORTANT:
 * This endpoint does NOT finalize release anymore.
 * It only selects the "release" action and resets workflow to draft,
 * so the record must pass through audit/analysis/supervision/approval/sign.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../app/Services/TimelineRecorder.php';

use App\Repositories\GuaranteeDecisionRepository;
use App\Support\ConcurrencyConflictException;
use App\Services\GuaranteeMutationPolicyService;
use App\Support\Database;
use App\Support\Guard;
use App\Support\Input;
use App\Support\GuaranteeDecisionConcurrencyGuard;
use App\Services\TimelineRecorder;

wbgl_api_require_login();
$policyContext = null;
$surface = null;

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }
    
    $guaranteeId = Input::int($input, 'guarantee_id');
    $reason = Input::string($input, 'reason', '') ?: null; // Optional
    $decidedBy = Input::string($input, 'decided_by', wbgl_api_current_user_display());
    
    if (!$guaranteeId) {
        throw new \RuntimeException('Missing guarantee_id');
    }

    wbgl_api_require_guarantee_visibility((int)$guaranteeId);
    wbgl_api_require_permission('guarantee_release');

    // Initialize services
    $db = Database::connect();
    $context = wbgl_api_policy_surface_for_guarantee($db, (int)$guaranteeId);
    $policyContext = $context['policy'];
    $surface = $context['surface'];
    $decisionRepo = new GuaranteeDecisionRepository($db);
    $stepStmt = $db->prepare("
        SELECT status, workflow_step, active_action, is_locked, supplier_id, bank_id, signatures_received
        FROM guarantee_decisions
        WHERE guarantee_id = ?
        LIMIT 1
    ");
    $stepStmt->execute([$guaranteeId]);
    $decisionRow = $stepStmt->fetch(PDO::FETCH_ASSOC);
    $currentStep = (string)($decisionRow['workflow_step'] ?? 'unknown');

    // Allow action start for data_entry via manage_data, even if executable-surface is false.
    if (!($surface['can_execute_actions'] ?? false) && !Guard::has('manage_data')) {
        wbgl_api_compat_fail(403, 'Permission Denied', [
            'message' => 'لا يمكن اختيار إجراء الإفراج على هذا السجل في حالته الحالية.',
            'required_permission' => 'guarantee_release',
            'current_step' => $currentStep,
            'reason_code' => 'SURFACE_NOT_GRANTED_CAN_EXECUTE_ACTIONS',
            'policy' => $policyContext,
            'surface' => $surface,
            'reasons' => $policyContext['reasons'] ?? [],
        ]);
    }

    if (!is_array($decisionRow)) {
        throw new \RuntimeException('لا يوجد قرار لهذا الضمان.');
    }

    $policy = GuaranteeMutationPolicyService::evaluate(
        (int)$guaranteeId,
        $input,
        'guarantee_release',
        $decidedBy
    );
    $isBreakGlass = !empty($policy['break_glass']);
    if (!$policy['allowed']) {
        wbgl_api_compat_fail(403, 'released_read_only', [
            'message' => (string)($policy['reason'] ?? 'Operation is not allowed'),
            'required_permission' => 'guarantee_release',
            'current_step' => $currentStep,
            'reason_code' => 'MUTATION_POLICY_DENIED',
            'policy' => $policyContext,
            'surface' => $surface,
            'reasons' => $policyContext['reasons'] ?? [],
        ]);
    }

    // Lifecycle guards for starting an action from data-entry.
    $status = strtolower(trim((string)($decisionRow['status'] ?? 'pending')));
    $activeAction = strtolower(trim((string)($decisionRow['active_action'] ?? '')));
    $workflowStep = strtolower(trim((string)($decisionRow['workflow_step'] ?? 'draft')));
    $isLocked = (bool)($decisionRow['is_locked'] ?? false);
    $hasSupplier = !empty($decisionRow['supplier_id']);
    $hasBank = !empty($decisionRow['bank_id']);

    if ($isLocked && !$isBreakGlass) {
        throw new \RuntimeException('لا يمكن تنفيذ الإجراء: السجل مقفل.');
    }
    if ($status !== 'ready' && !$isBreakGlass) {
        throw new \RuntimeException('لا يمكن اختيار الإفراج قبل اكتمال بيانات الضمان (الحالة يجب أن تكون جاهز).');
    }
    if ((!$hasSupplier || !$hasBank) && !$isBreakGlass) {
        throw new \RuntimeException('لا يمكن اختيار الإفراج بدون تحديد المورد والبنك أولاً.');
    }
    if ($workflowStep !== 'draft' && !$isBreakGlass) {
        throw new \RuntimeException('لا يمكن تغيير الإجراء بعد بدء مسار الاعتماد. استخدم الرفض/الإرجاع أو إكمال المسار الحالي.');
    }
    if ($activeAction !== '' && !$isBreakGlass) {
        throw new \RuntimeException('الإجراء محدد مسبقًا لهذا الضمان. لا يمكن استبداله في هذه المرحلة.');
    }
    
    $expectedDecisionState = GuaranteeDecisionConcurrencyGuard::snapshot($db, (int)$guaranteeId);

    $mutation = \App\Support\TransactionBoundary::run($db, function () use (
        $db,
        $decisionRepo,
        $guaranteeId,
        $decidedBy,
        $reason,
        $expectedDecisionState
    ): array {
        $lockedDecisionState = GuaranteeDecisionConcurrencyGuard::lockSnapshot($db, (int)$guaranteeId);
        GuaranteeDecisionConcurrencyGuard::assertExpectedSnapshot(
            $expectedDecisionState,
            $lockedDecisionState,
            ['status', 'workflow_step', 'active_action', 'signatures_received', 'is_locked', 'supplier_id', 'bank_id']
        );

        // --------------------------------------------------------------------
        // STRICT TIMELINE DISCIPLINE: Snapshot -> Update -> Record
        // --------------------------------------------------------------------
        $oldSnapshot = TimelineRecorder::createSnapshot($guaranteeId);

        $decision = $decisionRepo->findByGuarantee($guaranteeId);
        if (!$decision || empty($decision->supplierId) || empty($decision->bankId)) {
            throw new \RuntimeException('لا يمكن تنفيذ الإفراج - يجب اختيار المورد والبنك أولاً');
        }

        $actionStmt = $db->prepare("
            UPDATE guarantee_decisions
            SET active_action = 'release',
                active_action_set_at = CURRENT_TIMESTAMP,
                workflow_step = 'draft',
                signatures_received = 0,
                decision_source = 'manual',
                decided_by = ?,
                last_modified_by = ?,
                last_modified_at = CURRENT_TIMESTAMP
            WHERE guarantee_id = ?
        ");
        $actionStmt->execute([$decidedBy, $decidedBy, $guaranteeId]);

        $changes = [];
        $previousAction = trim((string)($lockedDecisionState['active_action'] ?? ''));
        $previousStep = trim((string)($lockedDecisionState['workflow_step'] ?? 'draft'));
        $previousSignatures = (int)($lockedDecisionState['signatures_received'] ?? 0);

        $changes[] = [
            'field' => 'active_action',
            'old_value' => $previousAction === '' ? null : $previousAction,
            'new_value' => 'release',
            'trigger' => 'release_action',
        ];

        if ($previousStep !== 'draft') {
            $changes[] = [
                'field' => 'workflow_step',
                'old_value' => $previousStep,
                'new_value' => 'draft',
                'trigger' => 'release_action',
            ];
        }

        if ($previousSignatures !== 0) {
            $changes[] = [
                'field' => 'signatures_received',
                'old_value' => $previousSignatures,
                'new_value' => 0,
                'trigger' => 'release_action',
            ];
        }

        $afterSnapshot = TimelineRecorder::createSnapshot($guaranteeId);
        $details = $reason ? ['reason_text' => $reason] : [];
        TimelineRecorder::recordStructuredEvent(
            (int)$guaranteeId,
            'modified',
            'release',
            is_array($oldSnapshot) ? $oldSnapshot : [],
            $changes,
            $decidedBy,
            $details,
            null,
            is_array($afterSnapshot) ? $afterSnapshot : null
        );

        return [
            'status' => 'ready',
            'active_action' => 'release',
            'workflow_step' => 'draft',
            'signatures_received' => 0,
        ];
    });

    wbgl_api_compat_success([
        'message' => 'تم اختيار إجراء الإفراج بنجاح. انتقل السجل إلى مسار الاعتماد.',
        'guarantee_id' => (int)$guaranteeId,
        'status' => (string)($mutation['status'] ?? 'ready'),
        'active_action' => (string)($mutation['active_action'] ?? 'release'),
        'workflow_step' => (string)($mutation['workflow_step'] ?? 'draft'),
        'signatures_received' => (int)($mutation['signatures_received'] ?? 0),
        'policy' => $policyContext,
        'surface' => $surface,
        'reasons' => $policyContext['reasons'] ?? [],
    ]);
    
} catch (ConcurrencyConflictException $e) {
    wbgl_api_compat_fail(409, $e->getMessage(), [
        'reason_code' => 'STALE_RECORD_CONFLICT',
        'conflict' => $e->context(),
        'policy' => $policyContext,
        'surface' => $surface,
        'reasons' => is_array($policyContext) ? ($policyContext['reasons'] ?? []) : [],
    ], 'conflict');
} catch (\Throwable $e) {
    wbgl_api_compat_fail(400, $e->getMessage(), [
        'reason_code' => 'RELEASE_OPERATION_FAILED',
        'policy' => $policyContext,
        'surface' => $surface,
        'reasons' => is_array($policyContext) ? ($policyContext['reasons'] ?? []) : [],
    ], 'validation');
}
