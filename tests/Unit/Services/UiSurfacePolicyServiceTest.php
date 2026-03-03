<?php

declare(strict_types=1);

use App\Services\UiSurfacePolicyService;
use PHPUnit\Framework\TestCase;

final class UiSurfacePolicyServiceTest extends TestCase
{
    public function testInvisiblePolicyDeniesAllSurfaces(): void
    {
        $policy = [
            'visible' => false,
            'actionable' => false,
            'executable' => false,
            'reasons' => ['NOT_VISIBLE'],
        ];

        $grants = UiSurfacePolicyService::forGuarantee($policy, ['*'], 'ready');

        $this->assertFalse($grants['can_view_record']);
        $this->assertFalse($grants['can_view_identity']);
        $this->assertFalse($grants['can_view_timeline']);
        $this->assertFalse($grants['can_execute_actions']);
        $this->assertFalse($grants['can_view_preview']);
    }

    public function testVisibleButNotActionableIsReadOnly(): void
    {
        $policy = [
            'visible' => true,
            'actionable' => false,
            'executable' => false,
            'reasons' => ['STAGE_NOT_ALLOWED'],
        ];

        $grants = UiSurfacePolicyService::forGuarantee(
            $policy,
            ['timeline_view', 'notes_view', 'notes_create', 'attachments_view', 'attachments_upload'],
            'pending'
        );

        $this->assertTrue($grants['can_view_record']);
        $this->assertTrue($grants['can_view_timeline']);
        $this->assertTrue($grants['can_view_notes']);
        $this->assertTrue($grants['can_view_attachments']);
        $this->assertFalse($grants['can_create_notes']);
        $this->assertFalse($grants['can_upload_attachments']);
        $this->assertFalse($grants['can_execute_actions']);
        $this->assertFalse($grants['can_view_preview']);
    }

    public function testActionableButNotExecutableIsReadOnly(): void
    {
        $policy = [
            'visible' => true,
            'actionable' => true,
            'executable' => false,
            'reasons' => ['LOCKED_RECORD'],
        ];

        $grants = UiSurfacePolicyService::forGuarantee(
            $policy,
            ['timeline_view', 'notes_view', 'notes_create', 'attachments_view', 'attachments_upload'],
            'ready'
        );

        $this->assertTrue($grants['can_view_record']);
        $this->assertTrue($grants['can_view_timeline']);
        $this->assertTrue($grants['can_view_notes']);
        $this->assertTrue($grants['can_view_attachments']);
        $this->assertFalse($grants['can_create_notes']);
        $this->assertFalse($grants['can_upload_attachments']);
        $this->assertFalse($grants['can_execute_actions']);
        $this->assertTrue($grants['can_view_preview']);
    }

    public function testExecutablePolicyEnablesMutatingSurfaces(): void
    {
        $policy = [
            'visible' => true,
            'actionable' => true,
            'executable' => true,
            'reasons' => [],
        ];

        $grants = UiSurfacePolicyService::forGuarantee(
            $policy,
            ['notes_view', 'notes_create', 'attachments_view', 'attachments_upload', 'timeline_view'],
            'ready'
        );

        $this->assertTrue($grants['can_view_record']);
        $this->assertTrue($grants['can_execute_actions']);
        $this->assertTrue($grants['can_create_notes']);
        $this->assertTrue($grants['can_upload_attachments']);
        $this->assertTrue($grants['can_view_preview']);
    }
}
