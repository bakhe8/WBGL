<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PermissionsDriftReportWiringTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = realpath(__DIR__ . '/../../') ?: dirname(__DIR__, 2);
    }

    public function testScriptAndCiWiringArePresent(): void
    {
        $script = $this->readFile('app/Scripts/permissions-drift-report.php');
        $ci = $this->readFile('.github/workflows/ci.yml');
        $doc = $this->readFile('Docs/PERMISSIONS-DRIFT-REPORT-AR.md');

        $this->assertStringContainsString('WBGL Permissions Drift Report', $script);
        $this->assertStringContainsString('PermissionCapabilityCatalog::all()', $script);
        $this->assertStringContainsString('UiPolicy::capabilityMap()', $script);
        $this->assertStringContainsString('ApiPolicyMatrix::all()', $script);
        $this->assertStringContainsString('--output-json', $script);
        $this->assertStringContainsString('--output-md', $script);
        $this->assertStringContainsString('wbgl_critical_endpoint_contracts', $script);
        $this->assertStringContainsString('critical_endpoint_contract', $script);

        $this->assertStringContainsString('Run governance reports (strict mode optional)', $ci);
        $this->assertStringContainsString('app/Scripts/permissions-drift-report.php', $ci);
        $this->assertStringContainsString('Upload governance artifacts', $ci);
        $this->assertStringContainsString('wbgl-governance-artifacts', $ci);

        $this->assertStringContainsString('تقرير انحراف الصلاحيات', $doc);
        $this->assertStringContainsString('permissions-drift-report.php', $doc);
    }

    private function readFile(string $relativePath): string
    {
        $path = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $this->assertFileExists($path, 'Missing required file: ' . $relativePath);
        return (string)file_get_contents($path);
    }
}
