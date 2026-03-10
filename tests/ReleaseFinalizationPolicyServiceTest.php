<?php

declare(strict_types=1);

namespace Tests;

use App\Services\ReleaseFinalizationPolicyService;
use App\Services\WorkflowService;
use PHPUnit\Framework\TestCase;

final class ReleaseFinalizationPolicyServiceTest extends TestCase
{
    public function testReleaseIsFinalizedOnlyWhenSignedAndActionIsRelease(): void
    {
        $this->assertTrue(
            ReleaseFinalizationPolicyService::shouldFinalizeOnTransition(
                WorkflowService::STAGE_SIGNED,
                'release'
            )
        );

        $this->assertFalse(
            ReleaseFinalizationPolicyService::shouldFinalizeOnTransition(
                WorkflowService::STAGE_SIGNED,
                'extension'
            )
        );

        $this->assertFalse(
            ReleaseFinalizationPolicyService::shouldFinalizeOnTransition(
                WorkflowService::STAGE_APPROVED,
                'release'
            )
        );
    }
}

