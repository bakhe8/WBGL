<?php

/**
 * Logout API
 */

if (!defined('WBGL_API_SKIP_GLOBAL_CSRF')) {
    define('WBGL_API_SKIP_GLOBAL_CSRF', true);
}
require_once __DIR__ . '/_bootstrap.php';

use App\Support\AuthService;
use App\Support\ApiTokenService;

$isApiRequest = ApiTokenService::hasBearerToken()
    || (isset($_SERVER['HTTP_ACCEPT']) && str_contains((string)$_SERVER['HTTP_ACCEPT'], 'application/json'));

if (ApiTokenService::hasBearerToken()) {
    ApiTokenService::revokeCurrentToken();
}

AuthService::logout();

if ($isApiRequest) {
    wbgl_api_compat_success([
        'message' => 'تم تسجيل الخروج بنجاح',
    ]);
}

header('Location: /views/login.php');
exit;
