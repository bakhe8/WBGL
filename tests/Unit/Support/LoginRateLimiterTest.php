<?php

declare(strict_types=1);

use App\Support\LoginRateLimiter;
use PHPUnit\Framework\TestCase;

final class LoginRateLimiterTest extends TestCase
{
    public function testBlocksAfterFiveFailuresWithinWindow(): void
    {
        $username = 'rate_test_' . uniqid('', true);
        LoginRateLimiter::clear($username);

        $firstCheck = LoginRateLimiter::check($username);
        $this->assertTrue($firstCheck['allowed']);

        for ($i = 0; $i < 5; $i++) {
            LoginRateLimiter::recordFailure($username);
        }

        $blocked = LoginRateLimiter::check($username);
        $this->assertFalse($blocked['allowed']);
        $this->assertGreaterThan(0, (int)$blocked['retry_after']);

        LoginRateLimiter::clear($username);
    }

    public function testClearRemovesLockState(): void
    {
        $username = 'rate_test_reset_' . uniqid('', true);
        LoginRateLimiter::clear($username);

        for ($i = 0; $i < 5; $i++) {
            LoginRateLimiter::recordFailure($username);
        }
        $blocked = LoginRateLimiter::check($username);
        $this->assertFalse($blocked['allowed']);

        LoginRateLimiter::clear($username);
        $allowed = LoginRateLimiter::check($username);
        $this->assertTrue($allowed['allowed']);
        $this->assertSame(0, (int)$allowed['retry_after']);
    }

    public function testRateLimitKeyIncludesUserAgentFingerprint(): void
    {
        $username = 'rate_test_ua_' . uniqid('', true);
        $_SERVER['REMOTE_ADDR'] = '10.10.10.10';

        $_SERVER['HTTP_USER_AGENT'] = 'UA/One';
        LoginRateLimiter::clear($username);
        for ($i = 0; $i < 5; $i++) {
            LoginRateLimiter::recordFailure($username);
        }
        $blockedUaOne = LoginRateLimiter::check($username);
        $this->assertFalse($blockedUaOne['allowed']);

        $_SERVER['HTTP_USER_AGENT'] = 'UA/Two';
        LoginRateLimiter::clear($username);
        $allowedUaTwo = LoginRateLimiter::check($username);
        $this->assertTrue($allowedUaTwo['allowed']);

        $_SERVER['HTTP_USER_AGENT'] = 'UA/One';
        LoginRateLimiter::clear($username);
    }
}
