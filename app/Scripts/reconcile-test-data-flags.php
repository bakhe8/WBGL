<?php
declare(strict_types=1);

require_once __DIR__ . '/../Support/autoload.php';

use App\Support\Database;

/**
 * Reconcile historical records that are very likely test data but were saved
 * without is_test_data flag.
 *
 * Usage:
 *   php app/Scripts/reconcile-test-data-flags.php
 *   php app/Scripts/reconcile-test-data-flags.php --apply
 *   php app/Scripts/reconcile-test-data-flags.php --apply --sources=manual_paste_20260214,manual_paste_20260304
 *   php app/Scripts/reconcile-test-data-flags.php --apply --sources=manual_paste_20260304 --allow-manual-sources
 */

$argv = $_SERVER['argv'] ?? [];
$apply = in_array('--apply', $argv, true);
$allowManualSources = in_array('--allow-manual-sources', $argv, true);

$explicitSources = [];
foreach ($argv as $arg) {
    if (str_starts_with((string)$arg, '--sources=')) {
        $csv = substr((string)$arg, strlen('--sources='));
        $explicitSources = array_values(array_filter(array_map(
            static fn($v): string => trim((string)$v),
            explode(',', (string)$csv)
        ), static fn(string $v): bool => $v !== ''));
    }
}

$db = Database::connect();

$sourcePredicates = [
    "LOWER(g.import_source) = 'integration_flow'",
    "LOWER(g.import_source) LIKE '%copyof%'",
    "LOWER(g.import_source) LIKE '%book1%'",
    "LOWER(g.import_source) LIKE '%sim_import%'",
    "LOWER(g.import_source) LIKE 'test\\_%' ESCAPE '\\'",
    "LOWER(g.import_source) LIKE 'test data%'",
    "LOWER(g.import_source) = 'email_import_draft'",
];

if (!empty($explicitSources)) {
    foreach ($explicitSources as $src) {
        $normalized = strtolower($src);
        $looksManual = str_starts_with($normalized, 'manual_paste_')
            || str_starts_with($normalized, 'excel_')
            || $normalized === 'smart paste'
            || $normalized === 'manual'
            || str_contains($normalized, 'manual');

        if ($looksManual && !$allowManualSources) {
            fwrite(STDERR, "Skipped manual-like source without --allow-manual-sources: {$src}\n");
            continue;
        }

        $safe = str_replace("'", "''", $src);
        $sourcePredicates[] = "g.import_source = '{$safe}'";
    }
}

$reasonPredicate = implode(" OR\n            ", $sourcePredicates);

$candidateSql = "
    SELECT DISTINCT
        g.id,
        g.import_source
    FROM guarantees g
    LEFT JOIN guarantee_occurrences o ON o.guarantee_id = g.id
    LEFT JOIN batch_metadata bm ON bm.import_source = o.batch_identifier
    WHERE COALESCE(g.is_test_data,0) = 0
      AND (
            {$reasonPredicate}
         OR LOWER(COALESCE(bm.batch_name,'')) LIKE '%test%'
         OR COALESCE(bm.batch_name,'') LIKE '%اختبار%'
         OR LOWER(COALESCE(bm.batch_notes,'')) LIKE '%test%'
         OR COALESCE(bm.batch_notes,'') LIKE '%اختبار%'
      )
    ORDER BY g.id
";

$stmt = $db->query($candidateSql);
$candidates = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
$ids = array_map(static fn(array $r): int => (int)$r['id'], $candidates);

$bySource = [];
foreach ($candidates as $row) {
    $src = (string)($row['import_source'] ?? '');
    if (!isset($bySource[$src])) {
        $bySource[$src] = 0;
    }
    $bySource[$src]++;
}
arsort($bySource);

echo "WBGL Test Data Reconciliation\n";
echo "Mode: " . ($apply ? 'APPLY' : 'DRY_RUN') . "\n";
echo "Candidates: " . count($ids) . "\n";
echo "Sources:\n";
foreach ($bySource as $src => $count) {
    echo " - {$src}: {$count}\n";
}

if (!$apply || empty($ids)) {
    if (!$apply) {
        echo "No changes applied. Re-run with --apply to persist.\n";
    }
    exit(0);
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$note = 'reconciled_by_script:' . date('Y-m-d H:i:s');

$db->beginTransaction();
try {
    $update = $db->prepare("
        UPDATE guarantees
        SET is_test_data = 1,
            test_note = CASE
                WHEN test_note IS NULL OR test_note = '' THEN ?
                ELSE test_note
            END
        WHERE id IN ({$placeholders})
    ");
    $params = array_merge([$note], $ids);
    $update->execute($params);
    $affected = (int)$update->rowCount();

    $db->commit();
    echo "Updated rows: {$affected}\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, "Failed: " . $e->getMessage() . "\n");
    exit(1);
}
