<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * StatisticsDashboardService
 *
 * Centralizes statistics dashboard data-access blocks to keep view files
 * focused on rendering concerns.
 */
final class StatisticsDashboardService
{
    /**
     * Fetch global overview metrics used at the top of statistics dashboard.
     *
     * @return array<string,mixed>
     */
    public static function fetchOverview(
        PDO $db,
        bool $isProd,
        string $whereD,
        string $andD,
        string $statsJsonRawExpiryDateExpr,
        string $statsNowDateExpr,
        string $statsJsonRawAmountExpr
    ): array {
        $occurrencesQuery = $isProd
            ? "SELECT COUNT(*) FROM guarantee_occurrences o JOIN guarantees g ON o.guarantee_id = g.id WHERE g.is_test_data = 0"
            : "SELECT COUNT(*) FROM guarantee_occurrences o JOIN guarantees g ON o.guarantee_id = g.id";

        $sql = "
            SELECT
                (SELECT COUNT(*) FROM guarantees {$whereD}) as total_assets,
                ({$occurrencesQuery}) as total_occurrences,
                (SELECT COUNT(*) FROM guarantees WHERE {$statsJsonRawExpiryDateExpr} >= {$statsNowDateExpr} {$andD}) as active_assets,
                (SELECT COUNT(*) FROM batch_metadata WHERE status='active') as active_batches,
                (SELECT SUM(CAST({$statsJsonRawAmountExpr} AS REAL)) FROM guarantees {$whereD}) as total_amount,
                (SELECT AVG(CAST({$statsJsonRawAmountExpr} AS REAL)) FROM guarantees {$whereD}) as avg_amount,
                (SELECT MAX(CAST({$statsJsonRawAmountExpr} AS REAL)) FROM guarantees {$whereD}) as max_amount,
                (SELECT MIN(CAST({$statsJsonRawAmountExpr} AS REAL)) FROM guarantees {$whereD}) as min_amount
        ";

        $row = $db->query($sql)->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return [
                'total_assets' => 0,
                'total_occurrences' => 0,
                'active_assets' => 0,
                'active_batches' => 0,
                'total_amount' => 0,
                'avg_amount' => 0,
                'max_amount' => 0,
                'min_amount' => 0,
            ];
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $overview
     */
    public static function calculateEfficiencyRatio(array $overview): float
    {
        $totalAssets = (float)($overview['total_assets'] ?? 0);
        $totalOccurrences = (float)($overview['total_occurrences'] ?? 0);
        if ($totalAssets <= 0) {
            return 1.0;
        }

        return round($totalOccurrences / $totalAssets, 2);
    }

    /**
     * Fetch statistics blocks for batch operations and suppliers/banks.
     *
     * @return array<string,mixed>
     */
    public static function fetchBatchAndSupplierBlocks(
        PDO $db,
        string $whereG,
        string $andG,
        string $statsBatchPrefixMarker,
        string $statsUnknownMarker,
        string $statsJsonAmountExpr
    ): array {
        $batchStats = self::fetchAll($db, "
            SELECT
                o.batch_identifier,
                COALESCE(MAX(m.batch_name), '{$statsBatchPrefixMarker}' || SUBSTR(o.batch_identifier, 1, 15)) as batch_name,
                MAX(o.occurred_at) as import_date,
                COUNT(o.id) as total_rows,
                SUM(CASE WHEN g.import_source = o.batch_identifier THEN 1 ELSE 0 END) as new_items,
                SUM(CASE WHEN g.import_source != o.batch_identifier THEN 1 ELSE 0 END) as recurring_items
            FROM guarantee_occurrences o
            JOIN guarantees g ON o.guarantee_id = g.id
            LEFT JOIN batch_metadata m ON o.batch_identifier = m.import_source
            {$whereG}
            GROUP BY o.batch_identifier
            ORDER BY import_date DESC
            LIMIT 5
        ");

        $topRecurring = self::fetchAll($db, "
            SELECT
                g.guarantee_number,
                MAX(COALESCE(s.official_name, '{$statsUnknownMarker}')) as supplier,
                MAX(COALESCE(b.arabic_name, '{$statsUnknownMarker}')) as bank,
                COUNT(o.id) as occurrence_count,
                MAX(g.imported_at) as imported_at
            FROM guarantee_occurrences o
            JOIN guarantees g ON o.guarantee_id = g.id
            LEFT JOIN guarantee_decisions d ON g.id = d.guarantee_id
            LEFT JOIN suppliers s ON d.supplier_id = s.id
            LEFT JOIN banks b ON d.bank_id = b.id
            {$whereG}
            GROUP BY g.id, g.guarantee_number
            ORDER BY occurrence_count DESC, imported_at DESC
            LIMIT 5
        ");

        $topSuppliers = self::fetchAll($db, "
            SELECT s.official_name, COUNT(*) as count
            FROM guarantee_decisions d
            JOIN suppliers s ON d.supplier_id = s.id
            JOIN guarantees g ON d.guarantee_id = g.id
            {$whereG}
            GROUP BY s.id
            ORDER BY count DESC
            LIMIT 10
        ");

        $topBanks = self::fetchAll($db, "
            SELECT
                b.arabic_name as bank_name,
                COUNT(*) as count,
                SUM(CAST({$statsJsonAmountExpr} AS REAL)) as total_amount,
                (SELECT COUNT(*) FROM guarantee_history h
                 JOIN guarantee_decisions d2 ON h.guarantee_id = d2.guarantee_id
                 WHERE d2.bank_id = b.id AND h.event_subtype = 'extension') as extensions
            FROM guarantee_decisions d
            JOIN banks b ON d.bank_id = b.id
            JOIN guarantees g ON d.guarantee_id = g.id
            {$whereG}
            GROUP BY b.id
            ORDER BY count DESC
            LIMIT 10
        ");

        $stableSuppliers = self::fetchAll($db, "
            SELECT s.official_name, COUNT(DISTINCT d.guarantee_id) as count
            FROM suppliers s
            JOIN guarantee_decisions d ON s.id = d.supplier_id
            JOIN guarantees g ON d.guarantee_id = g.id
            {$whereG}
            GROUP BY s.id
            ORDER BY count DESC
            LIMIT 5
        ");

        $riskySuppliers = self::fetchAll($db, "
            SELECT
                s.official_name,
                COUNT(DISTINCT d.guarantee_id) as total,
                COUNT(DISTINCT CASE WHEN h.event_subtype = 'extension' THEN h.guarantee_id END) as extensions,
                COUNT(DISTINCT CASE WHEN h.event_subtype = 'reduction' THEN h.guarantee_id END) as reductions,
                ROUND(((CAST(COUNT(DISTINCT CASE WHEN h.event_subtype = 'extension' THEN h.guarantee_id END) AS REAL) * 0.6 +
                       CAST(COUNT(DISTINCT CASE WHEN h.event_subtype = 'reduction' THEN h.guarantee_id END) AS REAL) * 0.4) /
                       CAST(COUNT(DISTINCT d.guarantee_id) AS REAL) * 100)::numeric, 1) as risk_score
            FROM suppliers s
            JOIN guarantee_decisions d ON s.id = d.supplier_id
            JOIN guarantees g ON d.guarantee_id = g.id
            LEFT JOIN guarantee_history h ON d.guarantee_id = h.guarantee_id
            {$whereG}
            GROUP BY s.id
            HAVING COUNT(DISTINCT d.guarantee_id) >= 2
            ORDER BY risk_score DESC
            LIMIT 10
        ");

        $challengingSuppliers = self::fetchAll($db, "
            SELECT s.official_name, COUNT(*) as manual_count
            FROM guarantee_decisions d
            JOIN suppliers s ON d.supplier_id = s.id
            JOIN guarantees g ON d.guarantee_id = g.id
            WHERE (d.decision_source = 'manual' OR d.decision_source IS NULL)
            {$andG}
            GROUP BY s.id
            ORDER BY manual_count DESC
            LIMIT 5
        ");

        $uniqueCounts = self::fetchRow($db, "
            SELECT
                COUNT(DISTINCT d.supplier_id) as suppliers,
                COUNT(DISTINCT d.bank_id) as banks
            FROM guarantee_decisions d
            JOIN guarantees g ON d.guarantee_id = g.id
            {$whereG}
        ", ['suppliers' => 0, 'banks' => 0]);

        $bankSupplierPairs = self::fetchAll($db, "
            SELECT
                b.arabic_name as bank,
                s.official_name as supplier,
                COUNT(*) as count
            FROM guarantee_decisions d
            JOIN banks b ON d.bank_id = b.id
            JOIN suppliers s ON d.supplier_id = s.id
            JOIN guarantees g ON d.guarantee_id = g.id
            {$whereG}
            GROUP BY b.id, s.id
            ORDER BY count DESC
            LIMIT 10
        ");

        $exclusiveSuppliers = self::fetchColumnInt($db, "
            SELECT COUNT(*) FROM (
                SELECT d.supplier_id
                FROM guarantee_decisions d
                JOIN guarantees g ON d.guarantee_id = g.id
                {$whereG}
                GROUP BY d.supplier_id
                HAVING COUNT(DISTINCT d.bank_id) = 1
            )
        ");

        return [
            'batchStats' => $batchStats,
            'topRecurring' => $topRecurring,
            'topSuppliers' => $topSuppliers,
            'topBanks' => $topBanks,
            'stableSuppliers' => $stableSuppliers,
            'riskySuppliers' => $riskySuppliers,
            'challengingSuppliers' => $challengingSuppliers,
            'uniqueCounts' => $uniqueCounts,
            'bankSupplierPairs' => $bankSupplierPairs,
            'exclusiveSuppliers' => $exclusiveSuppliers,
        ];
    }

    /**
     * Fetch time/performance query blocks used in statistics dashboard.
     *
     * @return array<string,mixed>
     */
    public static function fetchTimePerformanceBlocks(
        PDO $db,
        string $whereG,
        string $andG,
        string $whereD,
        string $statsDurationHoursExpr,
        string $statsMinus7DateExpr,
        string $statsMinus14DateExpr
    ): array {
        $timing = self::fetchRow($db, "
            SELECT
                AVG(CAST({$statsDurationHoursExpr} AS REAL)) as avg_hours,
                MIN(CAST({$statsDurationHoursExpr} AS REAL)) as min_hours,
                MAX(CAST({$statsDurationHoursExpr} AS REAL)) as max_hours
            FROM guarantee_decisions d
            JOIN guarantees g ON d.guarantee_id = g.id
            WHERE d.decided_at IS NOT NULL {$andG}
        ", ['avg_hours' => 0, 'min_hours' => 0, 'max_hours' => 0]);

        $peakHour = self::fetchRow($db, "
            SELECT to_char(CAST(h.created_at AS timestamp), 'HH24') as hour, COUNT(*) as count
            FROM guarantee_history h
            JOIN guarantees g ON h.guarantee_id = g.id
            {$whereG}
            GROUP BY hour
            ORDER BY count DESC
            LIMIT 1
        ", ['hour' => 'N/A', 'count' => 0]);

        $qualityMetrics = self::fetchRow($db, "
            SELECT
                COUNT(DISTINCT g.id) as total,
                COUNT(DISTINCT CASE WHEN h.id IS NULL THEN g.id END) as ftr,
                COUNT(DISTINCT CASE WHEN (SELECT COUNT(*) FROM guarantee_history h2
                                          WHERE h2.guarantee_id = g.id AND h2.event_type = 'modified') >= 3
                              THEN g.id END) as complex
            FROM guarantees g
            LEFT JOIN guarantee_history h ON g.id = h.guarantee_id AND h.event_type = 'modified'
            {$whereG}
        ", ['total' => 0, 'ftr' => 0, 'complex' => 0]);

        $busiestDay = self::fetchRow($db, "
            SELECT
                CAST(EXTRACT(DOW FROM CAST(h.created_at AS timestamp)) AS INTEGER) as weekday_num,
                COUNT(*) as count
            FROM guarantee_history h
            JOIN guarantees g ON h.guarantee_id = g.id
            {$whereG}
            GROUP BY weekday_num
            ORDER BY count DESC
            LIMIT 1
        ", ['weekday_num' => -1, 'count' => 0]);

        $weeklyTrend = self::fetchRow($db, "
            SELECT
                COUNT(CASE WHEN imported_at >= {$statsMinus7DateExpr} THEN 1 END) as this_week,
                COUNT(CASE WHEN imported_at >= {$statsMinus14DateExpr} AND imported_at < {$statsMinus7DateExpr} THEN 1 END) as last_week
            FROM guarantees
            {$whereD}
        ", ['this_week' => 0, 'last_week' => 0]);

        return [
            'timing' => $timing,
            'peakHour' => $peakHour,
            'qualityMetrics' => $qualityMetrics,
            'busiestDay' => $busiestDay,
            'weeklyTrend' => $weeklyTrend,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function fetchAll(PDO $db, string $sql): array
    {
        $stmt = $db->query($sql);
        if ($stmt === false) {
            return [];
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array<string,mixed> $default
     * @return array<string,mixed>
     */
    private static function fetchRow(PDO $db, string $sql, array $default = []): array
    {
        $stmt = $db->query($sql);
        if ($stmt === false) {
            return $default;
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : $default;
    }

    private static function fetchColumnInt(PDO $db, string $sql): int
    {
        $stmt = $db->query($sql);
        if ($stmt === false) {
            return 0;
        }
        return (int)$stmt->fetchColumn();
    }
}
