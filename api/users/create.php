<?php

/**
 * API Endpoint: Create User
 */

require_once __DIR__ . '/../../app/Support/autoload.php';

use App\Support\AuthService;
use App\Support\Database;
use App\Support\Guard;
use App\Repositories\UserRepository;
use App\Models\User;

header('Content-Type: application/json; charset=utf-8');

// 1. Auth Check
if (!AuthService::isLoggedIn() || !Guard::has('manage_users')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission Denied']);
    exit;
}

// 2. Input Validation
$input = json_decode(file_get_contents('php://input'), true);
$fullName = $input['full_name'] ?? null;
$username = $input['username'] ?? null;
$email = $input['email'] ?? null;
$roleId = $input['role_id'] ?? null;
$password = $input['password'] ?? null;

if (!$fullName || !$username || !$roleId || !$password) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'الرجاء تعبئة كافة الحقول المطلوبة']);
    exit;
}

try {
    $db = Database::connect();
    $repo = new UserRepository($db);

    // Check if username unique
    if ($repo->findByUsername($username)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'اسم المستخدم موجود مسبقاً']);
        exit;
    }

    $passwordHash = password_hash($password, PASSWORD_BCRYPT);

    $newUser = new User(
        0,
        $username,
        $passwordHash,
        $fullName,
        $email,
        (int)$roleId,
        null,
        date('Y-m-d H:i:s')
    );

    $user = $repo->create($newUser);

    // Sync overrides if provided
    if (isset($input['permissions_overrides']) && is_array($input['permissions_overrides'])) {
        $repo->syncPermissionsOverrides($user->id, $input['permissions_overrides']);
    }

    echo json_encode(['success' => true, 'message' => 'تم إنشاء المستخدم بنجاح']);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
