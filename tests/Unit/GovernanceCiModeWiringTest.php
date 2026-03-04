<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class GovernanceCiModeWiringTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = realpath(__DIR__ . '/../../') ?: dirname(__DIR__, 2);
    }

    public function testCiUsesOptionalStrictModeAndGeneratesGovernanceSummary(): void
    {
        $ci = $this->readFile('.github/workflows/ci.yml');
        $summaryScript = $this->readFile('app/Scripts/governance-summary.php');
        $doc = $this->readFile('Docs/GOVERNANCE-CI-MODE-AR.md');

        $this->assertStringContainsString('WBGL_GOVERNANCE_STRICT', $ci);
        $this->assertStringContainsString('Run governance reports (strict mode optional)', $ci);
        $this->assertStringContainsString('data-integrity-check.php --strict-warn', $ci);
        $this->assertStringContainsString('permissions-drift-report.php --strict', $ci);
        $this->assertStringContainsString('governance-summary.php', $ci);
        $this->assertStringContainsString('governance-summary.md', $ci);

        $this->assertStringContainsString('WBGL Governance Summary', $summaryScript);
        $this->assertStringContainsString('--drift', $summaryScript);
        $this->assertStringContainsString('--integrity', $summaryScript);
        $this->assertStringContainsString('--output-md', $summaryScript);

        $this->assertStringContainsString('WBGL_GOVERNANCE_STRICT', $doc);
        $this->assertStringContainsString('governance-summary.md', $doc);
    }

    private function readFile(string $relativePath): string
    {
        $path = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $this->assertFileExists($path, 'Missing required file: ' . $relativePath);
        return (string)file_get_contents($path);
    }
}
