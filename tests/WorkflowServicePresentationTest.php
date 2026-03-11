<?php

declare(strict_types=1);

namespace Tests;

use App\Services\WorkflowService;
use PHPUnit\Framework\TestCase;

final class WorkflowServicePresentationTest extends TestCase
{
    public function testAdvanceableStagesExcludeSigned(): void
    {
        $this->assertSame(
            ['draft', 'audited', 'analyzed', 'supervised', 'approved'],
            WorkflowService::advanceableStages()
        );
    }

    public function testDescribeAdvanceDenialReasonsHumanizesKnownCodes(): void
    {
        $message = WorkflowService::describeAdvanceDenialReasons([
            'ACTIVE_ACTION_NOT_SET',
            'MISSING_PERMISSION_SIGN_LETTERS',
        ]);

        $this->assertSame(
            'لم يتم اختيار إجراء لهذا الضمان، لا تملك صلاحية تنفيذ المرحلة التالية',
            $message
        );
    }
}
