<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\RoleRepository;
use App\Support\AuthService;
use App\Support\Database;
use App\Support\Guard;
use PDO;

/**
 * GuaranteeVisibilityService
 *
 * Centralized role-scoped visibility filter for guarantee queries.
 * Designed to be attached to list/navigation/statistics queries.
 */
class GuaranteeVisibilityService
{
    /**
     * Build SQL filter snippet and bound parameters.
     *
     * Returned SQL always starts with " AND ..." or is empty.
     *
     * @return array{sql:string,params:array<string,mixed>}
     */
    public static function buildSqlFilter(string $gAlias = 'g', string $dAlias = 'd'): array
    {
        $user = AuthService::getCurrentUser();
        if ($user === null) {
            return ['sql' => ' AND 1=0', 'params' => []];
        }

        if (self::shouldForceTaskOnlyScope()) {
            $predicate = ActionabilityPolicyService::buildActionableSqlPredicate(
                $dAlias,
                null,
                null,
                'vis_actionable_stage'
            );

            return [
                'sql' => " AND ({$dAlias}.is_locked IS NULL OR {$dAlias}.is_locked = FALSE)" . $predicate['sql'],
                'params' => $predicate['params'],
            ];
        }

        // Default broad scope only for roles explicitly allowed to use full filters
        // (data_entry/developer or permission override). All other roles are task-only.
        return ['sql' => '', 'params' => []];
    }

    private static function shouldForceTaskOnlyScope(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        // Permission-based exception:
        // allows specific role/user overrides from Users/Roles management UI.
        if (Guard::has('ui_full_filters_view')) {
            $cached = false;
            return $cached;
        }

        $user = AuthService::getCurrentUser();
        if ($user === null || $user->roleId === null) {
            $cached = false;
            return $cached;
        }

        try {
            $db = Database::connect();
            $roleRepo = new RoleRepository($db);
            $role = $roleRepo->find((int)$user->roleId);
            $roleSlug = trim((string)($role->slug ?? ''));
            $cached = !in_array($roleSlug, ['data_entry', 'developer'], true);
        } catch (\Throwable) {
            $cached = false;
        }

        return $cached;
    }

    public static function canAccessGuarantee(int $guaranteeId): bool
    {
        if ($guaranteeId <= 0) {
            return false;
        }

        $db = Database::connect();
        $visibility = self::buildSqlFilter('g', 'd');
        $sql = "
            SELECT g.id
            FROM guarantees g
            LEFT JOIN guarantee_decisions d ON d.guarantee_id = g.id
            WHERE g.id = :guarantee_id
            {$visibility['sql']}
            LIMIT 1
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute(array_merge(
            ['guarantee_id' => $guaranteeId],
            $visibility['params']
        ));

        return (bool)$stmt->fetchColumn();
    }
}
