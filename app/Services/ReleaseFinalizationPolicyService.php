<?php

declare(strict_types=1);

namespace App\Services;

final class ReleaseFinalizationPolicyService
{
    public static function shouldFinalizeOnTransition(string $nextStep, ?string $activeAction): bool
    {
        $normalizedStep = strtolower(trim($nextStep));
        $normalizedAction = strtolower(trim((string)$activeAction));

        return $normalizedStep === WorkflowService::STAGE_SIGNED
            && $normalizedAction === 'release';
    }
}

