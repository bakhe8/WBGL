<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use PDO;
use Throwable;

class OperationalMetricsService
{
    /**
     * @return array<string,mixed>
     */
    public static function snapshot(): array
    {
        $db = Database::connect();
        $last24hSql = "NOW() - INTERVAL '1 day'";

        $counters = [
            'open_dead_letters' => self::count($db, 'scheduler_dead_letters', "status = 'open'"),
            'pending_undo_requests' => self::count($db, 'undo_requests', "status = 'pending'"),
            'approved_undo_requests' => self::count($db, 'undo_requests', "status = 'approved'"),
            'unread_notifications' => self::count($db, 'notifications', "is_read = 0"),
            'print_events_24h' => self::count(
                $db,
                'print_events',
                "created_at >= {$last24hSql}"
            ),
            'api_access_denied_24h' => self::count(
                $db,
                'audit_trail_events',
                "event_type = 'api_access_denied' AND created_at >= {$last24hSql}"
            ),
            'scheduler_failures_24h' => self::count(
                $db,
                'scheduler_job_runs',
                "status = 'failed' AND started_at >= {$last24hSql}"
            ),
        ];

        $latestSchedulerRun = self::latestSchedulerRun($db);

        return [
            'generated_at' => date('c'),
            'counters' => $counters,
            'scheduler' => [
                'latest' => $latestSchedulerRun,
            ],
        ];
    }

    private static function count(PDO $db, string $table, string $whereSql = '1=1'): int
    {
        if (!self::tableExists($db, $table)) {
            return 0;
        }

        try {
            $sql = "SELECT COUNT(*) FROM {$table} WHERE {$whereSql}";
            $stmt = $db->query($sql);
            return $stmt ? (int)$stmt->fetchColumn() : 0;
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function latestSchedulerRun(PDO $db): ?array
    {
        if (!self::tableExists($db, 'scheduler_job_runs')) {
            return null;
        }

        try {
            $stmt = $db->query(
                "SELECT id, run_token, job_name, status, exit_code, duration_ms, started_at, finished_at
                 FROM scheduler_job_runs
                 ORDER BY id DESC
                 LIMIT 1"
            );
            $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
            return is_array($row) ? $row : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function tableExists(PDO $db, string $table): bool
    {
        try {
            $stmt = $db->prepare(
                "SELECT 1
                 FROM information_schema.tables
                 WHERE table_schema = 'public' AND table_name = ?
                 LIMIT 1"
            );
            $stmt->execute([$table]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}
