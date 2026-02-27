<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SecurityBaselineWiringTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
    }

    public function testAutoloadWiresSessionHeadersAndCsrfGuards(): void
    {
        $autoload = $this->readFile('app/Support/autoload.php');

        $this->assertStringContainsString('SessionSecurity::configureSessionCookieOptions', $autoload);
        $this->assertStringContainsString('SessionSecurity::startSessionIfNeeded', $autoload);
        $this->assertStringContainsString('SessionSecurity::enforceTimeouts', $autoload);
        $this->assertStringContainsString('SecurityHeaders::apply', $autoload);
        $this->assertStringContainsString('CsrfGuard::publishCookie', $autoload);
        $this->assertStringContainsString('CsrfGuard::isMutatingMethod', $autoload);
    }

    public function testApiBootstrapEnforcesCsrfForMutatingRequests(): void
    {
        $bootstrap = $this->readFile('api/_bootstrap.php');

        $this->assertStringContainsString('function wbgl_api_require_csrf', $bootstrap);
        $this->assertStringContainsString('CsrfGuard::validateRequest', $bootstrap);
        $this->assertStringContainsString('CsrfGuard::isMutatingMethod', $bootstrap);
        $this->assertStringContainsString('wbgl_api_require_csrf();', $bootstrap);
    }

    public function testLoginEndpointRequiresCsrf(): void
    {
        $loginApi = $this->readFile('api/login.php');
        $this->assertStringContainsString('CsrfGuard::validateRequest', $loginApi);
    }

    public function testFrontendWiresGlobalFetchCsrfInjection(): void
    {
        $securityJs = $this->readFile('public/js/security.js');
        $header = $this->readFile('partials/unified-header.php');
        $loginView = $this->readFile('views/login.php');
        $usersView = $this->readFile('views/users.php');
        $batchPrintView = $this->readFile('views/batch-print.php');

        $this->assertStringContainsString('X-CSRF-Token', $securityJs);
        $this->assertStringContainsString('window.fetch = function', $securityJs);
        $this->assertStringContainsString('public/js/security.js', $header);
        $this->assertStringContainsString('/public/js/security.js', $loginView);
        $this->assertStringContainsString('../public/js/security.js', $usersView);
        $this->assertStringContainsString('/public/js/security.js', $batchPrintView);
    }

    public function testRateLimiterUsesUsernameIpAndUserAgentFingerprint(): void
    {
        $rateLimiter = $this->readFile('app/Support/LoginRateLimiter.php');
        $this->assertStringContainsString('self::clientIp() . \'|\' . self::clientUserAgent()', $rateLimiter);
    }

    private function readFile(string $relativePath): string
    {
        $path = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $this->assertFileExists($path, 'Missing required file: ' . $relativePath);
        return (string)file_get_contents($path);
    }
}
