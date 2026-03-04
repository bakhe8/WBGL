<?php

/**
 * API Endpoint: Delete User
 */

require_once __DIR__ . '/../_bootstrap.php';

use App\Services\AuditTrailService;
use App\Support\AuthService;
use App\Support\Database;
use App\Repositories\UserRepository;

wbgl_api_require_permission('manage_users');

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}
$userId = $input['user_id'] ?? null;

if (!$userId) {
    wbgl_api_compat_fail(400, 'Missing user_id');
}

try {
    $db = Database::connect();
    $repo = new UserRepository($db);

    // Sanity Checks
    $currentUser = AuthService::getCurrentUser();
    if ($currentUser && $currentUser->id == $userId) {
        wbgl_api_compat_fail(400, 'لا يمكنك حذف حسابك الحالي');
    }

    $user = $repo->find((int)$userId);
    if (!$user) {
        wbgl_api_compat_fail(404, 'المستخدم غير موجود');
    }

    $repo->delete((int)$userId);

    AuditTrailService::record(
        'user_deleted',
        'delete',
        'user',
        (string)$userId,
        [
            'username' => $user->username,
            'full_name' => $user->fullName,
            'role_id' => $user->roleId,
        ],
        'critical'
    );

    wbgl_api_compat_success([
        'message' => 'تم حذف المستخدم بنجاح',
    ]);
} catch (\Exception $e) {
    wbgl_api_compat_fail(500, $e->getMessage(), [], 'internal');
}
