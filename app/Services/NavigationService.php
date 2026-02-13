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
        ?string $searchTerm = null
    ): array {
        $filter = self::buildFilterConditions($statusFilter, $searchTerm);
        
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
     * Build SQL WHERE conditions based on status filter
     */
    private static function buildFilterConditions(string $filter, ?string $searchTerm = null): array
    {
        // Production Mode: Check if we should exclude test data
        $settings = \App\Support\Settings::getInstance();
        $testDataFilter = '';
        if ($settings->isProductionMode()) {
            $testDataFilter = ' AND (g.is_test_data = 0 OR g.is_test_data IS NULL)';
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
                )" . $testDataFilter,
                'params' => [
                    'search_any' => $searchAny,
                ],
            ];
        }
        
        if ($filter === 'released') {
            // Show only released
            return [
                'sql' => ' AND d.is_locked = 1' . $testDataFilter,
                'params' => [],
            ];
        } else {
            // Exclude released for other filters
            $conditions = ' AND (d.is_locked IS NULL OR d.is_locked = 0)';
            
            // Apply specific status filter
            if ($filter === 'ready') {
                $conditions .= ' AND d.status = "ready"';
            } elseif ($filter === 'pending') {
                $conditions .= ' AND (d.id IS NULL OR d.status = "pending")';
            }
            // 'all' filter has no additional conditions
            
            return [
                'sql' => $conditions . $testDataFilter,
                'params' => [],
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
        
        return (int)$stmt->fetchColumn();
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
            
            return ((int)($result['position'] ?? 0)) + 1;
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
            
            return $result ? (int)$result['id'] : null;
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
            
            return $result ? (int)$result['id'] : null;
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
        ?string $searchTerm = null
    ): ?int {
        if ($index < 1) return null;
        
        $filter = self::buildFilterConditions($statusFilter, $searchTerm);
        
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
            
            return $result ? (int)$result['id'] : null;
        } catch (\Exception $e) {
            return null;
        }
    }
}
