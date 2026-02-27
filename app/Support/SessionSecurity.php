<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Session security hardening for web requests.
 */
class SessionSecurity
{
    private const SESSION_CREATED_AT = '_session_created_at';
    private const SESSION_LAST_ACTIVITY_AT = '_session_last_activity_at';

    public static function configureSessionCookieOptions(string $sameSite = 'Lax'): void
    {
        if (php_sapi_name() === 'cli') {
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if (headers_sent()) {
            return;
        }

        $normalizedSameSite = self::normalizeSameSite($sameSite);
        $secureCookies = self::shouldUseSecureCookies();

        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.use_only_cookies', '1');
        @ini_set('session.cookie_httponly', '1');
        @ini_set('session.cookie_secure', $secureCookies ? '1' : '0');
        @ini_set('session.cookie_samesite', $normalizedSameSite);

        $params = session_get_cookie_params();
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => (string)($params['path'] ?? '/'),
            'domain' => (string)($params['domain'] ?? ''),
            'secure' => $secureCookies,
            'httponly' => true,
            'samesite' => $normalizedSameSite,
        ]);
    }

    public static function startSessionIfNeeded(): void
    {
        if (php_sapi_name() === 'cli') {
            return;
        }

        if (headers_sent()) {
            return;
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public static function enforceTimeouts(int $idleTimeoutSeconds, int $absoluteTimeoutSeconds): void
    {
        if (php_sapi_name() === 'cli') {
            return;
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        if (empty($_SESSION['user_id'])) {
            return;
        }

        $now = time();
        $createdAt = isset($_SESSION[self::SESSION_CREATED_AT]) ? (int)$_SESSION[self::SESSION_CREATED_AT] : $now;
        $lastActivityAt = isset($_SESSION[self::SESSION_LAST_ACTIVITY_AT]) ? (int)$_SESSION[self::SESSION_LAST_ACTIVITY_AT] : $now;

        $idleExpired = $idleTimeoutSeconds > 0 && ($now - $lastActivityAt) > $idleTimeoutSeconds;
        $absoluteExpired = $absoluteTimeoutSeconds > 0 && ($now - $createdAt) > $absoluteTimeoutSeconds;

        if ($idleExpired || $absoluteExpired) {
            self::invalidateSession();
            return;
        }

        $_SESSION[self::SESSION_CREATED_AT] = $createdAt;
        $_SESSION[self::SESSION_LAST_ACTIVITY_AT] = $now;
    }

    public static function markAuthenticatedSession(): void
    {
        if (php_sapi_name() === 'cli') {
            return;
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        session_regenerate_id(true);
        $now = time();
        $_SESSION[self::SESSION_CREATED_AT] = $now;
        $_SESSION[self::SESSION_LAST_ACTIVITY_AT] = $now;
    }

    public static function invalidateSession(): void
    {
        if (php_sapi_name() === 'cli') {
            return;
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION = [];
        self::expireCookie(session_name(), true);
        session_unset();
        session_destroy();
    }

    public static function expireCookie(string $name, bool $httpOnly): void
    {
        if ($name === '' || headers_sent()) {
            return;
        }

        setcookie($name, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => self::shouldUseSecureCookies(),
            'httponly' => $httpOnly,
            'samesite' => 'Lax',
        ]);
    }

    public static function shouldUseSecureCookies(): bool
    {
        $https = (string)($_SERVER['HTTPS'] ?? '');
        if ($https !== '' && strtolower($https) !== 'off') {
            return true;
        }

        $forwardedProto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        return $forwardedProto === 'https';
    }

    private static function normalizeSameSite(string $sameSite): string
    {
        $value = ucfirst(strtolower(trim($sameSite)));
        if (!in_array($value, ['Lax', 'Strict', 'None'], true)) {
            return 'Lax';
        }
        if ($value === 'None' && !self::shouldUseSecureCookies()) {
            return 'Lax';
        }
        return $value;
    }
}
