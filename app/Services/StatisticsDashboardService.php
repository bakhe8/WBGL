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
}

