<?php

/**
 * Login API
 */

if (!defined('WBGL_API_SKIP_GLOBAL_CSRF')) {
    define('WBGL_API_SKIP_GLOBAL_CSRF', true);
}
require_once __DIR__ . '/_bootstrap.php';

use App\Support\AuthService;
use App\Support\ApiTokenService;
use App\Support\CsrfGuard;
use App\Support\LoginRateLimiter;
use App\Support\Logger;

header('Content-Type: application/json; charset=utf-8');

try {
    if (!CsrfGuard::validateRequest()) {
        $message = 'رمز الطلب غير صالح. يرجى تحديث الصفحة ثم المحاولة.';
        wbgl_api_compat_fail(419, $message, [
            'message' => $message,
        ], 'validation');
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }

    $username = trim((string)($input['username'] ?? ''));
    $password = $input['password'] ?? '';
    $issueToken = !empty($input['issue_token']);
    $tokenName = trim((string)($input['token_name'] ?? 'web-client'));

    if (empty($username) || empty($password)) {
        $message = 'يرجى إدخال اسم المستخدم وكلمة المرور';
        wbgl_api_compat_fail(400, $message, [
            'message' => $message,
        ], 'validation');
    }

    $rate = LoginRateLimiter::check($username);
    if (!$rate['allowed']) {
        $retryAfter = (int)($rate['retry_after'] ?? 60);
        header('Retry-After: ' . $retryAfter);
        $message = 'تم تجاوز عدد محاولات تسجيل الدخول. حاول مرة أخرى لاحقًا.';
        wbgl_api_compat_fail(429, $message, [
            'message' => $message,
            'retry_after' => $retryAfter,
        ], 'validation');
    }

    if (AuthService::login($username, $password)) {
        LoginRateLimiter::clear($username);
        $payload = [
            'success' => true,
            'message' => 'تم تسجيل الدخول بنجاح',
        ];

        $user = AuthService::getCurrentUser();
        if ($user) {
            $language = $user->preferredLanguage ?: 'ar';
            $direction = $user->preferredDirection === 'rtl' || $user->preferredDirection === 'ltr'
                ? $user->preferredDirection
                : ($language === 'en' ? 'ltr' : 'rtl');
            $payload['user'] = [
                'id' => $user->id,
                'username' => $user->username,
                'full_name' => $user->fullName,
                'preferences' => [
                    'language' => $language,
                    'theme' => $user->preferredTheme ?: 'system',
                    'direction' => $direction,
                    'direction_override' => $user->preferredDirection ?: 'auto',
                ],
            ];
        }

        if ($issueToken && $user) {
            $issued = ApiTokenService::issueToken(
                (int)$user->id,
                $tokenName !== '' ? $tokenName : 'api-client'
            );
            $payload['token_type'] = $issued['token_type'];
            $payload['access_token'] = $issued['token'];
            $payload['expires_at'] = $issued['expires_at'];
        }

        wbgl_api_compat_success($payload);
    } else {
        $failure = LoginRateLimiter::recordFailure($username);
        if (!empty($failure['locked'])) {
            $retryAfter = (int)($failure['retry_after'] ?? 60);
            header('Retry-After: ' . $retryAfter);
            $message = 'تم تجاوز عدد محاولات تسجيل الدخول. حاول مرة أخرى لاحقًا.';
            wbgl_api_compat_fail(429, $message, [
                'message' => $message,
                'retry_after' => $retryAfter,
            ], 'validation');
        }

        $message = 'اسم المستخدم أو كلمة المرور غير صحيحة';
        wbgl_api_compat_fail(401, $message, [
            'message' => $message,
        ], 'permission');
    }
} catch (\Throwable $e) {
    Logger::error('login_endpoint_failed', [
        'error' => $e->getMessage(),
    ]);
    wbgl_api_compat_fail(500, $e->getMessage(), [
        'message' => $e->getMessage(),
    ], 'internal');
}
