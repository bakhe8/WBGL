<?php

/**
 * API Endpoint: Update User (Full)
 */

require_once __DIR__ . '/../_bootstrap.php';

use App\Services\AuditTrailService;
use App\Support\Database;
use App\Repositories\UserRepository;
use App\Support\DirectionResolver;
use App\Support\ThemeResolver;

header('Content-Type: application/json; charset=utf-8');
wbgl_api_require_permission('manage_users');

// 2. Input Validation
$input = json_decode(file_get_contents('php://input'), true);
$userId = $input['user_id'] ?? null;
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
    $before = $user->toArray();
    $user->fullName = $fullName;
    $user->username = $username;
    $user->email = $email;
    $user->roleId = (int)$roleId;
    $user->preferredLanguage = $preferredLanguage;
    $user->preferredTheme = $preferredTheme;
    $user->preferredDirection = $preferredDirection;

    if (!empty($password)) {
        $user->passwordHash = password_hash($password, PASSWORD_BCRYPT);
    }

    $repo->update($user);

    // Sync overrides if provided
    if (isset($input['permissions_overrides']) && is_array($input['permissions_overrides'])) {
        $repo->syncPermissionsOverrides((int)$userId, $input['permissions_overrides']);
    }

    AuditTrailService::record(
        'user_updated',
        'update',
        'user',
        (string)$userId,
        [
            'before' => $before,
            'after' => $user->toArray(),
            'password_changed' => !empty($password),
            'overrides_updated' => isset($input['permissions_overrides']) && is_array($input['permissions_overrides']),
        ],
        'high'
    );

    echo json_encode(['success' => true, 'message' => 'تم تحديث البيانات بنجاح']);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
