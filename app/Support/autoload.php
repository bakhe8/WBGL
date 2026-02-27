<?php

declare(strict_types=1);

// Set timezone from Settings (dynamic)
date_default_timezone_set('Asia/Riyadh'); // Will be overridden below after Settings loads

spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/../';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

if (php_sapi_name() !== 'cli') {
    \App\Support\SessionSecurity::configureSessionCookieOptions('Lax');
    \App\Support\SessionSecurity::startSessionIfNeeded();
}

// After Settings class is loaded, update timezone dynamically
if (class_exists('App\\Support\\Settings')) {
    $settings = new App\Support\Settings();
    $timezone = $settings->get('TIMEZONE', 'Asia/Riyadh');
    date_default_timezone_set($timezone);

    if (php_sapi_name() !== 'cli') {
        if ((bool)$settings->get('SECURITY_HEADERS_ENABLED', true)) {
            \App\Support\SecurityHeaders::apply();
        }

        $idleTimeout = (int)$settings->get('SESSION_IDLE_TIMEOUT_SECONDS', 1800);
        $absoluteTimeout = (int)$settings->get('SESSION_ABSOLUTE_TIMEOUT_SECONDS', 43200);
        \App\Support\SessionSecurity::enforceTimeouts($idleTimeout, $absoluteTimeout);

        if ((bool)$settings->get('CSRF_ENFORCE_MUTATING', true)) {
            \App\Support\CsrfGuard::publishCookie();

            $requestPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
            $isApiPath = is_string($requestPath) && str_contains($requestPath, '/api/');

            if (
                !$isApiPath
                && \App\Support\CsrfGuard::isMutatingMethod()
                && !\App\Support\CsrfGuard::validateRequest()
            ) {
                http_response_code(419);

                $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
                $expectsJson = str_contains($accept, 'application/json');
                if ($expectsJson) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                        'success' => false,
                        'error' => 'Invalid CSRF token',
                    ], JSON_UNESCAPED_UNICODE);
                } else {
                    header('Content-Type: text/html; charset=utf-8');
                    echo '<h1>419</h1><p>Invalid request token. Refresh and try again.</p>';
                }
                exit;
            }
        }
    }
}

// Composer autoload (PhpSpreadsheet)
$composerAutoload = base_path('vendor/autoload.php');
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
}

function base_path(string $path = ''): string
{
    $base = dirname(__DIR__, 1);
    // Move up one more level to reach project root
    $base = dirname($base);
    return $path ? $base . '/' . ltrim($path, '/') : $base;
}

function storage_path(string $path = ''): string
{
    $base = base_path('storage');
    return $path ? $base . '/' . ltrim($path, '/') : $base;
}

if (!function_exists('wbgl_csrf_token')) {
    function wbgl_csrf_token(): string
    {
        return \App\Support\CsrfGuard::token();
    }
}

if (!function_exists('wbgl_csrf_input')) {
    function wbgl_csrf_input(): string
    {
        $token = htmlspecialchars(wbgl_csrf_token(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="_csrf" value="' . $token . '">';
    }
}

/**
 * Simple logger helper
 * Usage: \App\Support\Logger::error('message', ['context' => 'data']);
 */
