<?php

/**
 * API Endpoint: Workflow Advance
 * Progresses a guarantee to the next workflow stage
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\AuthService;
use App\Support\Database;
use App\Support\Guard;
use App\Repositories\GuaranteeDecisionRepository;
use App\Services\WorkflowService;
use App\Services\TimelineRecorder;

header('Content-Type: application/json; charset=utf-8');

// 1. Auth Check
if (!AuthService::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$user = AuthService::getCurrentUser();

// 2. Input Validation
$input = json_decode(file_get_contents('php://input'), true);
$guaranteeId = $input['guarantee_id'] ?? null;

if (!$guaranteeId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing guarantee_id']);
    exit;
}

try {
    $db = Database::connect();
    $decisionRepo = new GuaranteeDecisionRepository($db);

    // 3. Fetch current state
    $decision = $decisionRepo->findByGuarantee((int)$guaranteeId);
    if (!$decision) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'No decision found for this guarantee']);
        exit;
    }

    $currentStep = $decision->workflowStep;

    // 4. Permission & Logical Check via WorkflowService
    if (!WorkflowService::canAdvance($decision)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Permission Denied',
            'message' => 'ليس لديك الصلاحية لاعتماد هذه المرحلة أو أن الضمان ليس في الحالة الصحيحة.'
        ]);
        exit;
    }

    // 5. Determine Next Step
    $nextStep = WorkflowService::getNextStage($currentStep);
    if (!$nextStep) {
        echo json_encode(['success' => false, 'error' => 'No further stages available']);
        exit;
    }

    // Special Handling: Signatures (if multiple required)
    if ($nextStep === WorkflowService::STAGE_SIGNED) {
        $decision->signaturesReceived++;
        if ($decision->signaturesReceived < WorkflowService::signaturesRequired()) {
            // Stay in APPROVED stage but record signature
            $decisionRepo->createOrUpdate($decision);
            TimelineRecorder::recordWorkflowEvent($guaranteeId, $currentStep, "signature_received_" . $decision->signaturesReceived, $user->fullName);
            echo json_encode([
                'success' => true,
                'message' => 'تم تسجيل التوقيع بنجاح. بانتظار بقية التواقيع.',
                'workflow_step' => $currentStep
            ]);
            exit;
        }
    }

    // 6. Execute Transition
    $oldStep = $decision->workflowStep;
    $decision->workflowStep = $nextStep;

    // Update decision in DB
    $decisionRepo->createOrUpdate($decision);

    // 5. Record Event
    TimelineRecorder::recordWorkflowEvent(
        $guaranteeId,
        $oldStep,
        $nextStep,
        $user->fullName
    );

    echo json_encode([
        'success' => true,
        'message' => 'تم الانتقال بنجاح إلى مرحلة: ' . $nextStep,
        'workflow_step' => $nextStep
    ]);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
