<?php
declare(strict_types=1);

namespace App\Support;

/**
 * CSRF protection helper for cookie/session-based requests.
 */
class CsrfGuard
{
    private const SESSION_KEY = '_csrf_token';
    private const COOKIE_NAME = 'wbgl_csrf_token';

    public static function token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return '';
        }

        $current = (string)($_SESSION[self::SESSION_KEY] ?? '');
        if ($current !== '') {
            return $current;
        }

        $token = bin2hex(random_bytes(32));
        $_SESSION[self::SESSION_KEY] = $token;
        return $token;
    }

    public static function rotateToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return '';
        }

        $token = bin2hex(random_bytes(32));
        $_SESSION[self::SESSION_KEY] = $token;
        return $token;
    }

    public static function publishCookie(): void
    {
        if (php_sapi_name() === 'cli' || headers_sent()) {
            return;
        }

        $token = self::token();
        if ($token === '') {
            return;
        }

        setcookie(self::COOKIE_NAME, $token, [
            'expires' => 0,
            'path' => '/',
            'secure' => SessionSecurity::shouldUseSecureCookies(),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }

    public static function clearToken(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION[self::SESSION_KEY]);
        }
        SessionSecurity::expireCookie(self::COOKIE_NAME, false);
    }

    public static function isMutatingMethod(?string $method = null): bool
    {
        $resolved = strtoupper(trim((string)($method ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET'))));
        return in_array($resolved, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    public static function validateRequest(): bool
    {
        if (!self::isMutatingMethod()) {
            return true;
        }

        // Bearer-token API clients are not vulnerable to browser CSRF.
        if (ApiTokenService::hasBearerToken()) {
            return true;
        }

        $sessionToken = (string)($_SESSION[self::SESSION_KEY] ?? '');
        if ($sessionToken === '') {
            return false;
        }

        $requestToken = self::extractRequestToken();
        if ($requestToken === '') {
            return false;
        }

        return hash_equals($sessionToken, $requestToken);
    }

    public static function extractRequestToken(): string
    {
        $header = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        if ($header !== '') {
            return $header;
        }

        $altHeader = trim((string)($_SERVER['HTTP_X_XSRF_TOKEN'] ?? ''));
        if ($altHeader !== '') {
            return $altHeader;
        }

        $postToken = trim((string)($_POST['_csrf'] ?? ''));
        if ($postToken !== '') {
            return $postToken;
        }

        return '';
    }
}

