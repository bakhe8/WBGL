<?php

/**
 * Logout API
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\AuthService;

AuthService::logout();

header('Location: /views/login.php');
exit;
