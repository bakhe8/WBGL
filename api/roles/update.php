<?php
declare(strict_types=1);

require_once __DIR__ . '/../_bootstrap.php';

use App\Repositories\RoleRepository;
use App\Services\AuditTrailService;
use App\Services\RoleGovernanceService;
use App\Support\Database;

wbgl_api_require_permission('manage_roles');

/**
 * @return int[]
 */
function wbgl_roles_update_normalize_permission_ids(array $raw): array
{
    return array_values(array_unique(array_filter(array_map('intval', $raw), static function (int $id): bool {
        return $id > 0;
    })));
}

function wbgl_roles_update_slugify(string $name): string
{
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9_\\-]+/u', '_', $slug) ?? '';
    $slug = preg_replace('/_+/', '_', $slug) ?? '';
    $slug = trim($slug, '_-');
    if ($slug === '') {
        $slug = 'role_' . date('YmdHis');
    }
    return $slug;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

$roleId = (int)($input['role_id'] ?? 0);
$name = trim((string)($input['name'] ?? ''));
$slugRaw = trim((string)($input['slug'] ?? ''));
$description = trim((string)($input['description'] ?? ''));
$permissionIds = is_array($input['permission_ids'] ?? null) ? $input['permission_ids'] : [];
$permissionIds = wbgl_roles_update_normalize_permission_ids($permissionIds);

if ($roleId <= 0 || $name === '') {
    wbgl_api_compat_fail(400, 'role_id واسم الدور مطلوبان');
}

$slug = $slugRaw !== '' ? strtolower($slugRaw) : wbgl_roles_update_slugify($name);
if (!preg_match('/^[a-z0-9_\\-]{2,64}$/', $slug)) {
    wbgl_api_compat_fail(400, 'صيغة slug غير صالحة (a-z, 0-9, _, -)');
}

try {
    $db = Database::connect();
    $repo = new RoleRepository($db);

    $existing = $repo->find($roleId);
    if (!$existing) {
        wbgl_api_compat_fail(404, 'الدور غير موجود');
    }

    $existingSlug = strtolower(trim((string)$existing->slug));
    if (RoleGovernanceService::isProtectedRoleSlug($existingSlug) && $slug !== $existingSlug) {
        wbgl_api_compat_fail(409, 'لا يمكن تغيير slug لدور نظام أساسي.');
    }

    $slugOwner = $repo->findBySlug($slug);
    if ($slugOwner && (int)$slugOwner->id !== $roleId) {
        wbgl_api_compat_fail(409, 'يوجد دور آخر بنفس slug');
    }

    $allPermissionIds = array_map('intval', $db->query('SELECT id FROM permissions')->fetchAll(PDO::FETCH_COLUMN));
    $invalidPermissionIds = array_values(array_diff($permissionIds, $allPermissionIds));
    if (!empty($invalidPermissionIds)) {
        wbgl_api_compat_fail(400, 'تم إرسال صلاحيات غير موجودة: ' . implode(',', $invalidPermissionIds));
    }

    $before = $existing->toArray();
    $updated = $repo->updateRole($roleId, $name, $slug, $description === '' ? null : $description, $permissionIds);
    if (!$updated) {
        wbgl_api_compat_fail(500, 'تعذر تحديث الدور', [], 'internal');
    }

    AuditTrailService::record(
        'role_updated',
        'update',
        'role',
        (string)$roleId,
        [
            'before' => $before,
            'after' => $updated->toArray(),
            'permission_ids' => $permissionIds,
        ],
        'high'
    );

    wbgl_api_compat_success([
        'message' => 'تم تحديث الدور بنجاح',
        'role' => $updated->toArray(),
    ]);
} catch (\Throwable $e) {
    wbgl_api_compat_fail(500, $e->getMessage(), [], 'internal');
}
