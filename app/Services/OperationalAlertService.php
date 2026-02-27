<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Settings;

class OperationalAlertService
{
    /**
     * @param array<string,mixed>|null $metricsSnapshot
     * @return array<string,mixed>
     */
    public static function evaluate(?array $metricsSnapshot = null): array
    {
        $snapshot = $metricsSnapshot ?? OperationalMetricsService::snapshot();
        $counters = is_array($snapshot['counters'] ?? null) ? $snapshot['counters'] : [];
        $settings = Settings::getInstance();

        $rules = [
            [
                'code' => 'api_denied_spike_24h',
                'label' => 'ارتفاع رفض الوصول للـ API خلال 24 ساعة',
                'counter' => 'api_access_denied_24h',
                'threshold' => (int)$settings->get('OBS_ALERT_API_DENIED_SPIKE_24H', 25),
                'severity' => 'high',
            ],
            [
                'code' => 'dead_letter_growth',
                'label' => 'عدد رسائل Dead Letter المفتوحة تجاوز الحد',
                'counter' => 'open_dead_letters',
                'threshold' => (int)$settings->get('OBS_ALERT_OPEN_DEAD_LETTERS', 5),
                'severity' => 'high',
            ],
            [
                'code' => 'scheduler_failures_24h',
                'label' => 'ارتفاع فشل المهام المجدولة خلال 24 ساعة',
                'counter' => 'scheduler_failures_24h',
                'threshold' => (int)$settings->get('OBS_ALERT_SCHEDULER_FAILURES_24H', 3),
                'severity' => 'medium',
            ],
            [
                'code' => 'pending_undo_requests',
                'label' => 'تراكم طلبات Undo المعلقة',
                'counter' => 'pending_undo_requests',
                'threshold' => (int)$settings->get('OBS_ALERT_PENDING_UNDO_REQUESTS', 10),
                'severity' => 'medium',
            ],
        ];

        $alerts = [];
        $triggeredCount = 0;

        foreach ($rules as $rule) {
            $value = (int)($counters[$rule['counter']] ?? 0);
            $threshold = max(0, (int)$rule['threshold']);
            $triggered = $value >= $threshold;
            if ($triggered) {
                $triggeredCount++;
            }

            $alerts[] = [
                'code' => $rule['code'],
                'label' => $rule['label'],
                'severity' => $rule['severity'],
                'counter' => $rule['counter'],
                'value' => $value,
                'threshold' => $threshold,
                'status' => $triggered ? 'triggered' : 'ok',
            ];
        }

        $staleHours = (int)$settings->get('OBS_ALERT_SCHEDULER_STALE_HOURS', 24);
        $latest = is_array($snapshot['scheduler']['latest'] ?? null)
            ? $snapshot['scheduler']['latest']
            : null;

        $staleTriggered = true;
        $hoursSinceLatest = null;
        if (is_array($latest) && !empty($latest['started_at'])) {
            $latestTs = strtotime((string)$latest['started_at']);
            if ($latestTs !== false) {
                $hoursSinceLatest = (time() - $latestTs) / 3600;
                $staleTriggered = $hoursSinceLatest >= $staleHours;
            }
        }
        if ($staleTriggered) {
            $triggeredCount++;
        }

        $alerts[] = [
            'code' => 'scheduler_stale_run',
            'label' => 'توقف تشغيل Scheduler لفترة طويلة',
            'severity' => 'high',
            'counter' => 'scheduler_latest_run_age_hours',
            'value' => $hoursSinceLatest !== null ? round($hoursSinceLatest, 2) : null,
            'threshold' => $staleHours,
            'status' => $staleTriggered ? 'triggered' : 'ok',
        ];

        return [
            'generated_at' => date('c'),
            'summary' => [
                'total_rules' => count($alerts),
                'triggered' => $triggeredCount,
                'healthy' => $triggeredCount === 0,
            ],
            'alerts' => $alerts,
        ];
    }
}
