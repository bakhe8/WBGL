<?php

/**
 * API Endpoint: Create User
 */

require_once __DIR__ . '/../_bootstrap.php';

use App\Services\AuditTrailService;
use App\Support\Database;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;
use App\Models\User;
use App\Support\DirectionResolver;
use App\Support\ThemeResolver;

wbgl_api_require_permission('manage_users');

// 2. Input Validation
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}
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
    wbgl_api_compat_fail(400, 'الرجاء تعبئة كافة الحقول المطلوبة');
}

try {
    $db = Database::connect();
    $repo = new UserRepository($db);
    $roleRepo = new RoleRepository($db);

    if (!$roleRepo->find((int)$roleId)) {
        wbgl_api_compat_fail(400, 'الدور المحدد غير موجود');
    }

    // Check if username unique
    if ($repo->findByUsername($username)) {
        wbgl_api_compat_fail(400, 'اسم المستخدم موجود مسبقاً');
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

    wbgl_api_compat_success([
        'message' => 'تم إنشاء المستخدم بنجاح',
    ]);
} catch (\Exception $e) {
    wbgl_api_compat_fail(500, $e->getMessage(), [], 'internal');
}
