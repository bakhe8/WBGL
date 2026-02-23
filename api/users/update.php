<?php

/**
 * API Endpoint: Update User (Full)
 */

require_once __DIR__ . '/../../app/Support/autoload.php';

use App\Support\AuthService;
use App\Support\Database;
use App\Support\Guard;
use App\Repositories\UserRepository;

header('Content-Type: application/json; charset=utf-8');

// 1. Auth Check
if (!AuthService::isLoggedIn() || !Guard::has('manage_users')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission Denied']);
    exit;
}

// 2. Input Validation
$input = json_decode(file_get_contents('php://input'), true);
$userId = $input['user_id'] ?? null;
$fullName = $input['full_name'] ?? null;
$username = $input['username'] ?? null;
$email = $input['email'] ?? null;
$roleId = $input['role_id'] ?? null;
$password = $input['password'] ?? null;

if (!$userId || !$fullName || !$username || !$roleId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

try {
    $db = Database::connect();
    $repo = new UserRepository($db);

    $user = $repo->find((int)$userId);
    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'المستخدم غير موجود']);
        exit;
    }

    // Check username unique if changed
    if ($username !== $user->username) {
        if ($repo->findByUsername($username)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'اسم المستخدم الجديد موجود مسبقاً']);
            exit;
        }
    }

    // Update fields
    $user->fullName = $fullName;
    $user->username = $username;
    $user->email = $email;
    $user->roleId = (int)$roleId;

    if (!empty($password)) {
        $user->passwordHash = password_hash($password, PASSWORD_BCRYPT);
    }

    // Sync overrides if provided
    if (isset($input['permissions_overrides']) && is_array($input['permissions_overrides'])) {
        $repo->syncPermissionsOverrides((int)$userId, $input['permissions_overrides']);
    }

    echo json_encode(['success' => true, 'message' => 'تم تحديث البيانات بنجاح']);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
