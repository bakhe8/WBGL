<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Centralized HTTP response security headers.
 */
class SecurityHeaders
{
    private const CSP_REPORT_ENDPOINT = '/api/csp-report.php';

    public static function apply(): void
    {
        if (php_sapi_name() === 'cli' || headers_sent()) {
            return;
        }

        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');

        if (SessionSecurity::shouldUseSecureCookies()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }

        $strictEnforce = self::resolveFlag('CSP_STRICT_ENFORCE', false);
        $reportOnlyEnabled = self::resolveFlag('CSP_REPORT_ONLY', true);

        $compatiblePolicy = self::buildCompatiblePolicy();
        $strictPolicy = self::buildStrictPolicy();
        header('Content-Security-Policy: ' . ($strictEnforce ? $strictPolicy : $compatiblePolicy));

        if (!$strictEnforce && $reportOnlyEnabled) {
            header('Content-Security-Policy-Report-Only: ' . $strictPolicy);
        }
    }

    private static function buildCompatiblePolicy(): string
    {
        return implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
            "object-src 'none'",
            "script-src 'self' 'unsafe-inline'",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' data: https://fonts.gstatic.com",
            "img-src 'self' data: blob: https:",
            "connect-src 'self'",
        ]);
    }

    private static function buildStrictPolicy(): string
    {
        return implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
            "object-src 'none'",
            "script-src 'self'",
            "script-src-attr 'none'",
            "style-src 'self' https://fonts.googleapis.com",
            "style-src-attr 'none'",
            "font-src 'self' data: https://fonts.gstatic.com",
            "img-src 'self' data: blob: https:",
            "connect-src 'self'",
            'report-uri ' . self::CSP_REPORT_ENDPOINT,
        ]);
    }

    private static function resolveFlag(string $key, bool $default): bool
    {
        try {
            $value = Settings::getInstance()->get($key, null);
            if ($value !== null) {
                return self::normalizeBool($value, $default);
            }
        } catch (\Throwable $e) {
            // Fall back to environment/default values.
        }

        $envValue = getenv('WBGL_' . $key);
        if ($envValue !== false) {
            return self::normalizeBool($envValue, $default);
        }

        return $default;
    }

    private static function normalizeBool(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            $normalized = trim($value);
            if ($normalized === '') {
                return $default;
            }
            $parsed = filter_var($normalized, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            return $parsed ?? $default;
        }
        return $default;
    }
}
