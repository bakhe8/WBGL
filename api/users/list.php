<?php

/**
 * API Endpoint: List Users
 * Returns all system users with their roles
 */

require_once __DIR__ . '/../_bootstrap.php';

use App\Support\PermissionCapabilityCatalog;
use App\Support\Database;

wbgl_api_require_permission('manage_users');

try {
    $db = Database::connect();

    // Fetch users with roles
    $stmt = $db->query("
        SELECT u.id, u.username, u.full_name, u.email, u.last_login, u.role_id,
               u.preferred_language, u.preferred_theme, u.preferred_direction,
               r.name as role_name, r.slug as role_slug
        FROM users u
        LEFT JOIN roles r ON u.role_id = r.id
        ORDER BY u.created_at DESC
    ");

    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch all roles with metadata
    $stmtRoles = $db->query("
        SELECT r.id, r.name, r.slug, r.description, COUNT(DISTINCT u.id) AS users_count
        FROM roles r
        LEFT JOIN users u ON u.role_id = r.id
        GROUP BY r.id, r.name, r.slug, r.description
        ORDER BY r.id ASC
    ");
    $roles = $stmtRoles->fetchAll(PDO::FETCH_ASSOC);

    // Fetch all available permissions for the override UI
    $stmtPerms = $db->query("SELECT id, name, slug, description FROM permissions ORDER BY id ASC");
    $permissions = $stmtPerms->fetchAll(PDO::FETCH_ASSOC);
    $catalog = PermissionCapabilityCatalog::all();
    $permissions = array_map(static function (array $row) use ($catalog): array {
        $slug = (string)($row['slug'] ?? '');
        $name = trim((string)($row['name'] ?? ''));
        if ($name === '' || preg_match('/^[\?\s]+$/u', $name) === 1) {
            $row['name'] = $slug !== '' ? $slug : 'unknown_permission';
        }
        $row['meta'] = $catalog[$slug] ?? null;
        return $row;
    }, $permissions);

    // Fetch role->permission mapping
    $stmtRolePerms = $db->query("
        SELECT role_id, permission_id
        FROM role_permissions
        ORDER BY role_id ASC, permission_id ASC
    ");
    $rolePermRows = $stmtRolePerms->fetchAll(PDO::FETCH_ASSOC);
    $rolePermissions = [];
    foreach ($rolePermRows as $rp) {
        $roleId = (int)$rp['role_id'];
        if (!isset($rolePermissions[$roleId])) {
            $rolePermissions[$roleId] = [];
        }
        $rolePermissions[$roleId][] = (int)$rp['permission_id'];
    }

    $roles = array_map(static function (array $role) use ($rolePermissions): array {
        $roleId = (int)($role['id'] ?? 0);
        $permissionIds = $rolePermissions[$roleId] ?? [];
        $role['users_count'] = (int)($role['users_count'] ?? 0);
        $role['permission_ids'] = $permissionIds;
        $role['permissions_count'] = count($permissionIds);
        return $role;
    }, $roles);

    // Fetch all user overrides
    $stmtOverrides = $db->query("
        SELECT user_id, permission_id, override_type
        FROM user_permissions
    ");
    $overridesRaw = $stmtOverrides->fetchAll(PDO::FETCH_ASSOC);

    $userOverrides = [];
    foreach ($overridesRaw as $ov) {
        $uid = $ov['user_id'];
        if (!isset($userOverrides[$uid])) {
            $userOverrides[$uid] = [];
        }
        $userOverrides[$uid][] = [
            'permission_id' => $ov['permission_id'],
            'type' => $ov['override_type']
        ];
    }

    wbgl_api_compat_success([
        'users' => $users,
        'roles' => $roles,
        'permissions' => $permissions,
        'overrides' => $userOverrides,
        'permission_catalog' => $catalog,
    ]);
} catch (\Exception $e) {
    wbgl_api_compat_fail(500, $e->getMessage(), [], 'internal');
}
