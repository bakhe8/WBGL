<?php

/**
 * API Endpoint: Create User
 */

require_once __DIR__ . '/../_bootstrap.php';

use App\Services\AuditTrailService;
use App\Support\Database;
use App\Repositories\UserRepository;
use App\Models\User;
use App\Support\DirectionResolver;
use App\Support\ThemeResolver;

header('Content-Type: application/json; charset=utf-8');
wbgl_api_require_permission('manage_users');

// 2. Input Validation
$input = json_decode(file_get_contents('php://input'), true);
$fullName = $input['full_name'] ?? null;
$username = $input['username'] ?? null;
$email = $input['email'] ?? null;
$roleId = $input['role_id'] ?? null;
$password = $input['password'] ?? null;
$preferredLanguage = strtolower(trim((string)($input['preferred_language'] ?? 'ar')));
if (!in_array($preferredLanguage, ['ar', 'en'], true)) {
    $preferredLanguage = 'ar';
}
$preferredTheme = ThemeResolver::normalize((string)($input['preferred_theme'] ?? 'system')) ?? 'system';
$preferredDirection = DirectionResolver::normalizeOverride((string)($input['preferred_direction'] ?? 'auto'));

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
        $preferredLanguage,
        $preferredTheme,
        $preferredDirection,
        null,
        date('Y-m-d H:i:s')
    );

    $user = $repo->create($newUser);

    // Sync overrides if provided
    if (isset($input['permissions_overrides']) && is_array($input['permissions_overrides'])) {
        $repo->syncPermissionsOverrides($user->id, $input['permissions_overrides']);
    }

    AuditTrailService::record(
        'user_created',
        'create',
        'user',
        (string)$user->id,
        [
            'username' => $user->username,
            'role_id' => $user->roleId,
            'preferred_language' => $user->preferredLanguage,
            'preferred_theme' => $user->preferredTheme,
            'preferred_direction' => $user->preferredDirection,
        ],
        'high'
    );

    echo json_encode(['success' => true, 'message' => 'تم إنشاء المستخدم بنجاح']);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
