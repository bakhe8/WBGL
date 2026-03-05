<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ReleaseGateWiringTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = realpath(__DIR__ . '/../../') ?: dirname(__DIR__, 2);
    }

    public function testReleaseGateScriptExistsAndValidatesStatus(): void
    {
        $path = $this->root . DIRECTORY_SEPARATOR
            . 'app' . DIRECTORY_SEPARATOR
            . 'Scripts' . DIRECTORY_SEPARATOR
            . 'release-gate.php';

        $this->assertFileExists($path);
        $content = (string)file_get_contents($path);

        $this->assertStringContainsString('data-integrity-report.json', $content);
        $this->assertStringContainsString('permissions-drift-report.json', $content);
        $this->assertStringContainsString('fail_violations', $content);
        $this->assertStringContainsString("['pass', 'warn']", $content);
        $this->assertStringContainsString('WBGL release gate passed.', $content);
    }

    public function testWorkflowsInvokeReleaseGate(): void
    {
        $ci = (string)file_get_contents(
            $this->root . DIRECTORY_SEPARATOR . '.github' . DIRECTORY_SEPARATOR . 'workflows' . DIRECTORY_SEPARATOR . 'ci.yml'
        );
        $releaseReadiness = (string)file_get_contents(
            $this->root . DIRECTORY_SEPARATOR . '.github' . DIRECTORY_SEPARATOR . 'workflows' . DIRECTORY_SEPARATOR . 'release-readiness.yml'
        );
        $changeGate = (string)file_get_contents(
            $this->root . DIRECTORY_SEPARATOR . '.github' . DIRECTORY_SEPARATOR . 'workflows' . DIRECTORY_SEPARATOR . 'change-gate.yml'
        );

        $this->assertStringContainsString('php app/Scripts/release-gate.php', $ci);
        $this->assertStringContainsString('php app/Scripts/release-gate.php', $releaseReadiness);
        $this->assertStringContainsString('php app/Scripts/release-gate.php', $changeGate);
    }
}
