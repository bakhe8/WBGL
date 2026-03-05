<?php
declare(strict_types=1);

require_once __DIR__ . '/../Support/autoload.php';

use App\Support\Database;
use App\Support\Settings;

$db = Database::connect();
$settings = Settings::getInstance();

echo "WBGL Pending Breakdown\n";
echo "production_mode=" . ($settings->isProductionMode() ? '1' : '0') . "\n\n";

// Approximate pending filter used by index:
// - unreleased (not locked)
// - missing decision OR decision status = pending
$sql = "
    SELECT
        g.import_source,
        COUNT(*) AS total_count,
        SUM(CASE WHEN COALESCE(g.is_test_data,0)=1 THEN 1 ELSE 0 END) AS test_count,
        SUM(CASE WHEN COALESCE(g.is_test_data,0)=0 THEN 1 ELSE 0 END) AS real_count
    FROM guarantees g
    LEFT JOIN guarantee_decisions d ON d.guarantee_id = g.id
    WHERE (d.is_locked IS NULL OR d.is_locked = FALSE)
      AND (d.id IS NULL OR d.status = 'pending')
    GROUP BY g.import_source
    ORDER BY total_count DESC
    LIMIT 40
";

$stmt = $db->query($sql);
$rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

$sumTotal = 0;
$sumTest = 0;
$sumReal = 0;
foreach ($rows as $row) {
    $sumTotal += (int)($row['total_count'] ?? 0);
    $sumTest += (int)($row['test_count'] ?? 0);
    $sumReal += (int)($row['real_count'] ?? 0);
}

echo "Top sources in pending (first 40):\n";
foreach ($rows as $row) {
    echo "- ", (string)$row['import_source'],
        " | total=", (string)$row['total_count'],
        " | test=", (string)$row['test_count'],
        " | real=", (string)$row['real_count'],
        "\n";
}

echo "\nSubtotal (top40): total={$sumTotal} test={$sumTest} real={$sumReal}\n";

$exactSql = "
    SELECT
        COUNT(*) AS total_count,
        SUM(CASE WHEN COALESCE(g.is_test_data,0)=1 THEN 1 ELSE 0 END) AS test_count,
        SUM(CASE WHEN COALESCE(g.is_test_data,0)=0 THEN 1 ELSE 0 END) AS real_count
    FROM guarantees g
    LEFT JOIN guarantee_decisions d ON d.guarantee_id = g.id
    WHERE (d.is_locked IS NULL OR d.is_locked = FALSE)
      AND (d.id IS NULL OR d.status = 'pending')
";
$exact = $db->query($exactSql);
$exactRow = $exact ? $exact->fetch(PDO::FETCH_ASSOC) : ['total_count' => 0, 'test_count' => 0, 'real_count' => 0];
echo "Exact pending: total=" . (int)$exactRow['total_count']
    . " test=" . (int)$exactRow['test_count']
    . " real=" . (int)$exactRow['real_count'] . "\n";
