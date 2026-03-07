<?php

declare(strict_types=1);

use App\Support\ApiTokenRateLimiter;
use PHPUnit\Framework\TestCase;

final class ApiTokenRateLimiterTest extends TestCase
{
    private string $originalIp = '';
    private string $originalUa = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $this->originalUa = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        $_SERVER['REMOTE_ADDR'] = '10.20.30.40';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit/ApiTokenRateLimiterTest';

        $this->setEnv('WBGL_API_TOKEN_RATE_LIMIT_MAX_ATTEMPTS', null);
        $this->setEnv('WBGL_API_TOKEN_RATE_LIMIT_WINDOW_SECONDS', null);
        $this->setEnv('WBGL_API_TOKEN_RATE_LIMIT_LOCKOUT_SECONDS', null);
        $this->setEnv('WBGL_API_TOKEN_RATE_LIMIT_ALLOWLIST_IPS', null);
        $this->setEnv('WBGL_API_TOKEN_RATE_LIMIT_ALLOWLIST_TOKEN_PREFIXES', null);
    }

    protected function tearDown(): void
    {
        $_SERVER['REMOTE_ADDR'] = $this->originalIp;
        $_SERVER['HTTP_USER_AGENT'] = $this->originalUa;

        $this->setEnv('WBGL_API_TOKEN_RATE_LIMIT_MAX_ATTEMPTS', null);
        $this->setEnv('WBGL_API_TOKEN_RATE_LIMIT_WINDOW_SECONDS', null);
        $this->setEnv('WBGL_API_TOKEN_RATE_LIMIT_LOCKOUT_SECONDS', null);
        $this->setEnv('WBGL_API_TOKEN_RATE_LIMIT_ALLOWLIST_IPS', null);
        $this->setEnv('WBGL_API_TOKEN_RATE_LIMIT_ALLOWLIST_TOKEN_PREFIXES', null);
        parent::tearDown();
    }

    public function testBlocksAfterConfiguredFailures(): void
    {
        $this->setEnv('WBGL_API_TOKEN_RATE_LIMIT_MAX_ATTEMPTS', '3');
        $token = 'wbgl_pat_' . bin2hex(random_bytes(16));
        ApiTokenRateLimiter::clear($token);

        for ($i = 0; $i < 3; $i++) {
            ApiTokenRateLimiter::recordFailure($token);
        }

        $blocked = ApiTokenRateLimiter::check($token);
        $this->assertFalse($blocked['allowed']);
        $this->assertGreaterThan(0, (int)$blocked['retry_after']);
        $this->assertSame(3, (int)$blocked['limit']);
        $this->assertFalse((bool)$blocked['allowlisted']);

        ApiTokenRateLimiter::clear($token);
    }

    public function testAllowlistIpBypassesLimiter(): void
    {
        $this->setEnv('WBGL_API_TOKEN_RATE_LIMIT_MAX_ATTEMPTS', '2');
        $this->setEnv('WBGL_API_TOKEN_RATE_LIMIT_ALLOWLIST_IPS', '10.20.30.40');

        $token = 'wbgl_pat_' . bin2hex(random_bytes(16));
        ApiTokenRateLimiter::clear($token);

        for ($i = 0; $i < 5; $i++) {
            $failure = ApiTokenRateLimiter::recordFailure($token);
            $this->assertFalse($failure['locked']);
            $this->assertTrue((bool)$failure['allowlisted']);
        }

        $check = ApiTokenRateLimiter::check($token);
        $this->assertTrue($check['allowed']);
        $this->assertTrue((bool)$check['allowlisted']);

        ApiTokenRateLimiter::clear($token);
    }

    public function testAllowlistTokenPrefixBypassesLimiter(): void
    {
        $this->setEnv('WBGL_API_TOKEN_RATE_LIMIT_MAX_ATTEMPTS', '2');
        $this->setEnv('WBGL_API_TOKEN_RATE_LIMIT_ALLOWLIST_TOKEN_PREFIXES', 'wbgl_pat_allow_');

        $token = 'wbgl_pat_allow_zzzzzzzzzzzzzzzzzzzzzzzzzzzzzz';
        ApiTokenRateLimiter::clear($token);

        $failure = ApiTokenRateLimiter::recordFailure($token);
        $this->assertFalse($failure['locked']);
        $this->assertTrue((bool)$failure['allowlisted']);

        $check = ApiTokenRateLimiter::check($token);
        $this->assertTrue($check['allowed']);
        $this->assertTrue((bool)$check['allowlisted']);
        $this->assertNotNull($check['token_fingerprint']);

        ApiTokenRateLimiter::clear($token);
    }

    private function setEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            return;
        }
        putenv($key . '=' . $value);
    }
}
