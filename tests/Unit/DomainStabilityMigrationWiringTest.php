<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DomainStabilityMigrationWiringTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
    }

    public function testP105MigrationDeclaresRequiredIndexesAndDomainConstraints(): void
    {
        $path = $this->root . DIRECTORY_SEPARATOR
            . 'database' . DIRECTORY_SEPARATOR
            . 'migrations' . DIRECTORY_SEPARATOR
            . '20260228_000017_add_domain_constraints_and_stability_indexes.sql';

        $this->assertFileExists($path, 'P1-05 migration file must exist.');

        $sql = (string) file_get_contents($path);

        $this->assertStringContainsString('idx_guarantee_occurrences_guarantee_batch', $sql);
        $this->assertStringContainsString('idx_guarantee_decisions_status_workflow_lock', $sql);
        $this->assertStringContainsString('idx_guarantee_history_gid_created_id', $sql);
        $this->assertStringContainsString('idx_undo_requests_status', $sql);

        $this->assertStringContainsString('chk_guarantees_is_test_data_domain', $sql);
        $this->assertStringContainsString('chk_guarantee_decisions_status_domain', $sql);
        $this->assertStringContainsString('chk_guarantee_decisions_workflow_step_domain', $sql);
        $this->assertStringContainsString('chk_guarantee_decisions_active_action_domain', $sql);
        $this->assertStringContainsString('chk_undo_requests_status_domain', $sql);
    }

    public function testA14SchemaHardeningMigrationDeclaresTypeAndConstraintUpgrades(): void
    {
        $path = $this->root . DIRECTORY_SEPARATOR
            . 'database' . DIRECTORY_SEPARATOR
            . 'migrations' . DIRECTORY_SEPARATOR
            . '20260307_000031_harden_schema_temporal_types_and_alias_constraints.sql';

        $this->assertFileExists($path, 'A14 migration file must exist.');

        $sql = (string) file_get_contents($path);

        $this->assertStringContainsString(
            'idx_supplier_alternative_names_supplier_normalized_unique',
            $sql
        );
        $this->assertStringContainsString('chk_guarantees_raw_data_object', $sql);
        $this->assertStringContainsString('ALTER COLUMN active_action_set_at TYPE TIMESTAMPTZ', $sql);
        $this->assertStringContainsString('ALTER COLUMN window_started_at TYPE TIMESTAMPTZ', $sql);
        $this->assertStringContainsString('ALTER COLUMN created_at TYPE TIMESTAMPTZ', $sql);
    }
}
