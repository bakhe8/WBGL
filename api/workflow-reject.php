<?php

declare(strict_types=1);

/**
 * API Endpoint: Workflow Reject
 * Rejects current workflow task and returns record to data-entry scope:
 * - workflow_step => draft
 * - active_action => null
 * - signatures_received => 0
 *
 * Requires mandatory rejection reason.
 */

require_once __DIR__ . '/_bootstrap.php';

use App\Repositories\GuaranteeDecisionRepository;
use App\Repositories\NoteRepository;
use App\Services\TimelineRecorder;
use App\Services\WorkflowService;
use App\Support\Database;

header('Content-Type: application/json; charset=utf-8');
wbgl_api_require_login();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

$guaranteeId = (int)($input['guarantee_id'] ?? 0);
$reason = trim((string)($input['reason'] ?? ''));

if ($guaranteeId <= 0) {
    wbgl_api_compat_fail(400, 'Missing guarantee_id');
}

if ($reason === '') {
    wbgl_api_compat_fail(422, 'سبب الرفض مطلوب');
}

wbgl_api_require_guarantee_visibility($guaranteeId);

try {
    $db = Database::connect();
    $decisionRepo = new GuaranteeDecisionRepository($db);
    $context = wbgl_api_policy_surface_for_guarantee($db, $guaranteeId);
    $policy = $context['policy'];
    $surface = $context['surface'];
    $actor = wbgl_api_current_user_display();

    $decision = $decisionRepo->findByGuarantee($guaranteeId);
    if (!$decision) {
        wbgl_api_compat_fail(404, 'No decision found for this guarantee', [
            'policy' => $policy,
            'surface' => $surface,
            'reasons' => $policy['reasons'] ?? [],
        ]);
    }

    if (!($surface['can_execute_actions'] ?? false)) {
        wbgl_api_compat_fail(403, 'Permission Denied', [
            'message' => 'لا يمكن رفض هذا السجل في حالته الحالية.',
            'reason_code' => 'SURFACE_NOT_GRANTED_CAN_EXECUTE_ACTIONS',
            'policy' => $policy,
            'surface' => $surface,
            'reasons' => $policy['reasons'] ?? [],
        ]);
    }

    $rejectPolicy = WorkflowService::canRejectWithReasons($decision);
    if (!($rejectPolicy['allowed'] ?? false)) {
        wbgl_api_compat_fail(403, 'Permission Denied', [
            'message' => 'ليس لديك صلاحية رفض هذه المرحلة أو أن السجل غير صالح للرفض.',
            'reason_code' => 'WORKFLOW_REJECT_DENIED',
            'workflow_reasons' => $rejectPolicy['reasons'] ?? [],
            'policy' => $policy,
            'surface' => $surface,
            'reasons' => $policy['reasons'] ?? [],
        ]);
    }

    $oldStep = (string)$decision->workflowStep;
    $oldAction = trim((string)$decision->activeAction);
    $beforeSnapshot = TimelineRecorder::createSnapshot($guaranteeId);

    $db->beginTransaction();
    try {
        // 1) Reset workflow state to data-entry handoff.
        $resetStmt = $db->prepare(
            "UPDATE guarantee_decisions
             SET workflow_step = 'draft',
                 signatures_received = 0,
                 active_action = NULL,
                 active_action_set_at = NULL,
                 last_modified_by = ?,
                 last_modified_at = CURRENT_TIMESTAMP
             WHERE guarantee_id = ?"
        );
        $resetStmt->execute([$actor, $guaranteeId]);

        // 2) Persist rejection note (mandatory reason).
        $noteRepo = new NoteRepository();
        $noteRepo->create([
            'guarantee_id' => $guaranteeId,
            'content' => 'رفض سير العمل: ' . $reason,
            'created_by' => $actor,
        ]);

        // 3) Timeline event for audit traceability.
        $afterSnapshot = TimelineRecorder::createSnapshot($guaranteeId);
        TimelineRecorder::recordStructuredEvent(
            $guaranteeId,
            'status_change',
            'workflow_reject',
            is_array($beforeSnapshot) ? $beforeSnapshot : [],
            [
                [
                    'field' => 'workflow_step',
                    'old_value' => $oldStep,
                    'new_value' => 'draft',
                    'trigger' => 'workflow_reject',
                ],
                [
                    'field' => 'active_action',
                    'old_value' => $oldAction !== '' ? $oldAction : null,
                    'new_value' => null,
                    'trigger' => 'workflow_reject',
                ],
            ],
            $actor,
            [
                'reason' => $reason,
                'action' => 'workflow_reject_to_data_entry',
            ],
            null,
            is_array($afterSnapshot) ? $afterSnapshot : []
        );

        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    wbgl_api_compat_success([
        'message' => 'تم رفض السجل وإعادته لمدخل البيانات بنجاح.',
        'workflow_step' => 'draft',
        'active_action' => null,
        'policy' => $policy,
        'surface' => $surface,
        'reasons' => $policy['reasons'] ?? [],
    ]);
} catch (\Throwable $e) {
    wbgl_api_compat_fail(500, $e->getMessage(), [], 'internal');
}

