<?php
declare(strict_types=1);

/**
 * Scheduler runtime status.
 *
 * Usage:
 *   php maint/schedule-status.php
 *   php maint/schedule-status.php --limit=100
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Services\SchedulerDeadLetterService;
use App\Services\SchedulerRuntimeService;

$limit = 50;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $parsed = (int)substr($arg, 8);
        if ($parsed > 0) {
            $limit = $parsed;
        }
    }
}

try {
    $openDeadLetters = SchedulerDeadLetterService::countOpen();
    $rows = SchedulerRuntimeService::listRecent($limit);
    echo "WBGL Scheduler Status (latest {$limit})\n";
    echo "Open dead letters: {$openDeadLetters}\n";
    echo str_repeat('-', 100) . "\n";
    if (empty($rows)) {
        echo "No scheduler runs found.\n";
        exit(0);
    }

    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        $jobName = (string)($row['job_name'] ?? '');
        $attempt = (int)($row['attempt'] ?? 0);
        $maxAttempts = (int)($row['max_attempts'] ?? 0);
        $status = (string)($row['status'] ?? '');
        $exitCode = $row['exit_code'] !== null ? (string)$row['exit_code'] : '-';
        $duration = $row['duration_ms'] !== null ? (string)$row['duration_ms'] . 'ms' : '-';
        $startedAt = (string)($row['started_at'] ?? '-');
        $finishedAt = (string)($row['finished_at'] ?? '-');

        echo "[{$id}] {$jobName} attempt={$attempt}/{$maxAttempts} status={$status} exit={$exitCode} duration={$duration}\n";
        echo "      started={$startedAt} finished={$finishedAt}\n";
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'schedule-status failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
