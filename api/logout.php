<?php

/**
 * Logout API
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\AuthService;
use App\Support\ApiTokenService;

$isApiRequest = ApiTokenService::hasBearerToken()
    || (isset($_SERVER['HTTP_ACCEPT']) && str_contains((string)$_SERVER['HTTP_ACCEPT'], 'application/json'));

if (ApiTokenService::hasBearerToken()) {
    ApiTokenService::revokeCurrentToken();
}

AuthService::logout();

if ($isApiRequest) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'message' => 'تم تسجيل الخروج بنجاح',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Location: /views/login.php');
exit;
