<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class GovernancePolicyWiringTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = realpath(__DIR__ . '/../../../') ?: dirname(__DIR__, 3);
    }

    public function testBatchReopenGovernanceWiringIsPresent(): void
    {
        $batchApi = $this->readFile('api/batches.php');
        $batchService = $this->readFile('app/Services/BatchService.php');

        $this->assertStringContainsString("Guard::has('reopen_batch')", $batchApi);
        $this->assertStringContainsString('سبب إعادة فتح الدفعة مطلوب', $batchApi);
        $this->assertStringContainsString('BreakGlassService::authorizeAndRecord', $batchApi);

        $this->assertStringContainsString('BatchAuditService::record(', $batchService);
        $this->assertStringContainsString('batch_reopened', $batchService);
    }

    public function testGuaranteeReopenGovernanceWiringIsPresent(): void
    {
        $reopenApi = $this->readFile('api/reopen.php');

        $this->assertStringContainsString("Guard::has('reopen_guarantee')", $reopenApi);
        $this->assertStringContainsString('BreakGlassService::authorizeAndRecord', $reopenApi);
        $this->assertStringNotContainsString('ENFORCE_UNDO_REQUEST_WORKFLOW', $reopenApi);
        $this->assertStringContainsString('UndoRequestService::submit', $reopenApi);
    }

    public function testReleasedReadOnlyPolicyWiringIsPresent(): void
    {
        $extendApi = $this->readFile('api/extend.php');
        $reduceApi = $this->readFile('api/reduce.php');
        $updateGuaranteeApi = $this->readFile('api/update-guarantee.php');
        $saveAndNextApi = $this->readFile('api/save-and-next.php');
        $uploadAttachmentApi = $this->readFile('api/upload-attachment.php');

        $this->assertStringContainsString('GuaranteeMutationPolicyService::evaluate', $extendApi);
        $this->assertStringContainsString('GuaranteeMutationPolicyService::evaluate', $reduceApi);
        $this->assertStringContainsString('GuaranteeMutationPolicyService::evaluate', $updateGuaranteeApi);
        $this->assertStringContainsString('GuaranteeMutationPolicyService::evaluate', $saveAndNextApi);
        $this->assertStringContainsString('GuaranteeMutationPolicyService::evaluate', $uploadAttachmentApi);
    }

    private function readFile(string $relativePath): string
    {
        $path = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $this->assertFileExists($path, 'Missing required file: ' . $relativePath);
        return (string)file_get_contents($path);
    }
}
