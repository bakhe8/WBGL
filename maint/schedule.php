<?php
declare(strict_types=1);

/**
 * WBGL lightweight scheduler runner.
 *
 * Usage:
 *   php maint/schedule.php
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Services\SchedulerDeadLetterService;
use App\Services\SchedulerJobCatalog;
use App\Services\SchedulerRuntimeService;

$jobs = SchedulerJobCatalog::all();

$selectedJob = null;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--job=')) {
        $selectedJob = trim(substr($arg, 6));
    }
}

$failed = 0;
foreach ($jobs as $job) {
    $name = (string)$job['name'];
    $path = (string)$job['path'];
    $maxAttempts = (int)($job['max_attempts'] ?? 1);

    if ($selectedJob !== null && $selectedJob !== '' && $selectedJob !== $name) {
        continue;
    }

    if (!is_file($path)) {
        echo "[SKIP] {$name} (missing script)\n";
        continue;
    }

    echo "[RUN] {$name} (max_attempts={$maxAttempts})\n";
    $result = SchedulerRuntimeService::runJob($name, $path, $maxAttempts);
    if (($result['status'] ?? '') === 'skipped_running') {
        echo "[SKIP] {$name} (already running)\n";
        continue;
    }
    if (($result['status'] ?? '') === 'missing_script') {
        echo "[SKIP] {$name} (missing script)\n";
        continue;
    }

    if (!($result['success'] ?? false)) {
        $failed++;
        $attempts = (int)($result['attempts'] ?? 0);
        $exitCode = $result['exit_code'] ?? 'n/a';
        $message = (string)($result['message'] ?? 'failed');
        $deadLetterId = $result['dead_letter_id'] ?? null;
        $deadLetterText = $deadLetterId ? " dead_letter_id={$deadLetterId}" : '';
        echo "[FAIL] {$name} attempts={$attempts} exit_code={$exitCode}{$deadLetterText} message={$message}\n";
    } else {
        $attempts = (int)($result['attempts'] ?? 1);
        echo "[OK] {$name} attempts={$attempts}\n";
    }
}

if ($failed > 0) {
    exit(1);
}

try {
    $openDeadLetters = SchedulerDeadLetterService::countOpen();
    if ($openDeadLetters > 0) {
        echo "[WARN] open_dead_letters={$openDeadLetters}\n";
    }
} catch (Throwable $e) {
    // Ignore diagnostics errors
}

exit(0);
