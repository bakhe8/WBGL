<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class TransactionBoundaryWiringTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = realpath(__DIR__ . '/../../../') ?: dirname(__DIR__, 3);
    }

    public function testTransactionBoundaryHelperExists(): void
    {
        $helper = $this->readFile('app/Support/TransactionBoundary.php');

        $this->assertStringContainsString('final class TransactionBoundary', $helper);
        $this->assertStringContainsString('public static function run', $helper);
        $this->assertStringContainsString('$db->beginTransaction()', $helper);
        $this->assertStringContainsString('$db->commit()', $helper);
        $this->assertStringContainsString('$db->rollBack()', $helper);
    }

    public function testLifecycleCriticalFlowsUseUnifiedTransactionBoundary(): void
    {
        $extendApi = $this->readFile('api/extend.php');
        $reduceApi = $this->readFile('api/reduce.php');
        $releaseApi = $this->readFile('api/release.php');
        $saveAndNextService = $this->readFile('app/Services/SaveAndNextApplicationService.php');
        $undoService = $this->readFile('app/Services/UndoRequestService.php');

        $this->assertStringContainsString('TransactionBoundary::run', $extendApi);
        $this->assertStringContainsString('TransactionBoundary::run', $reduceApi);
        $this->assertStringContainsString('TransactionBoundary::run', $releaseApi);
        $this->assertStringContainsString('TransactionBoundary::run', $saveAndNextService);
        $this->assertStringContainsString('TransactionBoundary::run', $undoService);
    }

    private function readFile(string $relativePath): string
    {
        $path = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $this->assertFileExists($path, 'Missing required file: ' . $relativePath);
        return (string)file_get_contents($path);
    }
}

