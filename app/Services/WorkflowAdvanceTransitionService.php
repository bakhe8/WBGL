<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\GuaranteeDecision;

final class WorkflowAdvanceTransitionService
{
    /**
     * Apply workflow transition side effects on decision model.
     *
     * @return array{workflow_step:string,status:string,release_finalized:bool}
     */
    public static function apply(GuaranteeDecision $decision, string $nextStep): array
    {
        $decision->workflowStep = $nextStep;
        $isReleaseFinalized = ReleaseFinalizationPolicyService::shouldFinalizeOnTransition(
            $nextStep,
            $decision->activeAction
        );

        if ($isReleaseFinalized) {
            $decision->status = 'released';
        }

        return [
            'workflow_step' => $decision->workflowStep,
            'status' => $decision->status,
            'release_finalized' => $isReleaseFinalized,
        ];
    }
}

