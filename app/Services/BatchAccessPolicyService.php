<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\RoleRepository;
use App\Support\AuthService;
use App\Support\Database;

/**
 * BatchAccessPolicyService
 *
 * Access to batch surfaces is strictly role-scoped.
 * Only data_entry and developer roles are allowed.
 */
final class BatchAccessPolicyService
{
    /**
     * @var array<int,string>
     */
    private const ALLOWED_ROLES = ['data_entry', 'developer'];

    public static function canAccessBatchSurfaces(): bool
    {
        $user = AuthService::getCurrentUser();
        if ($user === null || $user->roleId === null) {
            return false;
        }

        try {
            $db = Database::connect();
            $roleRepo = new RoleRepository($db);
            $role = $roleRepo->find((int)$user->roleId);
            $roleSlug = strtolower(trim((string)($role->slug ?? '')));
            return in_array($roleSlug, self::ALLOWED_ROLES, true);
        } catch (\Throwable) {
            return false;
        }
    }
}
