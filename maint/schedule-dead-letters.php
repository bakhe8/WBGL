<?php
declare(strict_types=1);

/**
 * Scheduler dead letters maintenance command.
 *
 * Usage:
 *   php maint/schedule-dead-letters.php list --status=open --limit=50
 *   php maint/schedule-dead-letters.php resolve --id=12 --note="accepted manually"
 *   php maint/schedule-dead-letters.php retry --id=12
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Services\SchedulerDeadLetterService;

$action = $argv[1] ?? 'list';
$id = 0;
$limit = 50;
$status = 'open';
$note = null;

foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--id=')) {
        $id = (int)substr($arg, 5);
    } elseif (str_starts_with($arg, '--limit=')) {
        $limit = (int)substr($arg, 8);
    } elseif (str_starts_with($arg, '--status=')) {
        $status = trim(substr($arg, 9));
    } elseif (str_starts_with($arg, '--note=')) {
        $note = trim(substr($arg, 7));
    }
}

try {
    if ($action === 'list') {
        $rows = SchedulerDeadLetterService::list($limit, $status);
        echo "Scheduler Dead Letters ({$status})\n";
        echo str_repeat('-', 100) . "\n";
        if (empty($rows)) {
            echo "No dead letters found.\n";
            exit(0);
        }
        foreach ($rows as $row) {
            echo sprintf(
                "[%d] job=%s status=%s attempts=%d/%d exit=%s run=%s created=%s\n",
                (int)($row['id'] ?? 0),
                (string)($row['job_name'] ?? ''),
                (string)($row['status'] ?? ''),
                (int)($row['attempts'] ?? 0),
                (int)($row['max_attempts'] ?? 0),
                $row['exit_code'] !== null ? (string)$row['exit_code'] : '-',
                (string)($row['run_token'] ?? ''),
                (string)($row['created_at'] ?? '')
            );
        }
        exit(0);
    }

    if ($action === 'resolve') {
        if ($id <= 0) {
            throw new RuntimeException('--id is required');
        }
        SchedulerDeadLetterService::resolve($id, 'cli_operator', $note);
        echo "Resolved dead letter #{$id}\n";
        exit(0);
    }

    if ($action === 'retry') {
        if ($id <= 0) {
            throw new RuntimeException('--id is required');
        }
        $result = SchedulerDeadLetterService::retry($id, 'cli_operator');
        $retry = $result['retry_result'] ?? [];
        $statusText = (bool)($retry['success'] ?? false) ? 'success' : 'failed';
        $message = (string)($retry['message'] ?? '');
        echo "Retried dead letter #{$id} => {$statusText} {$message}\n";
        exit((bool)($retry['success'] ?? false) ? 0 : 1);
    }

    throw new RuntimeException('Unknown action. Use: list | resolve | retry');
} catch (Throwable $e) {
    fwrite(STDERR, 'schedule-dead-letters failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
