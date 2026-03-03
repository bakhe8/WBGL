<?php

declare(strict_types=1);

use App\Services\ActionabilityPolicyService;
use PHPUnit\Framework\TestCase;

final class ActionabilityPolicyServiceTest extends TestCase
{
    public function testAllowedStagesSupportsWildcardPermission(): void
    {
        $allowed = ActionabilityPolicyService::allowedStages(['*']);
        $this->assertSame(
            ['draft', 'audited', 'analyzed', 'supervised', 'approved'],
            $allowed
        );
    }

    public function testAllowedStagesSupportsManageDataOverride(): void
    {
        $allowed = ActionabilityPolicyService::allowedStages(['manage_data']);
        $this->assertSame(
            ['draft', 'audited', 'analyzed', 'supervised', 'approved'],
            $allowed
        );
    }

    public function testBuildActionableSqlPredicateDeniesWhenNoStagePermission(): void
    {
        $predicate = ActionabilityPolicyService::buildActionableSqlPredicate(
            'd',
            null,
            [],
            'test_stage'
        );

        $this->assertStringContainsString('1=0', $predicate['sql']);
        $this->assertContains('NO_ACTIONABLE_STAGE_PERMISSION', $predicate['reasons']);
    }

    public function testBuildActionableSqlPredicateAddsStageFilterWhenAllowed(): void
    {
        $predicate = ActionabilityPolicyService::buildActionableSqlPredicate(
            'd',
            'draft',
            ['audit_data'],
            'test_stage'
        );

        $this->assertStringContainsString("d.status = 'ready'", $predicate['sql']);
        $this->assertStringContainsString('d.workflow_step IN (:test_stage_0)', $predicate['sql']);
        $this->assertStringContainsString('d.workflow_step = :test_stage_filter', $predicate['sql']);
        $this->assertSame('draft', $predicate['params']['test_stage_0']);
        $this->assertSame('draft', $predicate['params']['test_stage_filter']);
    }

    public function testEvaluateReturnsExecutableWhenAllConditionsMatch(): void
    {
        $decision = [
            'workflow_step' => 'draft',
            'status' => 'ready',
            'active_action' => null,
            'is_locked' => false,
        ];

        $result = ActionabilityPolicyService::evaluate($decision, true, ['audit_data']);

        $this->assertTrue($result->visible);
        $this->assertTrue($result->actionable);
        $this->assertTrue($result->executable);
        $this->assertSame([], $result->reasonCodes);
    }

    public function testEvaluateReturnsReasonsWhenBlocked(): void
    {
        $decision = [
            'workflow_step' => 'approved',
            'status' => 'pending',
            'active_action' => 'release',
            'is_locked' => true,
        ];

        $result = ActionabilityPolicyService::evaluate($decision, true, ['audit_data']);

        $this->assertTrue($result->visible);
        $this->assertFalse($result->actionable);
        $this->assertFalse($result->executable);
        $this->assertContains('LOCKED_RECORD', $result->reasonCodes);
        $this->assertContains('STATUS_NOT_READY', $result->reasonCodes);
        $this->assertContains('ACTIVE_ACTION_SET', $result->reasonCodes);
        $this->assertContains('STAGE_NOT_ALLOWED', $result->reasonCodes);
    }
}
