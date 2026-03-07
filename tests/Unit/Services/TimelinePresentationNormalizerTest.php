<?php

declare(strict_types=1);

use App\Services\TimelinePresentationNormalizer;
use PHPUnit\Framework\TestCase;

final class TimelinePresentationNormalizerTest extends TestCase
{
    public function testActorFromLegacyCreatorParsesIdentityFields(): void
    {
        $event = [
            'created_by' => 'مدير النظام (id:1 | @admin | admin@example.com)',
        ];

        $actor = TimelinePresentationNormalizer::actorFromEvent($event);

        $this->assertSame('user', $actor['kind']);
        $this->assertSame('مدير النظام', $actor['display']);
        $this->assertSame(1, $actor['user_id']);
        $this->assertSame('admin', $actor['username']);
        $this->assertSame('admin@example.com', $actor['email']);
        $this->assertSame('👤', $actor['icon']);
    }

    public function testActorFromLegacyCreatorRecognizesSystemAlias(): void
    {
        $event = ['created_by' => 'بواسطة النظام'];
        $actor = TimelinePresentationNormalizer::actorFromEvent($event);

        $this->assertSame('system', $actor['kind']);
        $this->assertSame('system', $actor['display']);
        $this->assertSame('timeline.actor.system', $actor['i18n_key']);
    }

    public function testPresentValueNormalizesStatusAndWorkflowStep(): void
    {
        $status = TimelinePresentationNormalizer::presentValue('status', 'approved');
        $step = TimelinePresentationNormalizer::presentValue('workflow_step', 'audited');

        $this->assertSame('ready', $status['display']);
        $this->assertSame('timeline.status.ready', $status['i18n_key']);
        $this->assertSame('audited', $step['display']);
        $this->assertSame('timeline.workflow_step.audited', $step['i18n_key']);
    }

    public function testNormalizeChangeInjectsPresentationData(): void
    {
        $change = [
            'field' => 'active_action',
            'old_value' => null,
            'new_value' => 'release',
        ];

        $normalized = TimelinePresentationNormalizer::normalizeChange($change);

        $this->assertArrayHasKey('old_present', $normalized);
        $this->assertArrayHasKey('new_present', $normalized);
        $this->assertSame('timeline.value.empty', $normalized['old_present']['i18n_key']);
        $this->assertSame('timeline.action.release', $normalized['new_present']['i18n_key']);
    }
}
