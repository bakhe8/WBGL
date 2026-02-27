<?php

/**
 * Login API
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\AuthService;
use App\Support\ApiTokenService;
use App\Support\CsrfGuard;
use App\Support\LoginRateLimiter;
use App\Support\Logger;

header('Content-Type: application/json; charset=utf-8');

try {
    if (!CsrfGuard::validateRequest()) {
        http_response_code(419);
        echo json_encode([
            'success' => false,
            'message' => 'رمز الطلب غير صالح. يرجى تحديث الصفحة ثم المحاولة.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
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
        throw new \Exception('يرجى إدخال اسم المستخدم وكلمة المرور');
    }

    $rate = LoginRateLimiter::check($username);
    if (!$rate['allowed']) {
        $retryAfter = (int)($rate['retry_after'] ?? 60);
        header('Retry-After: ' . $retryAfter);
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'message' => 'تم تجاوز عدد محاولات تسجيل الدخول. حاول مرة أخرى لاحقًا.',
            'retry_after' => $retryAfter
        ], JSON_UNESCAPED_UNICODE);
        exit;
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

        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    } else {
        $failure = LoginRateLimiter::recordFailure($username);
        if (!empty($failure['locked'])) {
            $retryAfter = (int)($failure['retry_after'] ?? 60);
            header('Retry-After: ' . $retryAfter);
            http_response_code(429);
            echo json_encode([
                'success' => false,
                'message' => 'تم تجاوز عدد محاولات تسجيل الدخول. حاول مرة أخرى لاحقًا.',
                'retry_after' => $retryAfter
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'اسم المستخدم أو كلمة المرور غير صحيحة'
        ], JSON_UNESCAPED_UNICODE);
    }
} catch (\Throwable $e) {
    Logger::error('login_endpoint_failed', [
        'error' => $e->getMessage(),
    ]);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
