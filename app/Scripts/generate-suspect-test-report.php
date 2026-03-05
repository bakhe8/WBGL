<?php
require __DIR__ . '/../Support/autoload.php';

use App\Support\Database;

$db = Database::connect();

$sql = <<<'SQL'
SELECT
    g.id,
    g.guarantee_number,
    g.import_source,
    BOOL_OR(
        LOWER(g.import_source) = 'integration_flow'
        OR LOWER(g.import_source) = 'e2e_ui_flow_batch'
        OR LOWER(g.import_source) ~ '^e2e[_-]'
        OR LOWER(g.import_source) ~ '^test_'
        OR LOWER(g.import_source) LIKE 'test data%'
        OR LOWER(g.import_source) = 'email_import_draft'
    ) AS reason_import_source,
    BOOL_OR(
        LOWER(COALESCE(bm.batch_name, '')) LIKE '%e2e%'
        OR LOWER(COALESCE(bm.batch_name, '')) LIKE '%test%'
        OR COALESCE(bm.batch_name, '') LIKE '%اختبار%'
    ) AS reason_batch_name,
    BOOL_OR(
        LOWER(COALESCE(bm.batch_notes, '')) LIKE '%e2e%'
        OR LOWER(COALESCE(bm.batch_notes, '')) LIKE '%test%'
        OR COALESCE(bm.batch_notes, '') LIKE '%اختبار%'
    ) AS reason_batch_notes,
    STRING_AGG(DISTINCT o.batch_identifier, ', ' ORDER BY o.batch_identifier) AS batch_identifiers
FROM guarantees g
LEFT JOIN guarantee_occurrences o ON o.guarantee_id = g.id
LEFT JOIN batch_metadata bm ON bm.import_source = o.batch_identifier
WHERE COALESCE(g.is_test_data, 0) = 0
  AND (
        LOWER(g.import_source) = 'integration_flow'
     OR LOWER(g.import_source) = 'e2e_ui_flow_batch'
     OR LOWER(g.import_source) ~ '^e2e[_-]'
     OR LOWER(g.import_source) ~ '^test_'
     OR LOWER(g.import_source) LIKE 'test data%'
     OR LOWER(g.import_source) = 'email_import_draft'
     OR LOWER(COALESCE(bm.batch_name, '')) LIKE '%e2e%'
     OR LOWER(COALESCE(bm.batch_name, '')) LIKE '%test%'
     OR COALESCE(bm.batch_name, '') LIKE '%اختبار%'
     OR LOWER(COALESCE(bm.batch_notes, '')) LIKE '%e2e%'
     OR LOWER(COALESCE(bm.batch_notes, '')) LIKE '%test%'
     OR COALESCE(bm.batch_notes, '') LIKE '%اختبار%'
  )
GROUP BY g.id, g.guarantee_number, g.import_source
ORDER BY g.id ASC
SQL;

$stmt = $db->query($sql);
$rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
$count = is_array($rows) ? count($rows) : 0;

$generatedAt = date('Y-m-d H:i:s');

$lines = [];
$lines[] = '# تقرير السجلات ذات مؤشرات اختبار قوية (غير معلّمة كاختبار)';
$lines[] = '';
$lines[] = '- تاريخ التقرير: `' . $generatedAt . '`';
$lines[] = '- إجمالي السجلات: `' . $count . '`';
$lines[] = '- قاعدة البيانات: `wbgl`';
$lines[] = '';
$lines[] = '## أرقام الضمانات';
$lines[] = '';

foreach ($rows as $r) {
    $lines[] = '- `' . (string)($r['guarantee_number'] ?? '') . '`';
}

$lines[] = '';
$lines[] = '## التفاصيل';
$lines[] = '';
$lines[] = '| ID | رقم الضمان | import_source | سبب الاشتباه | batch identifiers |';
$lines[] = '|---:|---|---|---|---|';

foreach ($rows as $r) {
    $reasons = [];
    if (!empty($r['reason_import_source']) && $r['reason_import_source'] !== 'f') {
        $reasons[] = 'import_source';
    }
    if (!empty($r['reason_batch_name']) && $r['reason_batch_name'] !== 'f') {
        $reasons[] = 'batch_name';
    }
    if (!empty($r['reason_batch_notes']) && $r['reason_batch_notes'] !== 'f') {
        $reasons[] = 'batch_notes';
    }
    $reasonLabel = empty($reasons) ? '-' : implode(', ', $reasons);

    $id = (string)($r['id'] ?? '');
    $gn = str_replace('|', '\\|', (string)($r['guarantee_number'] ?? ''));
    $src = str_replace('|', '\\|', (string)($r['import_source'] ?? ''));
    $batches = str_replace('|', '\\|', (string)($r['batch_identifiers'] ?? ''));

    $lines[] = '| ' . $id . ' | `' . $gn . '` | `' . $src . '` | ' . $reasonLabel . ' | ' . ($batches !== '' ? '`' . $batches . '`' : '-') . ' |';
}

$reportPath = __DIR__ . '/../../storage/logs/suspect-test-data-unflagged-report.md';
file_put_contents($reportPath, implode(PHP_EOL, $lines) . PHP_EOL);

echo $reportPath . PHP_EOL;
echo 'COUNT=' . $count . PHP_EOL;
