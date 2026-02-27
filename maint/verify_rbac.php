<?php

/**
 * RBAC Infrastructure Verification Script
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\AuthService;
use App\Support\Guard;
use App\Support\Database;

echo "--- RBAC Infrastructure Verification ---\n";

// 1. Mock session for CLI
if (session_status() === PHP_SESSION_NONE) {
    $_SESSION = [];
}

echo "1. Testing Login...\n";
if (AuthService::login('admin', 'admin123')) {
    echo "✅ Success: Logged in as 'admin'\n";
} else {
    echo "❌ Fail: Could not login as 'admin'\n";
    exit;
}

echo "2. Testing Guard Permissions (Developer)...\n";
if (Guard::has('import_excel')) {
    echo "✅ Success: Developer has 'import_excel' permission\n";
} else {
    echo "❌ Fail: Developer missing 'import_excel' permission\n";
}

if (Guard::has('random_permission')) {
    echo "✅ Success: Developer has 'random_permission' (Full Access check ok)\n";
} else {
    echo "❌ Fail: Developer should have all permissions\n";
}

echo "3. Testing Logout...\n";
AuthService::logout();
if (!AuthService::isLoggedIn()) {
    echo "✅ Success: Logged out\n";
} else {
    echo "❌ Fail: Still logged in after logout\n";
}

echo "4. Testing Permission Denied (Logged out)...\n";
if (!Guard::has('import_excel')) {
    echo "✅ Success: Permission denied when logged out\n";
} else {
    echo "❌ Fail: Permission granted when logged out\n";
}

echo "\n--- Verification Complete ---\n";
