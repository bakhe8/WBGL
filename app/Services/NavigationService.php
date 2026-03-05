<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\RoleRepository;
use App\Support\AuthService;
use App\Support\Guard;
use PDO;

/**
 * NavigationService
 * 
 * Handles navigation and pagination logic for guarantees list
 * Supports filtering by status (all, ready, pending, released, actionable, data_entry)
 * 
 * @version 1.0
 */
class NavigationService
{
    /**
     * Get navigation information for a guarantee
     * 
     * @param PDO $db Database connection
     * @param int|null $currentId Current guarantee ID (null for first record)
     * @param string $statusFilter Filter: 'all', 'ready', 'pending', 'released', 'actionable', 'data_entry'
     * @param string|null $searchTerm Search query if active
     * @return array Navigation data with totalRecords, currentIndex, prevId, nextId
     */
    public static function getNavigationInfo(
        PDO $db,
        ?int $currentId,
        string $statusFilter = 'all',
        ?string $searchTerm = null,
        ?string $stageFilter = null,
        bool $includeTestData = false
    ): array {
        $filter = self::buildFilterConditions($statusFilter, $searchTerm, $stageFilter, $includeTestData);

        // Get total count
        $totalRecords = self::getTotalCount($db, $filter);

        // If no current ID, return defaults (unless we want to find the first ID for the search?)
        // If currentId is null but we have search results, logic usually handled by controller (index.php) finding the first ID
        if (!$currentId) {
            return [
                'totalRecords' => $totalRecords,
                'currentIndex' => 1,
                'prevId' => null,
                'nextId' => null
            ];
        }

        // Get current position
        $currentIndex = self::getCurrentPosition($db, $currentId, $filter);

        // Get prev/next IDs
        $prevId = self::getPreviousId($db, $currentId, $filter);
        $nextId = self::getNextId($db, $currentId, $filter);

        return [
            'totalRecords' => $totalRecords,
            'currentIndex' => $currentIndex,
            'prevId' => $prevId,
            'nextId' => $nextId
        ];
    }

    /**
     * Count records for a given filter scope.
     * This is the canonical count source used by list/navigation/statistics.
     */
    public static function countByFilter(
        PDO $db,
        string $statusFilter = 'all',
        ?string $searchTerm = null,
        ?string $stageFilter = null,
        bool $includeTestData = false
    ): int {
        $filter = self::buildFilterConditions($statusFilter, $searchTerm, $stageFilter, $includeTestData);
        return self::getTotalCount($db, $filter);
    }

    /**
     * Build SQL WHERE conditions based on status filter
     */
    public static function buildFilterConditions(
        string $filter,
        ?string $searchTerm = null,
        ?string $stageFilter = null,
        bool $includeTestData = false
    ): array
    {
        $visibility = GuaranteeVisibilityService::buildSqlFilter('g', 'd');
        $expiryDateExpr = "(g.raw_data::jsonb ->> 'expiry_date')";
        $forceActionableScope = self::shouldForceActionableScope();

        $settings = \App\Support\Settings::getInstance();
        $allowTestData = $includeTestData && !$settings->isProductionMode();
        $testDataFilter = $allowTestData ? '' : ' AND g.is_test_data = 0';

        // Role clamp: any role except data_entry/developer must stay in task scope only.
        if ($forceActionableScope) {
            if ($filter === 'released') {
                return [
                    'sql' => ' AND 1=0',
                    'params' => [],
                ];
            }
            if (!in_array($filter, ['actionable', 'data_entry'], true)) {
                $filter = 'actionable';
            }
        }

        // ✅ Search Mode: Overrides standard status filters
        if ($searchTerm) {
            $searchSafe = stripslashes($searchTerm);
            $searchAny = '%' . $searchSafe . '%';

            // Search in directly (Raw Data) AND Linked Official Names
            $searchSql = " AND (
                    g.guarantee_number LIKE :search_any OR
                    g.raw_data::text LIKE :search_any OR
                    s.official_name LIKE :search_any
                )";
            $params = [
                'search_any' => $searchAny,
            ];

            // When actionable clamp is forced, search must stay inside actionable predicate.
            if ($forceActionableScope) {
                $predicate = ActionabilityPolicyService::buildActionableSqlPredicate(
                    'd',
                    $stageFilter,
                    null,
                    'actionable_stage_nav'
                );
                $searchSql = ' AND (d.is_locked IS NULL OR d.is_locked = FALSE)' . $predicate['sql'] . $searchSql;
                $params = array_merge($params, $predicate['params']);
            }

            return [
                'sql' => $searchSql . $testDataFilter . $visibility['sql'],
                'params' => array_merge($params, $visibility['params']),
            ];
        }

