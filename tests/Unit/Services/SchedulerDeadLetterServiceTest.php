<?php

declare(strict_types=1);

use App\Services\SchedulerDeadLetterService;
use App\Support\Database;
use PHPUnit\Framework\TestCase;

final class SchedulerDeadLetterServiceTest extends TestCase
{
    private array $runTokens = [];

    protected function setUp(): void
    {
        if (!$this->hasTable('scheduler_dead_letters')) {
            $this->markTestSkipped('scheduler_dead_letters table is not available');
        }
    }

    protected function tearDown(): void
    {
        if (empty($this->runTokens)) {
            return;
        }
        $db = Database::connect();
        foreach ($this->runTokens as $token) {
            $db->prepare('DELETE FROM scheduler_dead_letters WHERE run_token = ?')->execute([$token]);
            $db->prepare('DELETE FROM notifications WHERE dedupe_key LIKE ?')->execute(["scheduler_failure:%:{$token}"]);
        }
        $this->runTokens = [];
    }

    public function testRecordFailureAndResolveLifecycle(): void
    {
        $token = 'ut-dl-' . uniqid('', true);
        $this->runTokens[] = $token;

        $id = SchedulerDeadLetterService::recordFailure(
            'notify-expiry',
            $token,
            2,
            2,
            1,
            'Job failed after retries',
            'Exit code: 1',
            'output text',
            null
        );

        $this->assertGreaterThan(0, $id);

        $open = SchedulerDeadLetterService::list(20, 'open');
        $ids = array_map(static fn(array $row): int => (int)$row['id'], $open);
        $this->assertContains($id, $ids);

        SchedulerDeadLetterService::resolve($id, 'phpunit', 'accepted');

        $resolvedRow = SchedulerDeadLetterService::findById($id);
        $this->assertSame('resolved', (string)($resolvedRow['status'] ?? ''));
        $this->assertSame('phpunit', (string)($resolvedRow['resolved_by'] ?? ''));
    }

    private function hasTable(string $table): bool
    {
        $db = Database::connect();
        $stmt = $db->prepare(
            "SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = ? LIMIT 1"
        );
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    }
}
