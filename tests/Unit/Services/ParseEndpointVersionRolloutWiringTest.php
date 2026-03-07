<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ParseEndpointVersionRolloutWiringTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3);
    }

    public function testFrontendUsesV2AsPrimaryAndV1AsFallback(): void
    {
        $controller = $this->readFile('public/js/input-modals.controller.js');

        $this->assertStringContainsString("sendParseRequest('/api/parse-paste-v2.php', 'ui-v2-primary')", $controller);
        $this->assertStringContainsString("sendParseRequest('/api/parse-paste.php', 'ui-v2-fallback-v1-status')", $controller);
        $this->assertStringContainsString("sendParseRequest('/api/parse-paste.php', 'ui-v2-fallback-v1-network')", $controller);
        $this->assertStringContainsString("'X-WBGL-Parse-Client': clientHint", $controller);
    }

    public function testLegacyEndpointIsVersionedShimWithDisableToggle(): void
    {
        $legacy = $this->readFile('api/parse-paste.php');

        $this->assertStringContainsString("wbgl_api_require_permission('import_excel');", $legacy);
        $this->assertStringContainsString("PARSE_PASTE_V1_ENABLED", $legacy);
        $this->assertStringContainsString("define('WBGL_PARSE_PASTE_REQUESTED_VERSION', 'v1')", $legacy);
        $this->assertStringContainsString("require __DIR__ . '/parse-paste-v2.php';", $legacy);
    }

    public function testV2EndpointEmitsUsageTelemetryForVersionMonitoring(): void
    {
        $v2 = $this->readFile('api/parse-paste-v2.php');
        $settings = $this->readFile('app/Support/Settings.php');
        $reportScript = $this->readFile('app/Scripts/parse-paste-usage-report.php');

        $this->assertStringContainsString('parse_paste_endpoint_usage', $v2);
        $this->assertStringContainsString('PARSE_PASTE_USAGE_AUDIT_ENABLED', $v2);
        $this->assertStringContainsString("'PARSE_PASTE_V1_ENABLED' => true", $settings);
        $this->assertStringContainsString("'PARSE_PASTE_V1_SAFE_THRESHOLD_PERCENT' => 5", $settings);
        $this->assertStringContainsString('Safe to retire V1:', $reportScript);
        $this->assertStringContainsString('parse_paste_endpoint_usage', $reportScript);
    }

    private function readFile(string $relativePath): string
    {
        $path = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $this->assertFileExists($path, 'Missing required file: ' . $relativePath);
        return (string)file_get_contents($path);
    }
}
