<?php

declare(strict_types=1);

use App\Services\SchedulerRuntimeService;
use App\Support\Database;
use PHPUnit\Framework\TestCase;

final class SchedulerRuntimeServiceTest extends TestCase
{
    private array $jobNames = [];

    protected function setUp(): void
    {
        if (!$this->hasTable('scheduler_job_runs')) {
            $this->markTestSkipped('scheduler_job_runs table is not available');
        }
        if (!$this->hasTable('scheduler_dead_letters')) {
            $this->markTestSkipped('scheduler_dead_letters table is not available');
        }
    }

    protected function tearDown(): void
    {
        if (empty($this->jobNames)) {
            return;
        }
        $db = Database::connect();
        foreach ($this->jobNames as $jobName) {
            $db->prepare('DELETE FROM scheduler_job_runs WHERE job_name = ?')->execute([$jobName]);
            $db->prepare('DELETE FROM scheduler_dead_letters WHERE job_name = ?')->execute([$jobName]);
            $db->prepare("DELETE FROM notifications WHERE dedupe_key LIKE ?")->execute(["scheduler_failure:{$jobName}:%"]);
        }
        $this->jobNames = [];
    }

    public function testRunJobSuccessWritesSuccessRun(): void
    {
        $jobName = 'ut-scheduler-success-' . uniqid();
        $this->jobNames[] = $jobName;
        $script = $this->createTempScript('echo "ok"; exit(0);');

        try {
            $result = SchedulerRuntimeService::runJob($jobName, $script, 1);
            $this->assertTrue((bool)$result['success']);
            $this->assertSame('success', (string)$result['status']);
            $this->assertSame(1, (int)$result['attempts']);

            $db = Database::connect();
            $stmt = $db->prepare('SELECT status, exit_code FROM scheduler_job_runs WHERE job_name = ? ORDER BY id DESC LIMIT 1');
            $stmt->execute([$jobName]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $this->assertSame('success', (string)($row['status'] ?? ''));
            $this->assertSame('0', (string)($row['exit_code'] ?? ''));
        } finally {
            @unlink($script);
        }
    }

    public function testRunJobRetriesUntilFailure(): void
    {
        $jobName = 'ut-scheduler-fail-' . uniqid();
        $this->jobNames[] = $jobName;
        $script = $this->createTempScript('echo "fail"; exit(1);');

        try {
            $result = SchedulerRuntimeService::runJob($jobName, $script, 2);
            $this->assertFalse((bool)$result['success']);
            $this->assertSame('failed', (string)$result['status']);
            $this->assertSame(2, (int)$result['attempts']);

            $db = Database::connect();
            $stmt = $db->prepare('SELECT COUNT(*) FROM scheduler_job_runs WHERE job_name = ?');
            $stmt->execute([$jobName]);
            $this->assertSame('2', (string)$stmt->fetchColumn());

            $dead = $db->prepare('SELECT status, attempts, max_attempts FROM scheduler_dead_letters WHERE job_name = ? ORDER BY id DESC LIMIT 1');
            $dead->execute([$jobName]);
            $row = $dead->fetch(PDO::FETCH_ASSOC);
            $this->assertSame('open', (string)($row['status'] ?? ''));
            $this->assertSame('2', (string)($row['attempts'] ?? ''));
            $this->assertSame('2', (string)($row['max_attempts'] ?? ''));
        } finally {
            @unlink($script);
        }
    }

    public function testRunJobSkipsWhenActiveRunExists(): void
    {
        $jobName = 'ut-scheduler-active-' . uniqid();
        $this->jobNames[] = $jobName;

        $db = Database::connect();
        $stmt = $db->prepare(
            "INSERT INTO scheduler_job_runs
             (run_token, job_name, attempt, max_attempts, status, started_at)
             VALUES (?, ?, 1, 1, 'running', CURRENT_TIMESTAMP)"
        );
        $stmt->execute(['token-' . uniqid(), $jobName]);

        $script = $this->createTempScript('echo "ok"; exit(0);');
        try {
            $result = SchedulerRuntimeService::runJob($jobName, $script, 1);
            $this->assertFalse((bool)$result['success']);
            $this->assertSame('skipped_running', (string)$result['status']);
        } finally {
            @unlink($script);
        }
    }

    private function createTempScript(string $body): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wbgl_scheduler_ut_' . uniqid('', true) . '.php';
        $code = "<?php\n" . $body . "\n";
        file_put_contents($path, $code);
        return $path;
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
