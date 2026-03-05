<?php
declare(strict_types=1);

require_once __DIR__ . '/../Support/autoload.php';

use App\Services\NavigationService;
use App\Support\Database;
use App\Support\Settings;

$db = Database::connect();
$settings = Settings::getInstance();

function scalar(PDO $db, string $sql): int
{
    $stmt = $db->query($sql);
    return (int)($stmt ? $stmt->fetchColumn() : 0);
}

$rows = [
    'production_mode' => $settings->isProductionMode() ? 1 : 0,
    'guarantees_total' => scalar($db, "SELECT COUNT(*) FROM guarantees"),
    'guarantees_test_flag_1' => scalar($db, "SELECT COUNT(*) FROM guarantees WHERE COALESCE(is_test_data,0)=1"),
    'guarantees_test_flag_0' => scalar($db, "SELECT COUNT(*) FROM guarantees WHERE COALESCE(is_test_data,0)=0"),
    'import_source_test_prefix' => scalar($db, "SELECT COUNT(*) FROM guarantees WHERE import_source LIKE 'test\\_%' ESCAPE '\\'"),
    'import_source_test_prefix_but_real_flag' => scalar($db, "SELECT COUNT(*) FROM guarantees WHERE import_source LIKE 'test\\_%' ESCAPE '\\' AND COALESCE(is_test_data,0)=0"),
    'has_test_batch_id' => scalar($db, "SELECT COUNT(*) FROM guarantees WHERE test_batch_id IS NOT NULL AND test_batch_id <> ''"),
    'has_test_batch_id_but_real_flag' => scalar($db, "SELECT COUNT(*) FROM guarantees WHERE test_batch_id IS NOT NULL AND test_batch_id <> '' AND COALESCE(is_test_data,0)=0"),
    'batch_name_contains_test_word_real_flag' => scalar(
        $db,
        "SELECT COUNT(DISTINCT g.id)
         FROM guarantees g
         JOIN guarantee_occurrences o ON o.guarantee_id = g.id
         JOIN batch_metadata bm ON bm.import_source = o.batch_identifier
         WHERE COALESCE(g.is_test_data,0)=0
           AND (
             LOWER(COALESCE(bm.batch_name,'')) LIKE '%test%'
             OR COALESCE(bm.batch_name,'') LIKE '%اختبار%'
             OR LOWER(COALESCE(bm.batch_notes,'')) LIKE '%test%'
             OR COALESCE(bm.batch_notes,'') LIKE '%اختبار%'
           )"
    ),
    'manual_or_excel_real_flag' => scalar(
        $db,
        "SELECT COUNT(*) FROM guarantees
         WHERE COALESCE(is_test_data,0)=0
           AND (
             import_source LIKE 'manual\\_%' ESCAPE '\\'
             OR import_source LIKE 'excel\\_%' ESCAPE '\\'
           )"
    ),
    'nav_all_excluding_test' => NavigationService::countByFilter($db, 'all', null, null, false),
    'nav_all_including_test' => NavigationService::countByFilter($db, 'all', null, null, true),
    'nav_released_excluding_test' => NavigationService::countByFilter($db, 'released', null, null, false),
    'nav_released_including_test' => NavigationService::countByFilter($db, 'released', null, null, true),
    'pure_test_batches_by_flag' => scalar(
        $db,
        "SELECT COUNT(*) FROM (
            SELECT o.batch_identifier
            FROM guarantee_occurrences o
            JOIN guarantees g ON g.id = o.guarantee_id
            GROUP BY o.batch_identifier
            HAVING SUM(CASE WHEN COALESCE(g.is_test_data,0)=0 THEN 1 ELSE 0 END)=0
               AND SUM(CASE WHEN COALESCE(g.is_test_data,0)=1 THEN 1 ELSE 0 END)>0
        ) t"
    ),
];

echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

$topStmt = $db->query("
    SELECT
        import_source,
        COUNT(*) AS total_count,
        SUM(CASE WHEN COALESCE(is_test_data,0)=1 THEN 1 ELSE 0 END) AS test_count,
        SUM(CASE WHEN COALESCE(is_test_data,0)=0 THEN 1 ELSE 0 END) AS real_count
    FROM guarantees
    GROUP BY import_source
    ORDER BY total_count DESC
    LIMIT 20
");

$top = $topStmt ? $topStmt->fetchAll(PDO::FETCH_ASSOC) : [];
echo PHP_EOL . 'Top import_source buckets:' . PHP_EOL;
foreach ($top as $item) {
    echo '- ', (string)$item['import_source'],
        ' | total=', (string)$item['total_count'],
        ' | test=', (string)$item['test_count'],
        ' | real=', (string)$item['real_count'],
        PHP_EOL;
}

$metaStmt = $db->query("
    SELECT
        bm.import_source,
        bm.batch_name,
        bm.batch_notes,
        bm.status
    FROM batch_metadata bm
    WHERE bm.import_source IN (
        SELECT import_source
        FROM guarantees
        WHERE COALESCE(is_test_data,0)=0
        GROUP BY import_source
        ORDER BY COUNT(*) DESC
        LIMIT 12
    )
    ORDER BY bm.import_source
");
$metaRows = $metaStmt ? $metaStmt->fetchAll(PDO::FETCH_ASSOC) : [];
echo PHP_EOL . 'Batch metadata for top real import_source buckets:' . PHP_EOL;
foreach ($metaRows as $meta) {
    echo '- ', (string)($meta['import_source'] ?? ''),
        ' | name=', (string)($meta['batch_name'] ?? ''),
        ' | notes=', (string)($meta['batch_notes'] ?? ''),
        ' | status=', (string)($meta['status'] ?? ''),
        PHP_EOL;
}
