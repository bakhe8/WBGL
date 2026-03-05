<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\RoleRepository;
use App\Support\AuthService;
use App\Support\Database;
use App\Support\Guard;

/**
 * BatchAccessPolicyService
 *
 * Default access to batch surfaces is restricted to data_entry only.
 * Any other role (including developer) can be granted an explicit exception via permission:
 * - batch_full_operations_override
 */
final class BatchAccessPolicyService
{
    /**
     * @var array<int,string>
     */
    private const DEFAULT_ALLOWED_ROLES = ['data_entry'];

    public static function canAccessBatchSurfaces(): bool
    {
        if (Guard::has('batch_full_operations_override')) {
            return true;
        }

        $user = AuthService::getCurrentUser();
        if ($user === null || $user->roleId === null) {
            return false;
        }

        try {
            $db = Database::connect();
            $roleRepo = new RoleRepository($db);
            $role = $roleRepo->find((int)$user->roleId);
            $roleSlug = strtolower(trim((string)($role->slug ?? '')));
            return in_array($roleSlug, self::DEFAULT_ALLOWED_ROLES, true);
        } catch (\Throwable) {
            return false;
        }
    }
}
