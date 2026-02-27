<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use PDO;
use RuntimeException;

class SchedulerDeadLetterService
{
    public static function recordFailure(
        string $jobName,
        string $runToken,
        int $attempts,
        int $maxAttempts,
        ?int $exitCode,
        ?string $failureReason,
        ?string $errorText,
        ?string $outputText,
        ?int $lastRunId = null
    ): int {
        $jobName = trim($jobName);
        $runToken = trim($runToken);
        if ($jobName === '' || $runToken === '') {
            throw new RuntimeException('job_name and run_token are required');
        }

        $db = Database::connect();
        $existing = $db->prepare(
            "SELECT id FROM scheduler_dead_letters WHERE run_token = ? LIMIT 1"
        );
        $existing->execute([$runToken]);
        $existingId = $existing->fetchColumn();

        if ($existingId) {
            $stmt = $db->prepare(
                "UPDATE scheduler_dead_letters
                 SET attempts = ?,
                     max_attempts = ?,
                     exit_code = ?,
                     failure_reason = ?,
                     error_text = ?,
                     output_text = ?,
                     last_run_id = ?,
                     status = 'open',
                     resolution_note = NULL,
                     resolved_by = NULL,
                     resolved_at = NULL,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?"
            );
            $stmt->execute([
                max(0, $attempts),
                max(1, $maxAttempts),
                $exitCode,
                $failureReason,
                $errorText,
                $outputText,
                $lastRunId,
                (int)$existingId,
            ]);
            $deadLetterId = (int)$existingId;
        } else {
            $stmt = $db->prepare(
                "INSERT INTO scheduler_dead_letters
                 (job_name, run_token, last_run_id, attempts, max_attempts, exit_code, failure_reason, error_text, output_text, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
            );
            $stmt->execute([
                $jobName,
                $runToken,
                $lastRunId,
                max(0, $attempts),
                max(1, $maxAttempts),
                $exitCode,
                $failureReason,
                $errorText,
                $outputText,
            ]);
            $deadLetterId = (int)$db->lastInsertId();
        }

        try {
            NotificationService::create(
                'scheduler_failure',
                'فشل مهمة مجدولة',
                "فشلت المهمة {$jobName} بعد {$attempts} محاولة",
                null,
                [
                    'dead_letter_id' => $deadLetterId,
                    'job_name' => $jobName,
                    'run_token' => $runToken,
                    'attempts' => $attempts,
                    'max_attempts' => $maxAttempts,
                    'exit_code' => $exitCode,
                ],
                "scheduler_failure:{$jobName}:{$runToken}"
            );
        } catch (\Throwable $e) {
            // Non-blocking notification
        }

        return $deadLetterId;
    }

    public static function list(int $limit = 100, ?string $status = 'open'): array
    {
        $limit = max(1, min(500, $limit));
        $db = Database::connect();

        if ($status === null || trim($status) === '' || $status === 'all') {
            $stmt = $db->query(
                "SELECT id, job_name, run_token, last_run_id, attempts, max_attempts, exit_code, failure_reason, error_text, output_text, status, resolution_note, resolved_by, resolved_at, created_at, updated_at
                 FROM scheduler_dead_letters
                 ORDER BY id DESC
                 LIMIT {$limit}"
            );
            return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        }

        $status = trim($status);
        $stmt = $db->prepare(
            "SELECT id, job_name, run_token, last_run_id, attempts, max_attempts, exit_code, failure_reason, error_text, output_text, status, resolution_note, resolved_by, resolved_at, created_at, updated_at
             FROM scheduler_dead_letters
             WHERE status = ?
             ORDER BY id DESC
             LIMIT {$limit}"
        );
        $stmt->execute([$status]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function resolve(int $id, string $resolvedBy, ?string $note = null): void
    {
        $row = self::findById($id);
        if (!$row) {
            throw new RuntimeException('Dead letter not found');
        }

        $resolvedBy = trim($resolvedBy) !== '' ? trim($resolvedBy) : 'النظام';
        $stmt = Database::connect()->prepare(
            "UPDATE scheduler_dead_letters
             SET status = 'resolved',
                 resolution_note = ?,
                 resolved_by = ?,
                 resolved_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ?"
        );
        $stmt->execute([$note, $resolvedBy, $id]);
    }

    public static function retry(int $id, string $actedBy): array
    {
        $row = self::findById($id);
        if (!$row) {
            throw new RuntimeException('Dead letter not found');
        }
        if (($row['status'] ?? '') !== 'open') {
            throw new RuntimeException('Only open dead letters can be retried');
        }

        $jobName = (string)($row['job_name'] ?? '');
        $job = SchedulerJobCatalog::find($jobName);
        if (!$job) {
            throw new RuntimeException("Unknown scheduler job: {$jobName}");
        }

        $maxAttempts = (int)($job['max_attempts'] ?? 1);
        $result = SchedulerRuntimeService::runJob(
            $jobName,
            (string)$job['path'],
            $maxAttempts
        );

        $note = ($result['success'] ?? false)
            ? 'Retried successfully'
            : ('Retried and failed: ' . (string)($result['message'] ?? 'failed'));

        $actedBy = trim($actedBy) !== '' ? trim($actedBy) : 'النظام';
        $stmt = Database::connect()->prepare(
            "UPDATE scheduler_dead_letters
             SET status = 'retried',
                 resolution_note = ?,
                 resolved_by = ?,
                 resolved_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ?"
        );
        $stmt->execute([$note, $actedBy, $id]);

        return [
            'dead_letter_id' => $id,
            'job_name' => $jobName,
            'retry_result' => $result,
        ];
    }

    public static function countOpen(): int
    {
        $stmt = Database::connect()->query(
            "SELECT COUNT(*) FROM scheduler_dead_letters WHERE status = 'open'"
        );
        return $stmt ? (int)$stmt->fetchColumn() : 0;
    }

    public static function findById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $stmt = Database::connect()->prepare(
            "SELECT id, job_name, run_token, last_run_id, attempts, max_attempts, exit_code, failure_reason, error_text, output_text, status, resolution_note, resolved_by, resolved_at, created_at, updated_at
             FROM scheduler_dead_letters
             WHERE id = ?
             LIMIT 1"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
