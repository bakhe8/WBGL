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
use App\Services\ActionabilityPolicyService;
use App\Services\GuaranteeVisibilityService;
use App\Services\AuditTrailService;
use App\Services\UiSurfacePolicyService;

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

if (!function_exists('wbgl_api_envelope')) {
    /**
     * Emit a unified WBGL API envelope and terminate execution.
     *
     * @param mixed $data
     */
    function wbgl_api_envelope(int $statusCode, bool $success, $data = null, ?string $error = null): void
    {
        wbgl_api_json_headers();
        http_response_code($statusCode);
        echo json_encode([
            'success' => $success,
            'data' => $data,
            'error' => $error,
            'request_id' => wbgl_api_request_id(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('wbgl_api_success')) {
    /**
     * @param mixed $data
     */
    function wbgl_api_success($data = null, int $statusCode = 200): void
    {
        wbgl_api_envelope($statusCode, true, $data, null);
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

        wbgl_api_compat_fail(
            $statusCode,
            $message,
            ['request_id' => $requestId],
            wbgl_api_error_type_from_status($statusCode)
        );
    }
}

if (!function_exists('wbgl_api_error_type_from_status')) {
    function wbgl_api_error_type_from_status(int $statusCode): string
    {
        if ($statusCode === 401 || $statusCode === 403) {
            return 'permission';
        }
        if ($statusCode === 404) {
            return 'not_found';
        }
        if ($statusCode === 409) {
            return 'conflict';
        }
        if ($statusCode >= 500) {
            return 'internal';
        }
        return 'validation';
    }
}

if (!function_exists('wbgl_api_compat_success')) {
    /**
     * Emit unified envelope while preserving legacy top-level fields.
     *
     * @param array<string,mixed> $payload
     */
    function wbgl_api_compat_success(array $payload = [], int $statusCode = 200): void
    {
        $requestId = wbgl_api_request_id();
        if (!array_key_exists('request_id', $payload)) {
            $payload['request_id'] = $requestId;
        }

        wbgl_api_json_headers();
        http_response_code($statusCode);
        echo json_encode(array_merge([
            'success' => true,
            'data' => $payload,
            'error' => null,
            'error_type' => null,
            'request_id' => $requestId,
        ], $payload), JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('wbgl_api_compat_fail')) {
    /**
     * Emit unified envelope while preserving legacy top-level fields.
     *
     * @param array<string,mixed> $payload
     */
    function wbgl_api_compat_fail(
        int $statusCode,
        string $message,
        array $payload = [],
        ?string $errorType = null
    ): void {
        $requestId = wbgl_api_request_id();
        $resolvedErrorType = trim((string)$errorType) !== ''
            ? (string)$errorType
            : wbgl_api_error_type_from_status($statusCode);
        $publicMessage = $message;
        if ($resolvedErrorType === 'internal') {
            $publicMessage = 'حدث خطأ داخلي. استخدم رقم الطلب للمتابعة.';
            if (trim($message) !== '') {
                error_log('[WBGL_API_INTERNAL][' . $requestId . '] ' . $message);
            }
        }

        if (!array_key_exists('request_id', $payload)) {
            $payload['request_id'] = $requestId;
        }
        if (!array_key_exists('error', $payload)) {
            $payload['error'] = $publicMessage;
        }
        if (!array_key_exists('error_type', $payload)) {
            $payload['error_type'] = $resolvedErrorType;
        }

        wbgl_api_json_headers();
        http_response_code($statusCode);
        echo json_encode(array_merge([
            'success' => false,
            'data' => null,
            'error' => $publicMessage,
            'error_type' => $resolvedErrorType,
            'request_id' => $requestId,
        ], $payload), JSON_UNESCAPED_UNICODE);
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

if (!function_exists('wbgl_api_require_guarantee_visibility')) {
    function wbgl_api_require_guarantee_visibility(int $guaranteeId): void
    {
        wbgl_api_require_login();

        if ($guaranteeId <= 0) {
            wbgl_api_fail(400, 'guarantee_id is required');
        }

        if (!GuaranteeVisibilityService::canAccessGuarantee($guaranteeId)) {
            wbgl_api_fail(403, 'Permission Denied');
        }
    }
}

if (!function_exists('wbgl_api_policy_for_guarantee')) {
    /**
     * Build canonical visibility/actionability/executability decision
     * using minimal fields from guarantee_decisions only.
     *
     * @return array{visible:bool,actionable:bool,executable:bool,reasons:array<int,string>}
     */
    function wbgl_api_policy_for_guarantee(\PDO $db, int $guaranteeId): array
    {
        if ($guaranteeId <= 0) {
            return [
                'visible' => false,
                'actionable' => false,
                'executable' => false,
                'reasons' => ['INVALID_GUARANTEE_ID'],
            ];
        }

        $visible = GuaranteeVisibilityService::canAccessGuarantee($guaranteeId);
        if (!$visible) {
            return [
                'visible' => false,
                'actionable' => false,
                'executable' => false,
                'reasons' => ['NOT_VISIBLE'],
            ];
        }

        $stmt = $db->prepare('
            SELECT status, workflow_step, is_locked, active_action
            FROM guarantee_decisions
            WHERE guarantee_id = ?
            LIMIT 1
        ');
        $stmt->execute([$guaranteeId]);
        $decisionRow = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [
            'status' => 'pending',
            'workflow_step' => null,
            'is_locked' => false,
            'active_action' => null,
        ];

        return ActionabilityPolicyService::evaluate($decisionRow, true)->toArray();
    }
}

if (!function_exists('wbgl_api_policy_surface_for_guarantee')) {
    /**
     * Build canonical policy + surface grants envelope for guarantee-targeted APIs.
     *
     * @return array{
     *   policy:array{visible:bool,actionable:bool,executable:bool,reasons:array<int,string>},
     *   surface:array{
     *     can_view_record:bool,
     *     can_view_identity:bool,
     *     can_view_timeline:bool,
     *     can_view_notes:bool,
     *     can_create_notes:bool,
     *     can_view_attachments:bool,
     *     can_upload_attachments:bool,
     *     can_execute_actions:bool,
     *     can_view_preview:bool
     *   }
     * }
     */
    function wbgl_api_policy_surface_for_guarantee(
        \PDO $db,
        int $guaranteeId,
        ?string $recordStatus = null
    ): array {
        $policy = wbgl_api_policy_for_guarantee($db, $guaranteeId);
        $status = trim((string)$recordStatus);

        if ($status === '') {
            $stmt = $db->prepare('SELECT status FROM guarantee_decisions WHERE guarantee_id = ? LIMIT 1');
            $stmt->execute([$guaranteeId]);
            $status = (string)($stmt->fetchColumn() ?: 'pending');
        }

        $surface = UiSurfacePolicyService::forGuarantee(
            $policy,
            Guard::permissions(),
            $status
        );

        return [
            'policy' => $policy,
            'surface' => $surface,
        ];
    }
}

if (!function_exists('wbgl_api_current_user_display')) {
    function wbgl_api_current_user_display(): string
    {
        $user = AuthService::getCurrentUser();
        if (!$user) {
            return 'النظام';
        }
        $fullName = trim((string)$user->fullName);
        $username = trim((string)$user->username);
        $email = trim((string)($user->email ?? ''));
        $id = (int)($user->id ?? 0);

        if ($fullName !== '') {
            $base = $fullName;
        } elseif ($username !== '') {
            $base = '@' . $username;
        } elseif ($email !== '') {
            $base = $email;
        } elseif ($id > 0) {
            $base = 'id:' . $id;
        } else {
            return 'النظام';
        }
        $parts = [];
        if ($username !== '') {
            $parts[] = '@' . $username;
        }
        if ($id > 0) {
            $parts[] = 'id:' . $id;
        }
        if ($email !== '') {
            $parts[] = $email;
        }

        if (empty($parts)) {
            return $base;
        }

        return $base . ' (' . implode(' | ', $parts) . ')';
    }
}

if (!function_exists('wbgl_api_current_user_actor')) {
    /**
     * @return array{id:?int,username:string,full_name:string,email:string,display:string}
     */
    function wbgl_api_current_user_actor(): array
    {
        $user = AuthService::getCurrentUser();
        if (!$user) {
            return [
                'id' => null,
                'username' => 'system',
                'full_name' => 'النظام',
                'email' => '',
                'display' => 'النظام',
            ];
        }

        return [
            'id' => $user->id,
            'username' => (string)$user->username,
            'full_name' => (string)$user->fullName,
            'email' => (string)($user->email ?? ''),
            'display' => wbgl_api_current_user_display(),
        ];
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
$skipGlobalCsrf = defined('WBGL_API_SKIP_GLOBAL_CSRF') && (bool)WBGL_API_SKIP_GLOBAL_CSRF;
if (!$skipGlobalCsrf && $csrfEnforced && CsrfGuard::isMutatingMethod()) {
    wbgl_api_require_csrf();
}
