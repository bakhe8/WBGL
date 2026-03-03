<?php
declare(strict_types=1);

require_once __DIR__ . '/../_bootstrap.php';

use App\Repositories\RoleRepository;
use App\Services\AuditTrailService;
use App\Support\Database;

wbgl_api_json_headers();
wbgl_api_require_permission('manage_roles');

/**
 * @return int[]
 */
function wbgl_roles_normalize_permission_ids(array $raw): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $raw), static function (int $id): bool {
        return $id > 0;
    })));
    return $ids;
}

function wbgl_roles_slugify(string $name): string
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

$name = trim((string)($input['name'] ?? ''));
$slugRaw = trim((string)($input['slug'] ?? ''));
$description = trim((string)($input['description'] ?? ''));
$permissionIds = is_array($input['permission_ids'] ?? null) ? $input['permission_ids'] : [];
$permissionIds = wbgl_roles_normalize_permission_ids($permissionIds);

if ($name === '') {
    wbgl_api_fail(400, 'اسم الدور مطلوب');
}

$slug = $slugRaw !== '' ? strtolower($slugRaw) : wbgl_roles_slugify($name);
if (!preg_match('/^[a-z0-9_\\-]{2,64}$/', $slug)) {
    wbgl_api_fail(400, 'صيغة slug غير صالحة (a-z, 0-9, _, -)');
}

try {
    $db = Database::connect();
    $repo = new RoleRepository($db);

    if ($repo->findBySlug($slug)) {
        wbgl_api_fail(409, 'يوجد دور بنفس slug');
    }

    $allPermissionIds = array_map('intval', $db->query('SELECT id FROM permissions')->fetchAll(PDO::FETCH_COLUMN));
    $invalidPermissionIds = array_values(array_diff($permissionIds, $allPermissionIds));
    if (!empty($invalidPermissionIds)) {
        wbgl_api_fail(400, 'تم إرسال صلاحيات غير موجودة: ' . implode(',', $invalidPermissionIds));
    }

    $role = $repo->create($name, $slug, $description === '' ? null : $description, $permissionIds);

    AuditTrailService::record(
        'role_created',
        'create',
        'role',
        (string)$role->id,
        [
            'name' => $role->name,
            'slug' => $role->slug,
            'description' => $role->description,
            'permission_ids' => $permissionIds,
        ],
        'high'
    );

    wbgl_api_success([
        'message' => 'تم إنشاء الدور بنجاح',
        'role' => $role->toArray(),
    ]);
} catch (\Throwable $e) {
    wbgl_api_fail(500, $e->getMessage());
}