        if ($filter === 'released') {
            $canViewReleased = Guard::has('reopen_guarantee')
                || Guard::has('manage_data')
                || Guard::has('manage_users');
            if (!$canViewReleased) {
                return [
                    'sql' => ' AND 1=0',
                    'params' => [],
                ];
            }

            // Show only released
            return [
                'sql' => ' AND d.is_locked = TRUE' . $testDataFilter . $visibility['sql'],
                'params' => $visibility['params'],
            ];
        } else {
            // Exclude released for other filters
            $conditions = ' AND (d.is_locked IS NULL OR d.is_locked = FALSE)';
            $params = $visibility['params'];

            // Apply specific status filter
            if ($filter === 'ready') {
                $conditions .= " AND d.status = 'ready'";
            } elseif ($filter === 'actionable') {
                $predicate = ActionabilityPolicyService::buildActionableSqlPredicate(
                    'd',
                    $stageFilter,
                    null,
                    'actionable_stage_nav'
                );
                $conditions .= $predicate['sql'];
                $params = array_merge($params, $predicate['params']);
            } elseif ($filter === 'pending') {
                $conditions .= " AND (d.id IS NULL OR d.status = 'pending')";
            } elseif ($filter === 'data_entry') {
                if (!Guard::has('manage_data')) {
                    return [
                        'sql' => ' AND 1=0',
                        'params' => [],
                    ];
                }
                // Data-entry task bucket: ready + draft + no active action selected yet.
                $conditions .= " AND d.status = 'ready'";
                $conditions .= " AND d.workflow_step = 'draft'";
                $conditions .= " AND (d.active_action IS NULL OR d.active_action = '')";
            } elseif ($filter === 'expiring_30') {
                $conditions .= " AND {$expiryDateExpr} IS NOT NULL AND ({$expiryDateExpr})::date BETWEEN CURRENT_DATE AND (CURRENT_DATE + INTERVAL '30 days')";
            } elseif ($filter === 'expiring_90') {
                $conditions .= " AND {$expiryDateExpr} IS NOT NULL AND ({$expiryDateExpr})::date BETWEEN CURRENT_DATE AND (CURRENT_DATE + INTERVAL '90 days')";
            }
            // 'all' filter has no additional conditions

            return [
                'sql' => $conditions . $testDataFilter . $visibility['sql'],
                'params' => $params,
            ];
        }
    }

    private static function shouldForceActionableScope(): bool
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
            $db = \App\Support\Database::connect();
            $roleRepo = new RoleRepository($db);
            $role = $roleRepo->find((int)$user->roleId);
            $roleSlug = trim((string)($role->slug ?? ''));
            $cached = !in_array($roleSlug, ['data_entry', 'developer'], true);
            return $cached;
        } catch (\Throwable) {
            $cached = false;
            return $cached;
        }
    }

    /**
     * Get total count of guarantees matching filter
     */
    private static function getTotalCount(PDO $db, array $filter): int
    {
        $query = '
            SELECT COUNT(*) FROM guarantees g
            LEFT JOIN guarantee_decisions d ON d.guarantee_id = g.id
            LEFT JOIN suppliers s ON d.supplier_id = s.id
            WHERE 1=1
        ' . $filter['sql'];

        $stmt = $db->prepare($query);
        $stmt->execute($filter['params']);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Get current position (1-indexed) of a guarantee in filtered list
     */
    private static function getCurrentPosition(PDO $db, int $currentId, array $filter): int
    {
        try {
            $query = '
                SELECT COUNT(*) as position
                FROM guarantees g
                LEFT JOIN guarantee_decisions d ON d.guarantee_id = g.id
                LEFT JOIN suppliers s ON d.supplier_id = s.id
                WHERE g.id < :current_id
            ' . $filter['sql'];

            $stmt = $db->prepare($query);
            $stmt->execute(array_merge(['current_id' => $currentId], $filter['params']));
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return ((int) ($result['position'] ?? 0)) + 1;
        } catch (\Exception $e) {
            return 1; // Default to first position on error
        }
    }

    /**
     * Get ID of previous guarantee in filtered list
     */
    private static function getPreviousId(PDO $db, int $currentId, array $filter): ?int
    {
        try {
            $query = '
                SELECT g.id FROM guarantees g
                LEFT JOIN guarantee_decisions d ON d.guarantee_id = g.id
                LEFT JOIN suppliers s ON d.supplier_id = s.id
                WHERE g.id < :current_id
            ' . $filter['sql'] . '
                ORDER BY g.id DESC LIMIT 1
            ';

            $stmt = $db->prepare($query);
            $stmt->execute(array_merge(['current_id' => $currentId], $filter['params']));
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return $result ? (int) $result['id'] : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get ID of next guarantee in filtered list
     */
    private static function getNextId(PDO $db, int $currentId, array $filter): ?int
    {
        try {
            $query = '
                SELECT g.id FROM guarantees g
                LEFT JOIN guarantee_decisions d ON d.guarantee_id = g.id
                LEFT JOIN suppliers s ON d.supplier_id = s.id
                WHERE g.id > :current_id
            ' . $filter['sql'] . '
                ORDER BY g.id ASC LIMIT 1
            ';

            $stmt = $db->prepare($query);
            $stmt->execute(array_merge(['current_id' => $currentId], $filter['params']));
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return $result ? (int) $result['id'] : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get ID of guarantee at specific 1-based index in filtered list
     */
    public static function getIdByIndex(
        PDO $db,
        int $index,
        string $statusFilter = 'all',
        ?string $searchTerm = null,
        ?string $stageFilter = null,
        bool $includeTestData = false
    ): ?int {
        if ($index < 1)
            return null;

        $filter = self::buildFilterConditions($statusFilter, $searchTerm, $stageFilter, $includeTestData);

        try {
            // Offset is index - 1
            $offset = $index - 1;

            $query = '
                SELECT g.id FROM guarantees g
                LEFT JOIN guarantee_decisions d ON d.guarantee_id = g.id
                LEFT JOIN suppliers s ON d.supplier_id = s.id
                WHERE 1=1
            ' . $filter['sql'] . '
                ORDER BY g.id ASC
                LIMIT 1 OFFSET :offset
            ';

            $stmt = $db->prepare($query);
            $stmt->execute(array_merge(['offset' => $offset], $filter['params']));
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return $result ? (int) $result['id'] : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Check whether a specific guarantee id belongs to current filtered scope.
     */
    public static function isIdInFilter(
        PDO $db,
        int $id,
        string $statusFilter = 'all',
        ?string $searchTerm = null,
        ?string $stageFilter = null,
        bool $includeTestData = false
    ): bool {
        $filter = self::buildFilterConditions($statusFilter, $searchTerm, $stageFilter, $includeTestData);

        $query = '
            SELECT 1
            FROM guarantees g
            LEFT JOIN guarantee_decisions d ON d.guarantee_id = g.id
            LEFT JOIN suppliers s ON d.supplier_id = s.id
            WHERE g.id = :current_id
        ' . $filter['sql'] . '
            LIMIT 1
        ';

        $stmt = $db->prepare($query);
        $stmt->execute(array_merge(['current_id' => $id], $filter['params']));
        return (bool)$stmt->fetchColumn();
    }
}
