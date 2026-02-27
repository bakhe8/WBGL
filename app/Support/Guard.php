<?php

declare(strict_types=1);

namespace App\Support;

use App\Repositories\RoleRepository;
use PDO;

/**
 * Guard
 * Centralized permission checking service
 */
class Guard
{
    private static ?array $permissions = null;
    private static ?array $userOverrides = null;

    /**
     * Check if the current user has a specific permission
     *
     * @param string $permissionSlug
     * @return bool
     */
    public static function has(string $permissionSlug): bool
    {
        $permissions = self::permissions();
        return in_array($permissionSlug, $permissions, true) || in_array('*', $permissions, true);
    }

    /**
     * Get current effective permission slugs.
     *
     * @return string[]
     */
    public static function permissions(): array
    {
        if (self::$permissions !== null) {
            return self::$permissions;
        }

        $user = AuthService::getCurrentUser();
        if (!$user || $user->roleId === null) {
            self::$permissions = [];
            return self::$permissions;
        }

        $db = Database::connect();
        $roleRepo = new RoleRepository($db);
        $role = $roleRepo->find($user->roleId);

        if ($role && $role->slug === 'developer') {
            self::$permissions = ['*'];
            self::$userOverrides = ['allow' => ['*'], 'deny' => []];
            return self::$permissions;
        }

        $rolePerms = $roleRepo->getPermissions($user->roleId);

        $stmt = $db->prepare("
            SELECT p.slug, up.override_type
            FROM user_permissions up
            JOIN permissions p ON p.id = up.permission_id
            WHERE up.user_id = ?
        ");
        $stmt->execute([$user->id]);
        $overrides = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $allow = [];
        $deny = [];
        foreach ($overrides as $ov) {
            if (($ov['override_type'] ?? '') === 'allow') {
                $allow[] = $ov['slug'];
            }
            if (($ov['override_type'] ?? '') === 'deny') {
                $deny[] = $ov['slug'];
            }
        }

        self::$permissions = array_values(array_diff(array_unique(array_merge($rolePerms, $allow)), $deny));
        self::$userOverrides = ['allow' => $allow, 'deny' => $deny];
        return self::$permissions;
    }

    /**
     * Throw exception if user doesn't have permission
     */
    public static function protect(string $permissionSlug): void
    {
        if (!self::has($permissionSlug)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'Permission Denied',
                'message' => 'ليس لديك الصلاحية للقيام بهذا الإجراء.'
            ]);
            exit;
        }
    }
}
