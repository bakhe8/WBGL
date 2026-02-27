<?php
declare(strict_types=1);

use App\Services\OperationalMetricsService;
use PHPUnit\Framework\TestCase;

final class OperationalMetricsServiceTest extends TestCase
{
    public function testSnapshotReturnsOperationalCountersAndSchedulerBlock(): void
    {
        $snapshot = OperationalMetricsService::snapshot();

        $this->assertIsArray($snapshot);
        $this->assertArrayHasKey('generated_at', $snapshot);
        $this->assertNotSame('', (string)$snapshot['generated_at']);

        $this->assertArrayHasKey('counters', $snapshot);
        $this->assertIsArray($snapshot['counters']);

        $requiredCounters = [
            'open_dead_letters',
            'pending_undo_requests',
            'approved_undo_requests',
            'unread_notifications',
            'print_events_24h',
            'api_access_denied_24h',
            'scheduler_failures_24h',
        ];

        foreach ($requiredCounters as $key) {
            $this->assertArrayHasKey($key, $snapshot['counters']);
            $this->assertIsInt($snapshot['counters'][$key]);
            $this->assertGreaterThanOrEqual(0, $snapshot['counters'][$key]);
        }

        $this->assertArrayHasKey('scheduler', $snapshot);
        $this->assertIsArray($snapshot['scheduler']);
        $this->assertArrayHasKey('latest', $snapshot['scheduler']);
    }
}
