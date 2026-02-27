<?php
declare(strict_types=1);

/**
 * WBGL API bootstrap
 * Centralizes auth/permission checks for API endpoints.
 */
require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\AuthService;
use App\Support\ApiTokenService;
use App\Support\CsrfGuard;
use App\Support\Guard;
use App\Support\Settings;
use App\Services\AuditTrailService;

if (!function_exists('wbgl_api_json_headers')) {
    function wbgl_api_json_headers(): void
    {
        header('Content-Type: application/json; charset=utf-8');
    }
}

if (!function_exists('wbgl_api_request_id')) {
    function wbgl_api_request_id(): string
    {
        static $requestId = null;
        if (is_string($requestId) && $requestId !== '') {
            return $requestId;
        }

        $incoming = trim((string)($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
        if ($incoming !== '' && preg_match('/^[A-Za-z0-9._-]{8,128}$/', $incoming) === 1) {
            $requestId = $incoming;
        } else {
            $requestId = 'wbgl_' . bin2hex(random_bytes(8));
        }

        $_SERVER['WBGL_REQUEST_ID'] = $requestId;
        header('X-Request-Id: ' . $requestId);
        return $requestId;
    }
}

if (!function_exists('wbgl_api_fail')) {
    function wbgl_api_fail(int $statusCode, string $message): void
    {
        $requestId = wbgl_api_request_id();

        if ($statusCode === 401 || $statusCode === 403) {
            AuditTrailService::record(
                'api_access_denied',
                'deny',
                'endpoint',
                (string)($_SERVER['REQUEST_URI'] ?? ''),
                [
                    'status_code' => $statusCode,
                    'message' => $message,
                    'request_id' => $requestId,
                    'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
                ],
                'medium'
            );
        }

        wbgl_api_json_headers();
        http_response_code($statusCode);
        echo json_encode([
            'success' => false,
            'error' => $message,
            'request_id' => $requestId,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('wbgl_api_require_login')) {
    function wbgl_api_require_login(): void
    {
        if (AuthService::isLoggedIn()) {
            return;
        }

        $tokenUser = ApiTokenService::authenticateRequest();
        if ($tokenUser !== null) {
            return;
        }

        wbgl_api_fail(401, 'Unauthorized');
    }
}

if (!function_exists('wbgl_api_require_permission')) {
    function wbgl_api_require_permission(string $permissionSlug): void
    {
        wbgl_api_require_login();
        if (!Guard::has($permissionSlug)) {
            wbgl_api_fail(403, 'Permission Denied');
        }
    }
}

if (!function_exists('wbgl_api_current_user_display')) {
    function wbgl_api_current_user_display(): string
    {
        $user = AuthService::getCurrentUser();
        if (!$user) {
            return 'النظام';
        }
        return $user->fullName ?: $user->username;
    }
}

if (!function_exists('wbgl_api_require_csrf')) {
    function wbgl_api_require_csrf(): void
    {
        if (CsrfGuard::validateRequest()) {
            return;
        }
        wbgl_api_fail(419, 'Invalid CSRF token');
    }
}

$csrfEnforced = (bool)Settings::getInstance()->get('CSRF_ENFORCE_MUTATING', true);
wbgl_api_request_id();
if ($csrfEnforced && CsrfGuard::isMutatingMethod()) {
    wbgl_api_require_csrf();
}
