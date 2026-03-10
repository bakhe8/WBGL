<?php

declare(strict_types=1);

namespace Tests;

use App\Models\GuaranteeDecision;
use App\Services\WorkflowAdvanceTransitionService;
use App\Services\WorkflowService;
use PHPUnit\Framework\TestCase;

final class WorkflowAdvanceTransitionServiceTest extends TestCase
{
    public function testApplyFinalizesReleaseAfterSigning(): void
    {
        $decision = new GuaranteeDecision(
            id: 1,
            guaranteeId: 10,
            status: 'ready',
            activeAction: 'release',
            workflowStep: WorkflowService::STAGE_APPROVED
        );

        $result = WorkflowAdvanceTransitionService::apply($decision, WorkflowService::STAGE_SIGNED);

        $this->assertSame('signed', $result['workflow_step']);
        $this->assertSame('released', $result['status']);
        $this->assertTrue($result['release_finalized']);
    }

    public function testApplyDoesNotFinalizeNonReleaseActions(): void
    {
        $decision = new GuaranteeDecision(
            id: 2,
            guaranteeId: 11,
            status: 'ready',
            activeAction: 'extension',
            workflowStep: WorkflowService::STAGE_APPROVED
        );

        $result = WorkflowAdvanceTransitionService::apply($decision, WorkflowService::STAGE_SIGNED);

        $this->assertSame('signed', $result['workflow_step']);
        $this->assertSame('ready', $result['status']);
        $this->assertFalse($result['release_finalized']);
    }
}

