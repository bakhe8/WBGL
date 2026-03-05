<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class WorkflowTransitionGuardMigrationWiringTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = realpath(__DIR__ . '/../../') ?: dirname(__DIR__, 2);
    }

    public function testWorkflowGuardMigrationDeclaresTriggerAndTransitionRules(): void
    {
        $path = $this->root . DIRECTORY_SEPARATOR
            . 'database' . DIRECTORY_SEPARATOR
            . 'migrations' . DIRECTORY_SEPARATOR
            . '20260305_000026_enforce_workflow_transition_guards.sql';

        $this->assertFileExists($path, 'Workflow transition guard migration must exist.');

        $sql = (string) file_get_contents($path);

        $this->assertStringContainsString('CREATE OR REPLACE FUNCTION wbgl_enforce_decision_workflow_guards()', $sql);
        $this->assertStringContainsString('CREATE TRIGGER trg_wbgl_enforce_decision_workflow_guards', $sql);
        $this->assertStringContainsString('pending decision must stay in draft stage', $sql);
        $this->assertStringContainsString('invalid workflow transition % -> %', $sql);
        $this->assertStringContainsString("old_step = 'approved' AND new_step = 'signed'", $sql);
        $this->assertStringContainsString("IF new_step = 'draft' THEN", $sql);
    }
}
