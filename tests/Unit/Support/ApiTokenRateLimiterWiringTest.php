<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ApiTokenRateLimiterWiringTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3);
    }

    public function testBootstrapUsesApiTokenRateLimiterForBearerAuth(): void
    {
        $bootstrap = $this->readFile('api/_bootstrap.php');
        $this->assertStringContainsString('use App\\Support\\ApiTokenRateLimiter;', $bootstrap);
        $this->assertStringContainsString('ApiTokenRateLimiter::check($token)', $bootstrap);
        $this->assertStringContainsString('ApiTokenRateLimiter::recordFailure($token)', $bootstrap);
        $this->assertStringContainsString('ApiTokenRateLimiter::clear($token)', $bootstrap);
    }

    public function testRateLimiterStateTableMigrationExists(): void
    {
        $sql = $this->readFile('database/migrations/20260307_000030_add_api_token_rate_limits.sql');
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS api_token_rate_limits', $sql);
        $this->assertStringContainsString('token_fingerprint', $sql);
        $this->assertStringContainsString('idx_api_token_rate_limits_locked_until', $sql);
    }

    private function readFile(string $relativePath): string
    {
        $path = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $this->assertFileExists($path, 'Missing required file: ' . $relativePath);
        return (string)file_get_contents($path);
    }
}
