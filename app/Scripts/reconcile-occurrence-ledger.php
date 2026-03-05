<?php
declare(strict_types=1);

/**
 * Reconcile guarantees missing occurrence rows.
 *
 * Usage:
 *   php app/Scripts/reconcile-occurrence-ledger.php
 *   php app/Scripts/reconcile-occurrence-ledger.php --apply
 *   php app/Scripts/reconcile-occurrence-ledger.php --apply --scope=all
 *   php app/Scripts/reconcile-occurrence-ledger.php --apply --scope=real
 *
 * Notes:
 * - Dry-run is default (no writes).
 * - Default scope is test-only for safety.
 */

require_once __DIR__ . '/../Support/autoload.php';

use App\Services\ImportService;
use App\Support\Database;

/**
 * @param array<string, string|false>|false $options
 */
function optionValue($options, string $key, string $default = ''): string
{
    if (!is_array($options) || !array_key_exists($key, $options)) {
        return $default;
    }
    $raw = $options[$key];
    if ($raw === false) {
        return $default;
    }
    return trim((string)$raw);
}

function resolveBatchType(string $importSource): string
{
    $src = strtolower(trim($importSource));
    if ($src === '') {
        return 'repair';
    }
    if (str_starts_with($src, 'excel_') || str_contains($src, 'excel')) {
        return 'excel';
    }
    if (str_starts_with($src, 'manual_') || str_contains($src, 'paste')) {
        return 'manual';
    }
    if (str_contains($src, 'email')) {
        return 'email';
    }
    if (str_contains($src, 'integration')) {
        return 'integration';
    }
    return 'repair';
}

try {
    /** @var array<string, string|false>|false $options */
    $options = getopt('', ['apply', 'scope::']);
    $apply = is_array($options) && array_key_exists('apply', $options);
    $scope = strtolower(optionValue($options, 'scope', 'test'));
    if (!in_array($scope, ['test', 'real', 'all'], true)) {
        throw new RuntimeException("Invalid --scope value. Allowed: test|real|all");
    }

    $db = Database::connect();

    $where = [];
    $params = [];
    if ($scope === 'test') {
        $where[] = 'COALESCE(g.is_test_data, 0) = 1';
    } elseif ($scope === 'real') {
        $where[] = 'COALESCE(g.is_test_data, 0) = 0';
    }
    $whereSql = $where ? (' AND ' . implode(' AND ', $where)) : '';

    $sql = "
        SELECT
            g.id,
            COALESCE(g.import_source, '') AS import_source,
            g.imported_at,
            COALESCE(g.is_test_data, 0) AS is_test_data
        FROM guarantees g
        WHERE NOT EXISTS (
            SELECT 1
            FROM guarantee_occurrences o
            WHERE o.guarantee_id = g.id
        )
        {$whereSql}
        ORDER BY g.id ASC
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        $rows = [];
    }

    echo "WBGL Occurrence Ledger Reconciliation\n";
    echo "Mode: " . ($apply ? 'APPLY' : 'DRY-RUN') . "\n";
    echo "Scope: {$scope}\n";
    echo "Missing guarantees: " . count($rows) . "\n\n";

    $grouped = [];
    foreach ($rows as $row) {
        $importSource = trim((string)($row['import_source'] ?? ''));
        if ($importSource === '') {
            $importSource = '__MISSING_IMPORT_SOURCE__';
        }
        $grouped[$importSource] = (int)($grouped[$importSource] ?? 0) + 1;
    }

    arsort($grouped);
    echo "Top import_source (missing occurrence):\n";
    foreach (array_slice($grouped, 0, 20, true) as $source => $count) {
        echo "- {$source}: {$count}\n";
    }
    echo "\n";

    if (!$apply || count($rows) === 0) {
        echo $apply
            ? "Nothing to apply.\n"
            : "Dry-run only. Re-run with --apply to write occurrences.\n";
        exit(0);
    }

    $applied = 0;
    $db->beginTransaction();
    try {
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $batchIdentifier = trim((string)($row['import_source'] ?? ''));
            if ($batchIdentifier === '') {
                $batchIdentifier = 'legacy_orphan_' . date('Ymd');
            }
            $isTestData = (int)($row['is_test_data'] ?? 0) === 1;
            $resolvedBatch = ImportService::resolveCompatibleBatchIdentifier($db, $batchIdentifier, $isTestData);
            if ($resolvedBatch !== $batchIdentifier) {
                $updateImportSource = $db->prepare('UPDATE guarantees SET import_source = ? WHERE id = ?');
                $updateImportSource->execute([$resolvedBatch, $id]);
            }
            $type = resolveBatchType($resolvedBatch);
            $occurredAt = trim((string)($row['imported_at'] ?? ''));
            if ($occurredAt === '') {
                $occurredAt = date('Y-m-d H:i:s');
            }

            ImportService::recordOccurrence($id, $resolvedBatch, $type, $occurredAt, $db);
            $applied++;
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    echo "Applied occurrences: {$applied}\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Occurrence reconciliation failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
