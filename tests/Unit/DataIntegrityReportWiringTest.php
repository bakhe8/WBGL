<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DataIntegrityReportWiringTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = realpath(__DIR__ . '/../../') ?: dirname(__DIR__, 2);
    }

    public function testScriptAndCiArtifactWiringArePresent(): void
    {
        $script = $this->readFile('app/Scripts/data-integrity-check.php');
        $ci = $this->readFile('.github/workflows/ci.yml');
        $doc = $this->readFile('Docs/DATA-INTEGRITY-REPORT-AR.md');

        $this->assertStringContainsString('WBGL Data Integrity Checker', $script);
        $this->assertStringContainsString('--output-json', $script);
        $this->assertStringContainsString('--output-md', $script);
        $this->assertStringContainsString('--strict-warn', $script);
        $this->assertStringContainsString('DECISION_STATUS_DOMAIN', $script);
        $this->assertStringContainsString('ROLE_PERMISSION_ORPHANS', $script);

        $this->assertStringContainsString('Run governance reports (strict mode optional)', $ci);
        $this->assertStringContainsString('data-integrity-report.json', $ci);
        $this->assertStringContainsString('data-integrity-report.md', $ci);
        $this->assertStringContainsString('wbgl-governance-artifacts', $ci);

        $this->assertStringContainsString('تقرير سلامة البيانات', $doc);
        $this->assertStringContainsString('data-integrity-check.php', $doc);
    }

    private function readFile(string $relativePath): string
    {
        $path = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $this->assertFileExists($path, 'Missing required file: ' . $relativePath);
        return (string)file_get_contents($path);
    }
}
