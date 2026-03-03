<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * NavigationService
 * 
 * Handles navigation and pagination logic for guarantees list
 * Supports filtering by status (all, ready, pending, released)
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
     * @param string $statusFilter Filter: 'all', 'ready', 'pending', 'released'
     * @param string|null $searchTerm Search query if active
     * @return array Navigation data with totalRecords, currentIndex, prevId, nextId
     */
    public static function getNavigationInfo(
        PDO $db,
        ?int $currentId,
        string $statusFilter = 'all',
        ?string $searchTerm = null,
        ?string $stageFilter = null
    ): array {
        $filter = self::buildFilterConditions($statusFilter, $searchTerm, $stageFilter);

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
        ?string $stageFilter = null
    ): int {
        $filter = self::buildFilterConditions($statusFilter, $searchTerm, $stageFilter);
        return self::getTotalCount($db, $filter);
    }

    /**
     * Build SQL WHERE conditions based on status filter
     */
    public static function buildFilterConditions(
        string $filter,
        ?string $searchTerm = null,
        ?string $stageFilter = null
    ): array
    {
        $visibility = GuaranteeVisibilityService::buildSqlFilter('g', 'd');
        $expiryDateExpr = "(g.raw_data::jsonb ->> 'expiry_date')";

        // Production Mode: Check if we should exclude test data
        $settings = \App\Support\Settings::getInstance();
        $testDataFilter = '';
        if ($settings->isProductionMode()) {
            $testDataFilter = ' AND g.is_test_data = 0';
        }

        // ✅ Search Mode: Overrides standard status filters
        if ($searchTerm) {
            $searchSafe = stripslashes($searchTerm);
            $searchAny = '%' . $searchSafe . '%';

            // Search in directly (Raw Data) AND Linked Official Names
            return [
                'sql' => " AND (
                    g.guarantee_number LIKE :search_any OR
                    g.raw_data LIKE :search_any OR
                    s.official_name LIKE :search_any
                )" . $testDataFilter . $visibility['sql'],
                'params' => array_merge([
                    'search_any' => $searchAny,
                ], $visibility['params']),
            ];
        }

        if ($filter === 'released') {
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
        ?string $stageFilter = null
    ): ?int {
        if ($index < 1)
            return null;

        $filter = self::buildFilterConditions($statusFilter, $searchTerm, $stageFilter);

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
        ?string $stageFilter = null
    ): bool {
        $filter = self::buildFilterConditions($statusFilter, $searchTerm, $stageFilter);

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
