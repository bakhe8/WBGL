<?php

declare(strict_types=1);

namespace Tests;

use App\Services\WorkflowStageDisplayService;
use PHPUnit\Framework\TestCase;

final class WorkflowStageDisplayServiceTest extends TestCase
{
    public function testDraftWithoutActionUsesAwaitingActionSelectionLabel(): void
    {
        $result = WorkflowStageDisplayService::describe('draft', '', 'index.workflow.step');

        $this->assertSame('draft_without_action', $result['code']);
        $this->assertSame('index.workflow.step.draft_without_action', $result['key']);
        $this->assertSame('بانتظار اختيار الإجراء', $result['fallback_label']);
        $this->assertSame('badge-neutral', $result['class']);
    }

    public function testDraftWithActionKeepsAwaitingAuditLabel(): void
    {
        $result = WorkflowStageDisplayService::describe(' Draft ', ' release ', 'batch_detail.workflow.step');

        $this->assertSame('draft', $result['code']);
        $this->assertSame('batch_detail.workflow.step.draft', $result['key']);
        $this->assertSame('بانتظار التدقيق', $result['fallback_label']);
        $this->assertSame('badge-neutral', $result['class']);
    }

    public function testDescribeUsesTranslatorWhenProvided(): void
    {
        $result = WorkflowStageDisplayService::describe(
            'approved',
            'extension',
            'batch_detail.workflow.step',
            static fn(string $key, string $fallback): string => $key . '|' . $fallback
        );

        $this->assertSame('approved', $result['code']);
        $this->assertSame('batch_detail.workflow.step.approved|تم الاعتماد', $result['label']);
        $this->assertSame('badge-warning', $result['class']);
    }
}
