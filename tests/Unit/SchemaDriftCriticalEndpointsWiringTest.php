<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SchemaDriftCriticalEndpointsWiringTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
    }

    public function testCreateGuaranteeUsesSharedOccurrenceRecorder(): void
    {
        $createApi = $this->readFile('api/create-guarantee.php');

        $this->assertStringContainsString('ImportService::recordOccurrence', $createApi);
        $this->assertStringNotContainsString(
            'guarantee_occurrences (guarantee_id, batch_identifier, import_source, occurred_at)',
            $createApi
        );
    }

    public function testOccurrenceRecorderResolvesCanonicalThenLegacyColumnAsFallback(): void
    {
        $importService = $this->readFile('app/Services/ImportService.php');

        $this->assertStringContainsString("if (in_array('batch_type', \$columns, true))", $importService);
        $this->assertStringContainsString("if (in_array('import_source', \$columns, true))", $importService);
    }

    public function testCommitBatchDraftDoesNotWriteLegacyStatusFlags(): void
    {
        $commitApi = $this->readFile('api/commit-batch-draft.php');

        $this->assertStringNotContainsString('SET guarantee_number = ?, status_flags = ?', $commitApi);
        $this->assertStringContainsString('UPDATE guarantees SET guarantee_number = ? WHERE id = ?', $commitApi);
    }

    public function testConvertToRealUsesRepositoryWithExplicitPdoConnection(): void
    {
        $convertApi = $this->readFile('api/convert-to-real.php');

        $this->assertStringContainsString('Database::connect()', $convertApi);
        $this->assertStringContainsString('new GuaranteeRepository($db)', $convertApi);
    }

    private function readFile(string $relativePath): string
    {
        $path = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $this->assertFileExists($path, 'Missing required file: ' . $relativePath);
        return (string) file_get_contents($path);
    }
}
