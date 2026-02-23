<?php

declare(strict_types=1);

namespace App\Support;

use App\Repositories\RoleRepository;

/**
 * Guard
 * Centralized permission checking service
 */
class Guard
{
    private static ?array $permissions = null;

    /**
     * Check if the current user has a specific permission
     *
     * @param string $permissionSlug
     * @return bool
     */
    public static function has(string $permissionSlug): bool
    {
        $user = AuthService::getCurrentUser();

        // Developer has all permissions
        if ($user && $user->roleId !== null) {
            $db = Database::connect();
            $roleRepo = new RoleRepository($db);
            $role = $roleRepo->find($user->roleId);

            if ($role && $role->slug === 'developer') {
                return true;
            }

            // Cache permissions for the current request
            if (self::$permissions === null) {
                self::$permissions = $roleRepo->getPermissions($user->roleId);
            }

            return in_array($permissionSlug, self::$permissions);
        }

        return false;
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
