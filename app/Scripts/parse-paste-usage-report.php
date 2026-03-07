<?php
declare(strict_types=1);

/**
 * Parse Paste Usage Report
 *
 * Usage:
 *   php app/Scripts/parse-paste-usage-report.php
 *   php app/Scripts/parse-paste-usage-report.php --days=14
 */

require_once __DIR__ . '/../Support/autoload.php';

use App\Support\Database;
use App\Support\SchemaInspector;
use App\Support\Settings;

/**
 * @param array<int,string> $argv
 */
function wbglParsePasteResolveDays(array $argv): int
{
    foreach ($argv as $arg) {
        if (!is_string($arg)) {
            continue;
        }
        if (preg_match('/^--days=(\d{1,3})$/', trim($arg), $m) === 1) {
            $days = (int)$m[1];
            if ($days >= 1 && $days <= 365) {
                return $days;
            }
        }
    }
    return 7;
}

try {
    $days = wbglParsePasteResolveDays($argv ?? []);
    $since = date('Y-m-d H:i:s', time() - ($days * 86400));

    $db = Database::connect();
    if (!SchemaInspector::tableExists($db, 'audit_trail_events')) {
        fwrite(STDERR, "audit_trail_events table does not exist.\n");
        exit(1);
    }

    $stmt = $db->prepare(
        'SELECT COALESCE(target_id, \'unknown\') AS requested_version, COUNT(*)::bigint AS total
         FROM audit_trail_events
         WHERE event_type = ?
           AND created_at >= ?
         GROUP BY COALESCE(target_id, \'unknown\')
         ORDER BY requested_version ASC'
    );
    $stmt->execute(['parse_paste_endpoint_usage', $since]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $counts = [];
    $total = 0;
    foreach ($rows as $row) {
        $version = strtolower(trim((string)($row['requested_version'] ?? 'unknown')));
        $count = (int)($row['total'] ?? 0);
        if ($count < 0) {
            $count = 0;
        }
        $counts[$version] = ($counts[$version] ?? 0) + $count;
        $total += $count;
    }

    $v1 = (int)($counts['v1'] ?? 0);
    $v2 = (int)($counts['v2'] ?? 0);
    $v1Ratio = $total > 0 ? round(($v1 / $total) * 100, 2) : 0.0;

    $settings = Settings::getInstance();
    $safeThreshold = (float)$settings->get('PARSE_PASTE_V1_SAFE_THRESHOLD_PERCENT', 5);
    $safeReached = $total === 0 ? true : ($v1Ratio <= $safeThreshold);

    echo "WBGL Parse-Paste Usage Report\n";
    echo "Window days: {$days}\n";
    echo "Since: {$since}\n";
    echo "Total tracked requests: {$total}\n";
    echo "V1 requests: {$v1}\n";
    echo "V2 requests: {$v2}\n";
    echo "V1 ratio: {$v1Ratio}%\n";
    echo "Safe threshold: {$safeThreshold}%\n";
    echo "Safe to retire V1: " . ($safeReached ? 'YES' : 'NO') . "\n";

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Parse-paste usage report failed: {$e->getMessage()}\n");
    exit(1);
}
