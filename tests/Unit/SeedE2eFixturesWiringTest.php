<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SeedE2eFixturesWiringTest extends TestCase
{
    public function testSeedScriptForcesE2eGuaranteesAsTestData(): void
    {
        $scriptPath = dirname(__DIR__, 2) . '/app/Scripts/seed-e2e-fixtures.php';
        $this->assertFileExists($scriptPath);

        $script = (string)file_get_contents($scriptPath);

        $this->assertStringContainsString(
            'is_test_data, test_batch_id, test_note',
            $script,
            'E2E seed must persist explicit test classification metadata.'
        );
        $this->assertStringContainsString(
            "VALUES (?, ?::json, ?, ?, ?, 1, ?, ?)",
            $script,
            'E2E seed must force is_test_data=1 at insert path.'
        );
        $this->assertStringContainsString(
            "'test_batch_id' => \$batchIdentifier",
            $script,
            'E2E seed payload must carry test_batch_id for traceability.'
        );
        $this->assertStringContainsString(
            "'test_note' => 'seeded_e2e_fixture'",
            $script,
            'E2E seed payload must carry a deterministic test note.'
        );
    }
}
