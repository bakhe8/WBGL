<?php
declare(strict_types=1);

use App\Services\OperationalAlertService;
use PHPUnit\Framework\TestCase;

final class OperationalAlertServiceTest extends TestCase
{
    public function testEvaluateReturnsHealthySummaryWhenCountersAreBelowThresholds(): void
    {
        $snapshot = [
            'counters' => [
                'api_access_denied_24h' => 0,
                'open_dead_letters' => 0,
                'scheduler_failures_24h' => 0,
                'pending_undo_requests' => 0,
            ],
            'scheduler' => [
                'latest' => [
                    'started_at' => date('Y-m-d H:i:s'),
                ],
            ],
        ];

        $result = OperationalAlertService::evaluate($snapshot);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertIsArray($result['summary']);
        $this->assertSame(0, (int)($result['summary']['triggered'] ?? -1));
        $this->assertTrue((bool)($result['summary']['healthy'] ?? false));
    }

    public function testEvaluateTriggersAllAlertsWhenCountersAndSchedulerAgeAreHigh(): void
    {
        $snapshot = [
            'counters' => [
                'api_access_denied_24h' => 999,
                'open_dead_letters' => 999,
                'scheduler_failures_24h' => 999,
                'pending_undo_requests' => 999,
            ],
            'scheduler' => [
                'latest' => [
                    'started_at' => date('Y-m-d H:i:s', strtotime('-3 days')),
                ],
            ],
        ];

        $result = OperationalAlertService::evaluate($snapshot);
        $alerts = is_array($result['alerts'] ?? null) ? $result['alerts'] : [];
        $triggered = array_values(array_filter(
            $alerts,
            static fn(array $row): bool => (string)($row['status'] ?? '') === 'triggered'
        ));

        $this->assertCount(5, $alerts);
        $this->assertCount(5, $triggered);
        $this->assertSame(5, (int)($result['summary']['triggered'] ?? -1));
        $this->assertFalse((bool)($result['summary']['healthy'] ?? true));
    }
}

