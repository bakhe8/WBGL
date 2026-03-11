<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\GuaranteeDecisionRepository;
use App\Support\ConcurrencyConflictException;
use App\Support\Database;
use App\Support\GuaranteeDecisionConcurrencyGuard;
use App\Support\TransactionBoundary;
use PDO;

final class WorkflowAdvanceExecutionService
{
    private PDO $db;
    private GuaranteeDecisionRepository $decisionRepo;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connect();
        $this->decisionRepo = new GuaranteeDecisionRepository($this->db);
    }

    /**
     * @param array<string,mixed>|null $expectedDecisionState
     * @return array{
     *   partial_signature:bool,
     *   workflow_step:string,
     *   status:string,
     *   is_locked:bool,
     *   release_finalized:bool,
     *   next_step:string
     * }
     */
    public function advance(int $guaranteeId, string $userName, ?array $expectedDecisionState = null): array
    {
        if ($guaranteeId <= 0) {
            throw new \InvalidArgumentException('guarantee_id غير صالح');
        }

        $expectedState = $expectedDecisionState ?? GuaranteeDecisionConcurrencyGuard::snapshot($this->db, $guaranteeId);

        return TransactionBoundary::run($this->db, function () use (
            $guaranteeId,
            $userName,
            $expectedState
        ): array {
            $lockedDecisionState = GuaranteeDecisionConcurrencyGuard::lockSnapshot($this->db, $guaranteeId);
            GuaranteeDecisionConcurrencyGuard::assertExpectedSnapshot(
                $expectedState,
                $lockedDecisionState,
                ['status', 'workflow_step', 'active_action', 'signatures_received', 'is_locked']
            );

            $decision = $this->decisionRepo->findByGuarantee($guaranteeId);
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

            $oldStep = $decision->workflowStep;
            $oldSnapshot = TimelineRecorder::createSnapshot($guaranteeId);
            $requiredSignatures = max(1, WorkflowService::signaturesRequired());

            if ($resolvedNextStep === WorkflowService::STAGE_SIGNED) {
                $decision->signaturesReceived++;
                if ($decision->signaturesReceived < $requiredSignatures) {
                    $this->decisionRepo->createOrUpdate($decision);
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
                $finalizeStmt = $this->db->prepare("
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
                    $guaranteeId,
                ]);
                $decision->isLocked = true;
                $decision->lockedReason = 'released_after_signed_workflow';
            } else {
                $this->decisionRepo->createOrUpdate($decision);
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
    }
}
