<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use PDO;
use RuntimeException;

class SchedulerRuntimeService
{
    private const STATUS_RUNNING = 'running';
    private const STATUS_SUCCESS = 'success';
    private const STATUS_FAILED = 'failed';

    public static function runJob(string $jobName, string $scriptPath, int $maxAttempts = 2): array
    {
        $jobName = trim($jobName);
        if ($jobName === '') {
            throw new RuntimeException('job_name is required');
        }

        if (!is_file($scriptPath)) {
            return [
                'success' => false,
                'status' => 'missing_script',
                'job_name' => $jobName,
                'attempts' => 0,
                'max_attempts' => 0,
                'run_token' => null,
                'exit_code' => null,
                'message' => 'Script file not found',
            ];
        }

        if (self::hasRecentRunningJob($jobName, 30)) {
            return [
                'success' => false,
                'status' => 'skipped_running',
                'job_name' => $jobName,
                'attempts' => 0,
                'max_attempts' => 0,
                'run_token' => null,
                'exit_code' => null,
                'message' => 'Job has an active running instance',
            ];
        }

        $maxAttempts = max(1, min(5, $maxAttempts));
        $runToken = self::generateRunToken($jobName);
        $command = PHP_BINARY . ' ' . escapeshellarg($scriptPath);
        $attempt = 0;
        $lastRunId = null;
        $lastExitCode = null;
        $lastMessage = '';
        $lastOutput = null;

        while ($attempt < $maxAttempts) {
            $attempt++;
            $startedAt = microtime(true);
            $runId = self::insertRunStart($runToken, $jobName, $attempt, $maxAttempts, $command);
            $lastRunId = $runId;

            [$outputText, $exitCode] = self::runShellCommand($command);
            $durationMs = (int)round((microtime(true) - $startedAt) * 1000);

            if ($exitCode === 0) {
                self::markRunFinished($runId, self::STATUS_SUCCESS, 0, $outputText, null, $durationMs);
                return [
                    'success' => true,
                    'status' => self::STATUS_SUCCESS,
                    'job_name' => $jobName,
                    'attempts' => $attempt,
                    'max_attempts' => $maxAttempts,
                    'run_token' => $runToken,
                    'run_id' => $runId,
                    'exit_code' => 0,
                    'message' => 'Job executed successfully',
                ];
            }

            $error = "Exit code: {$exitCode}";
            self::markRunFinished($runId, self::STATUS_FAILED, $exitCode, $outputText, $error, $durationMs);
            $lastExitCode = $exitCode;
            $lastMessage = $error;
            $lastOutput = $outputText;
        }

        $deadLetterId = null;
        try {
            $deadLetterId = SchedulerDeadLetterService::recordFailure(
                $jobName,
                $runToken,
                $attempt,
                $maxAttempts,
                $lastExitCode,
                'Job failed after retries',
                $lastMessage,
                $lastOutput,
                $lastRunId
            );
        } catch (\Throwable $e) {
            $deadLetterId = null;
        }

        return [
            'success' => false,
            'status' => self::STATUS_FAILED,
            'job_name' => $jobName,
            'attempts' => $attempt,
            'max_attempts' => $maxAttempts,
            'run_token' => $runToken,
            'run_id' => $lastRunId,
            'dead_letter_id' => $deadLetterId,
            'exit_code' => $lastExitCode,
            'message' => $lastMessage !== '' ? $lastMessage : 'Job failed after retries',
        ];
    }

    public static function listRecent(int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));
        $db = Database::connect();
        $stmt = $db->query(
            "SELECT id, run_token, job_name, attempt, max_attempts, status, exit_code, duration_ms, started_at, finished_at
             FROM scheduler_job_runs
             ORDER BY id DESC
             LIMIT {$limit}"
        );
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    public static function hasRecentRunningJob(string $jobName, int $windowMinutes = 30): bool
    {
        $jobName = trim($jobName);
        if ($jobName === '') {
            return false;
        }
        $windowMinutes = max(1, min(240, $windowMinutes));

        $db = Database::connect();
        $stmt = $db->prepare(
            "SELECT id
             FROM scheduler_job_runs
             WHERE job_name = ?
               AND status = ?
               AND CAST(started_at AS TIMESTAMP) >= (NOW() - (CAST(? AS INTEGER) * INTERVAL '1 minute'))
             ORDER BY id DESC
             LIMIT 1"
        );
        $stmt->execute([
            $jobName,
            self::STATUS_RUNNING,
            $windowMinutes,
        ]);
        return (bool)$stmt->fetchColumn();
    }

    private static function insertRunStart(string $runToken, string $jobName, int $attempt, int $maxAttempts, string $command): int
    {
        $db = Database::connect();
        $stmt = $db->prepare(
            "INSERT INTO scheduler_job_runs
             (run_token, job_name, attempt, max_attempts, status, command_text, started_at)
             VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)"
        );
        $stmt->execute([
            $runToken,
            $jobName,
            $attempt,
            $maxAttempts,
            self::STATUS_RUNNING,
            $command,
        ]);
        return (int)$db->lastInsertId();
    }

    private static function markRunFinished(
        int $runId,
        string $status,
        ?int $exitCode,
        ?string $outputText,
        ?string $errorText,
        ?int $durationMs
    ): void {
        $db = Database::connect();
        $stmt = $db->prepare(
            "UPDATE scheduler_job_runs
             SET status = ?,
                 exit_code = ?,
                 output_text = ?,
                 error_text = ?,
                 duration_ms = ?,
                 finished_at = CURRENT_TIMESTAMP
             WHERE id = ?"
        );
        $stmt->execute([
            $status,
            $exitCode,
            $outputText,
            $errorText,
            $durationMs,
            $runId,
        ]);
    }

    /**
     * @return array{0:string,1:int}
     */
    private static function runShellCommand(string $command): array
    {
        $lines = [];
        $code = 1;
        exec($command . ' 2>&1', $lines, $code);
        $output = trim(implode(PHP_EOL, $lines));
        return [$output, $code];
    }

    private static function generateRunToken(string $jobName): string
    {
        try {
            return $jobName . '-' . bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            return $jobName . '-' . str_replace('.', '', (string)microtime(true));
        }
    }
}
