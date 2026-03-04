<?php

/**
 * API Endpoint: Workflow Advance
 * Progresses a guarantee to the next workflow stage
 */

require_once __DIR__ . '/_bootstrap.php';

use App\Support\Database;
use App\Repositories\GuaranteeDecisionRepository;
use App\Services\WorkflowService;
use App\Services\TimelineRecorder;

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

    // 4. Permission & Logical Check via WorkflowService
    if (!WorkflowService::canAdvance($decision)) {
        wbgl_api_compat_fail(403, 'Permission Denied', [
            'message' => 'ليس لديك الصلاحية لاعتماد هذه المرحلة أو أن الضمان ليس في الحالة الصحيحة.',
            'required_permission' => 'workflow_advance',
            'current_step' => $currentStep,
            'reason_code' => 'WORKFLOW_ADVANCE_DENIED',
            'policy' => $policy,
            'surface' => $surface,
            'reasons' => $policy['reasons'] ?? [],
        ]);
    }

    // 5. Determine Next Step
    $nextStep = WorkflowService::getNextStage($currentStep);
    if (!$nextStep) {
        wbgl_api_compat_fail(200, 'No further stages available', [
            'required_permission' => 'workflow_advance',
            'current_step' => $currentStep,
            'reason_code' => 'NO_NEXT_STAGE',
            'policy' => $policy,
            'surface' => $surface,
            'reasons' => $policy['reasons'] ?? [],
        ], 'conflict');
    }

    // Special Handling: Signatures (if multiple required)
    if ($nextStep === WorkflowService::STAGE_SIGNED) {
        $oldSnapshot = TimelineRecorder::createSnapshot($guaranteeId);
        $decision->signaturesReceived++;
        if ($decision->signaturesReceived < WorkflowService::signaturesRequired()) {
            // Stay in APPROVED stage but record signature
            $decisionRepo->createOrUpdate($decision);
            TimelineRecorder::recordWorkflowEvent($guaranteeId, $currentStep, "signature_received_" . $decision->signaturesReceived, $userName, $oldSnapshot);
            wbgl_api_compat_success([
                'message' => 'تم تسجيل التوقيع بنجاح. بانتظار بقية التواقيع.',
                'workflow_step' => $currentStep,
                'policy' => $policy,
                'surface' => $surface,
                'reasons' => $policy['reasons'] ?? [],
            ]);
        }
    }

    // 6. Execute Transition
    $oldStep = $decision->workflowStep;
    $oldSnapshot = TimelineRecorder::createSnapshot($guaranteeId);
    $decision->workflowStep = $nextStep;

    // Update decision in DB
    $decisionRepo->createOrUpdate($decision);

    // 5. Record Event
    TimelineRecorder::recordWorkflowEvent(
        $guaranteeId,
        $oldStep,
        $nextStep,
        $userName,
        $oldSnapshot
    );

    wbgl_api_compat_success([
        'message' => 'تم الانتقال بنجاح إلى مرحلة: ' . $nextStep,
        'workflow_step' => $nextStep,
        'policy' => $policy,
        'surface' => $surface,
        'reasons' => $policy['reasons'] ?? [],
    ]);
} catch (\Exception $e) {
    wbgl_api_compat_fail(500, $e->getMessage());
}
