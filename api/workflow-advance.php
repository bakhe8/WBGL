<?php

/**
 * API Endpoint: Workflow Advance
 * Progresses a guarantee to the next workflow stage
 */

require_once __DIR__ . '/_bootstrap.php';

use App\Support\Database;
use App\Support\ConcurrencyConflictException;
use App\Support\GuaranteeDecisionConcurrencyGuard;
use App\Repositories\GuaranteeDecisionRepository;
use App\Services\WorkflowService;
use App\Services\WorkflowAdvanceExecutionService;

header('Content-Type: application/json; charset=utf-8');
wbgl_api_require_login();

// 2. Input Validation
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}
$guaranteeId = (int)($input['guarantee_id'] ?? 0);

if (!$guaranteeId) {
    wbgl_api_compat_fail(400, 'Missing guarantee_id');
}

wbgl_api_require_guarantee_visibility($guaranteeId);

try {
    $userName = wbgl_api_current_user_display();
    $db = Database::connect();
    $decisionRepo = new GuaranteeDecisionRepository($db);
    $context = wbgl_api_policy_surface_for_guarantee($db, (int)$guaranteeId);
    $policy = $context['policy'];
    $surface = $context['surface'];

    // 3. Fetch current state
    $decision = $decisionRepo->findByGuarantee((int)$guaranteeId);
    if (!$decision) {
        wbgl_api_compat_fail(404, 'No decision found for this guarantee', [
            'policy' => $policy,
            'surface' => $surface,
            'reasons' => $policy['reasons'] ?? [],
        ]);
    }

    $currentStep = $decision->workflowStep;

    if (!($surface['can_execute_actions'] ?? false)) {
        wbgl_api_compat_fail(403, 'Permission Denied', [
            'message' => 'لا يمكن تنفيذ إجراء سير العمل على هذا السجل في حالته الحالية.',
            'required_permission' => 'workflow_advance',
            'current_step' => $currentStep,
            'reason_code' => 'SURFACE_NOT_GRANTED_CAN_EXECUTE_ACTIONS',
            'policy' => $policy,
            'surface' => $surface,
            'reasons' => $policy['reasons'] ?? [],
        ]);
    }

    // 4. Permission & Domain Guard Check via WorkflowService
    $advancePolicy = WorkflowService::canAdvanceWithReasons($decision);
    if (!($advancePolicy['allowed'] ?? false)) {
        wbgl_api_compat_fail(403, 'Permission Denied', [
            'message' => 'ليس لديك الصلاحية لاعتماد هذه المرحلة أو أن الضمان ليس في الحالة الصحيحة.',
            'required_permission' => 'workflow_advance',
            'current_step' => $currentStep,
            'reason_code' => 'WORKFLOW_ADVANCE_DENIED',
            'workflow_reasons' => $advancePolicy['reasons'] ?? [],
            'policy' => $policy,
            'surface' => $surface,
            'reasons' => $policy['reasons'] ?? [],
        ]);
    }

    // 5. Determine Next Step
    $nextStep = WorkflowService::getNextStage($currentStep);
    if (!$nextStep) {
        wbgl_api_compat_fail(409, 'No further stages available', [
            'required_permission' => 'workflow_advance',
            'current_step' => $currentStep,
            'reason_code' => 'NO_NEXT_STAGE',
            'policy' => $policy,
            'surface' => $surface,
            'reasons' => $policy['reasons'] ?? [],
        ], 'conflict');
    }

    $expectedDecisionState = GuaranteeDecisionConcurrencyGuard::snapshot($db, (int)$guaranteeId);

    // 6. Execute transition/signature atomically under row lock.
    $executor = new WorkflowAdvanceExecutionService($db);
    $transitionResult = $executor->advance((int)$guaranteeId, $userName, $expectedDecisionState);

    if (($transitionResult['partial_signature'] ?? false) === true) {
        wbgl_api_compat_success([
            'message' => 'تم تسجيل التوقيع بنجاح. بانتظار بقية التواقيع.',
            'workflow_step' => (string)($transitionResult['workflow_step'] ?? $currentStep),
            'status' => (string)($transitionResult['status'] ?? $decision->status),
            'is_locked' => (bool)($transitionResult['is_locked'] ?? $decision->isLocked),
            'release_finalized' => false,
            'policy' => $policy,
            'surface' => $surface,
            'reasons' => $policy['reasons'] ?? [],
        ]);
    }

    $isReleaseFinalized = (bool)($transitionResult['release_finalized'] ?? false);
    $finalStep = (string)($transitionResult['next_step'] ?? $nextStep);
    $responseMessage = $isReleaseFinalized
        ? 'اكتمل التوقيع وتم الإفراج النهائي عن الضمان.'
        : ('تم الانتقال بنجاح إلى مرحلة: ' . $finalStep);

    wbgl_api_compat_success([
        'message' => $responseMessage,
        'workflow_step' => (string)($transitionResult['workflow_step'] ?? $finalStep),
        'status' => (string)($transitionResult['status'] ?? $decision->status),
        'is_locked' => (bool)($transitionResult['is_locked'] ?? $decision->isLocked),
        'release_finalized' => $isReleaseFinalized,
        'policy' => $policy,
        'surface' => $surface,
        'reasons' => $policy['reasons'] ?? [],
    ]);
} catch (ConcurrencyConflictException $e) {
    wbgl_api_compat_fail(409, $e->getMessage(), [
        'reason_code' => 'STALE_RECORD_CONFLICT',
        'conflict' => $e->context(),
        'policy' => isset($policy) ? $policy : [],
        'surface' => isset($surface) ? $surface : [],
        'reasons' => isset($policy['reasons']) && is_array($policy['reasons']) ? $policy['reasons'] : [],
    ], 'conflict');
} catch (\Throwable $e) {
    error_log('[WBGL_WORKFLOW_ADVANCE_ERROR] ' . $e->getMessage());
    wbgl_api_compat_fail(500, 'حدث خطأ داخلي أثناء تنفيذ انتقال المرحلة.', [], 'internal');
}
