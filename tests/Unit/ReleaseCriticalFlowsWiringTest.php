<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ReleaseCriticalFlowsWiringTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = realpath(__DIR__ . '/../../') ?: dirname(__DIR__, 2);
    }

    public function testPrintFlowWiringIsPresent(): void
    {
        $printApi = $this->readFile('api/print-events.php');
        $template = $this->readFile('templates/letter-template.php');
        $batchPrint = $this->readFile('views/batch-print.php');
        $previewApi = $this->readFile('api/get-letter-preview.php');

        $this->assertStringContainsString('wbgl_api_require_login();', $printApi);
        $this->assertStringContainsString('PrintAuditService::record', $previewApi);
        $this->assertStringContainsString('handleOverlayPrint', $template);
        $this->assertStringContainsString('recordBatchPrint', $batchPrint);
    }

    public function testHistoryTimeMachineHybridWiringIsPresent(): void
    {
        $recorder = $this->readFile('app/Services/TimelineRecorder.php');
        $snapshotApi = $this->readFile('api/get-history-snapshot.php');
        $recordsController = $this->readFile('public/js/records.controller.js');

        $this->assertStringContainsString('TimelineHybridLedger::buildHybridPayload', $recorder);
        $this->assertStringContainsString('TimelineHybridLedger::resolveEventSnapshot', $snapshotApi);
        $this->assertStringContainsString('/api/get-history-snapshot.php', $recordsController);
    }

    public function testUndoGovernanceWiringIsPresent(): void
    {
        $undoApi = $this->readFile('api/undo-requests.php');
        $reopenApi = $this->readFile('api/reopen.php');

        $this->assertStringContainsString("case 'submit':", $undoApi);
        $this->assertStringContainsString("case 'approve':", $undoApi);
        $this->assertStringContainsString("case 'reject':", $undoApi);
        $this->assertStringContainsString("case 'execute':", $undoApi);
        $this->assertStringContainsString('UndoRequestService::submit', $reopenApi);
        $this->assertStringNotContainsString('ENFORCE_UNDO_REQUEST_WORKFLOW', $reopenApi);
    }

    public function testSchedulerAndDeadLetterWiringIsPresent(): void
    {
        $scheduleRunner = $this->readFile('maint/schedule.php');
        $runtimeService = $this->readFile('app/Services/SchedulerRuntimeService.php');
        $deadLetterCommand = $this->readFile('maint/schedule-dead-letters.php');
        $deadLetterApi = $this->readFile('api/scheduler-dead-letters.php');

        $this->assertStringContainsString('SchedulerRuntimeService::runJob', $scheduleRunner);
        $this->assertStringContainsString('SchedulerDeadLetterService::recordFailure', $runtimeService);
        $this->assertStringContainsString("if (\$action === 'list')", $deadLetterCommand);
        $this->assertStringContainsString("if (\$action === 'resolve')", $deadLetterCommand);
        $this->assertStringContainsString("if (\$action === 'retry')", $deadLetterCommand);
        $this->assertStringContainsString("if (\$action === 'resolve')", $deadLetterApi);
        $this->assertStringContainsString("if (\$action === 'retry')", $deadLetterApi);
    }

    private function readFile(string $relativePath): string
    {
        $path = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $this->assertFileExists($path, 'Missing required file: ' . $relativePath);
        return (string)file_get_contents($path);
    }
}
