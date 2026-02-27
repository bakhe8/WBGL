<?php
declare(strict_types=1);

namespace App\Services;

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

        // Keep broad operational/admin permissions unrestricted.
        if (Guard::has('manage_data') || Guard::has('manage_users')) {
            return ['sql' => '', 'params' => []];
        }

        $conditions = [];
        $params = [];

        // Stage-based visibility according to workflow permissions.
        $stageMap = [
            'audit_data' => ['draft'],
            'analyze_guarantee' => ['audited'],
            'supervise_analysis' => ['analyzed'],
            'approve_decision' => ['supervised'],
            'sign_letters' => ['approved', 'signed'],
        ];

        $stageIndex = 0;
        foreach ($stageMap as $permission => $stages) {
            if (!Guard::has($permission)) {
                continue;
            }

            foreach ($stages as $stage) {
                $key = 'vis_stage_' . $stageIndex++;
                $conditions[] = "{$dAlias}.workflow_step = :{$key}";
                $params[$key] = $stage;
            }
        }

        // Ownership-based fallback visibility.
        $identifiers = array_values(array_unique(array_filter([
            trim($user->username),
            trim($user->fullName),
        ], static fn(string $v): bool => $v !== '')));

        $ownerIndex = 0;
        foreach ($identifiers as $identifier) {
            $k1 = 'vis_owner_imported_' . $ownerIndex;
            $k2 = 'vis_owner_decided_' . $ownerIndex;
            $k3 = 'vis_owner_modified_' . $ownerIndex;

            $conditions[] = "{$gAlias}.imported_by = :{$k1}";
            $conditions[] = "{$dAlias}.decided_by = :{$k2}";
            $conditions[] = "{$dAlias}.last_modified_by = :{$k3}";

            $params[$k1] = $identifier;
            $params[$k2] = $identifier;
            $params[$k3] = $identifier;
            $ownerIndex++;
        }

        if (empty($conditions)) {
            return ['sql' => ' AND 1=0', 'params' => []];
        }

        return [
            'sql' => ' AND (' . implode(' OR ', $conditions) . ')',
            'params' => $params,
        ];
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
