<?php
declare(strict_types=1);

require_once __DIR__ . '/../_bootstrap.php';

use App\Repositories\RoleRepository;
use App\Services\AuditTrailService;
use App\Support\AuthService;
use App\Support\Database;

wbgl_api_require_permission('manage_roles');

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

$roleId = (int)($input['role_id'] ?? 0);
if ($roleId <= 0) {
    wbgl_api_compat_fail(400, 'role_id مطلوب');
}

try {
    $db = Database::connect();
    $repo = new RoleRepository($db);

    $role = $repo->find($roleId);
    if (!$role) {
        wbgl_api_compat_fail(404, 'الدور غير موجود');
    }

    $assignedUsers = $repo->countUsersByRole($roleId);
    if ($assignedUsers > 0) {
        wbgl_api_compat_fail(409, 'لا يمكن حذف الدور لأنه مرتبط بمستخدمين. أعد توزيع المستخدمين أولًا.');
    }

    $currentUser = AuthService::getCurrentUser();
    if ($currentUser && (int)($currentUser->roleId ?? 0) === $roleId) {
        wbgl_api_compat_fail(409, 'لا يمكن حذف الدور الحالي لحسابك');
    }

    $deleted = $repo->deleteRole($roleId);
    if (!$deleted) {
        wbgl_api_compat_fail(500, 'تعذر حذف الدور', [], 'internal');
    }

    AuditTrailService::record(
        'role_deleted',
        'delete',
        'role',
        (string)$roleId,
        [
            'name' => $role->name,
            'slug' => $role->slug,
        ],
        'critical'
    );

    wbgl_api_compat_success([
        'message' => 'تم حذف الدور بنجاح',
    ]);
} catch (\Throwable $e) {
    wbgl_api_compat_fail(500, $e->getMessage(), [], 'internal');
}
