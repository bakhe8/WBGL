<?php

/**
 * API Endpoint: Workflow Advance
 * Progresses a guarantee to the next workflow stage
 */

require_once __DIR__ . '/_bootstrap.php';

use App\Support\Database;
use App\Support\TransactionBoundary;
use App\Support\ConcurrencyConflictException;
use App\Support\GuaranteeDecisionConcurrencyGuard;
use App\Repositories\GuaranteeDecisionRepository;
use App\Services\WorkflowService;
use App\Services\TimelineRecorder;
use App\Services\WorkflowAdvanceTransitionService;

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
    $transitionResult = TransactionBoundary::run($db, function () use (
        $db,
        $decisionRepo,
        $guaranteeId,
        $nextStep,
        $userName,
        $expectedDecisionState
    ): array {
        $lockedDecisionState = GuaranteeDecisionConcurrencyGuard::lockSnapshot($db, (int)$guaranteeId);
        GuaranteeDecisionConcurrencyGuard::assertExpectedSnapshot(
            $expectedDecisionState,
            $lockedDecisionState,
            ['status', 'workflow_step', 'active_action', 'signatures_received', 'is_locked']
        );

        $decision = $decisionRepo->findByGuarantee((int)$guaranteeId);
        if (!$decision) {
            throw new \RuntimeException('No decision found for this guarantee');
        }

        $advancePolicy = WorkflowService::canAdvanceWithReasons($decision);
        if (!($advancePolicy['allowed'] ?? false)) {
            throw new ConcurrencyConflictException(
                'تم تعديل حالة السجل أثناء التنفيذ. أعد التحميل ثم أعد المحاولة.',
                ['workflow_reasons' => $advancePolicy['reasons'] ?? []]
            );
        }

        $resolvedNextStep = WorkflowService::getNextStage($decision->workflowStep);
        if (!$resolvedNextStep) {
            throw new ConcurrencyConflictException('لا توجد مرحلة تالية بعد الآن لهذا السجل.');
        }
        if ($resolvedNextStep !== $nextStep) {
            throw new ConcurrencyConflictException(
                'تم انتقال السجل إلى مرحلة مختلفة أثناء التنفيذ. أعد التحميل ثم أعد المحاولة.',
                [
                    'expected_next_step' => $nextStep,
                    'actual_next_step' => $resolvedNextStep,
                ]
            );
        }

        $oldStep = $decision->workflowStep;
        $oldSnapshot = TimelineRecorder::createSnapshot($guaranteeId);
        $requiredSignatures = max(1, WorkflowService::signaturesRequired());

        if ($resolvedNextStep === WorkflowService::STAGE_SIGNED) {
            $decision->signaturesReceived++;
            if ($decision->signaturesReceived < $requiredSignatures) {
                $decisionRepo->createOrUpdate($decision);
                TimelineRecorder::recordWorkflowEvent(
                    $guaranteeId,
                    $oldStep,
                    'signature_received_' . $decision->signaturesReceived,
                    $userName,
                    $oldSnapshot
                );

                return [
                    'partial_signature' => true,
                    'workflow_step' => $decision->workflowStep,
                    'status' => $decision->status,
                    'is_locked' => (bool)$decision->isLocked,
                    'release_finalized' => false,
                    'next_step' => $resolvedNextStep,
                ];
            }
        }

        $transitionOutcome = WorkflowAdvanceTransitionService::apply($decision, $resolvedNextStep);
        $isReleaseFinalization = (bool)$transitionOutcome['release_finalized'];

        if ($isReleaseFinalization) {
            $finalizeStmt = $db->prepare("
                UPDATE guarantee_decisions
                SET workflow_step = ?,
                    status = ?,
                    signatures_received = ?,
                    is_locked = TRUE,
                    locked_reason = 'released_after_signed_workflow',
                    last_modified_by = ?,
                    last_modified_at = CURRENT_TIMESTAMP
                WHERE guarantee_id = ?
            ");
            $finalizeStmt->execute([
                $decision->workflowStep,
                $decision->status,
                max($requiredSignatures, (int)$decision->signaturesReceived),
                $userName,
                (int)$guaranteeId,
            ]);
            $decision->isLocked = true;
            $decision->lockedReason = 'released_after_signed_workflow';
        } else {
            $decisionRepo->createOrUpdate($decision);
        }

        TimelineRecorder::recordWorkflowEvent(
            $guaranteeId,
            $oldStep,
            $resolvedNextStep,
            $userName,
            $oldSnapshot
        );

        if ($isReleaseFinalization) {
            TimelineRecorder::recordReleaseEvent(
                $guaranteeId,
                is_array($oldSnapshot) ? $oldSnapshot : [],
                'release_completed_after_sign'
            );
        }

        return [
            'partial_signature' => false,
            'workflow_step' => (string)$transitionOutcome['workflow_step'],
            'status' => (string)$transitionOutcome['status'],
            'is_locked' => $isReleaseFinalization ? true : (bool)$decision->isLocked,
            'release_finalized' => $isReleaseFinalization,
            'next_step' => $resolvedNextStep,
        ];
    });

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
