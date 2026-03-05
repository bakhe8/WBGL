<?php
declare(strict_types=1);

require __DIR__ . '/../Support/autoload.php';

use App\Support\Database;

$db = Database::connect();

$summarySql = "
    SELECT
        COUNT(*) AS total_guarantees,
        COUNT(*) FILTER (WHERE COALESCE(is_test_data, 0) = 0) AS real_guarantees,
        COUNT(*) FILTER (WHERE COALESCE(is_test_data, 0) = 1) AS test_guarantees,
        COUNT(*) FILTER (
            WHERE NOT EXISTS (
                SELECT 1
                FROM guarantee_occurrences o
                WHERE o.guarantee_id = guarantees.id
            )
        ) AS guarantees_without_occurrence
    FROM guarantees
";
$summary = $db->query($summarySql)->fetch(PDO::FETCH_ASSOC) ?: [];

$workflowSql = "
    SELECT
        COUNT(*) AS absolute_total,
        COUNT(*) FILTER (WHERE (d.is_locked IS NULL OR d.is_locked = FALSE)) AS open_total,
        COUNT(*) FILTER (WHERE (d.is_locked IS NULL OR d.is_locked = FALSE) AND d.status = 'ready') AS ready_total,
        COUNT(*) FILTER (WHERE (d.is_locked IS NULL OR d.is_locked = FALSE) AND (d.id IS NULL OR d.status = 'pending')) AS pending_total,
        COUNT(*) FILTER (WHERE d.is_locked = TRUE) AS released_total
    FROM guarantees g
    LEFT JOIN guarantee_decisions d ON d.guarantee_id = g.id
";
$workflow = $db->query($workflowSql)->fetch(PDO::FETCH_ASSOC) ?: [];

$batchesSql = "
    WITH b AS (
        SELECT
            o.batch_identifier,
            COUNT(DISTINCT CASE WHEN COALESCE(g.is_test_data, 0) = 1 THEN o.guarantee_id END) AS test_cnt,
            COUNT(DISTINCT CASE WHEN COALESCE(g.is_test_data, 0) = 0 THEN o.guarantee_id END) AS real_cnt
        FROM guarantee_occurrences o
        JOIN guarantees g ON g.id = o.guarantee_id
        GROUP BY o.batch_identifier
    )
    SELECT
        COUNT(*) AS total_batches,
        COUNT(*) FILTER (WHERE test_cnt > 0) AS batches_with_test,
        COUNT(*) FILTER (WHERE test_cnt > 0 AND real_cnt = 0) AS test_only_batches,
        COUNT(*) FILTER (WHERE test_cnt > 0 AND real_cnt > 0) AS mixed_batches
    FROM b
";
$batches = $db->query($batchesSql)->fetch(PDO::FETCH_ASSOC) ?: [];

$totalGuarantees = (int)($summary['total_guarantees'] ?? 0);
$realGuarantees = (int)($summary['real_guarantees'] ?? 0);
$testGuarantees = (int)($summary['test_guarantees'] ?? 0);
$missingOccurrences = (int)($summary['guarantees_without_occurrence'] ?? 0);

$absoluteTotal = (int)($workflow['absolute_total'] ?? 0);
$openTotal = (int)($workflow['open_total'] ?? 0);
$readyTotal = (int)($workflow['ready_total'] ?? 0);
$pendingTotal = (int)($workflow['pending_total'] ?? 0);
$releasedTotal = (int)($workflow['released_total'] ?? 0);

$totalBatches = (int)($batches['total_batches'] ?? 0);
$batchesWithTest = (int)($batches['batches_with_test'] ?? 0);
$testOnlyBatches = (int)($batches['test_only_batches'] ?? 0);
$mixedBatches = (int)($batches['mixed_batches'] ?? 0);

$checks = [
    'total_equals_real_plus_test' => $totalGuarantees === ($realGuarantees + $testGuarantees),
    'absolute_equals_open_plus_released' => $absoluteTotal === ($openTotal + $releasedTotal),
    'open_equals_ready_plus_pending' => $openTotal === ($readyTotal + $pendingTotal),
    'no_missing_occurrences' => $missingOccurrences === 0,
    'batches_with_test_partition' => $batchesWithTest === ($testOnlyBatches + $mixedBatches),
];

$generatedAt = date('Y-m-d H:i:s');
$reportPath = __DIR__ . '/../../storage/logs/system-counts-consistency-report.md';

$lines = [];
$lines[] = '# تقرير اتساق الأرقام - WBGL';
$lines[] = '';
$lines[] = '- تاريخ التقرير: `' . $generatedAt . '`';
$lines[] = '- قاعدة البيانات: `wbgl`';
$lines[] = '';
$lines[] = '## 1) أرقام الضمانات';
$lines[] = '';
$lines[] = '| المؤشر | القيمة |';
$lines[] = '|---|---:|';
$lines[] = '| إجمالي الضمانات | ' . $totalGuarantees . ' |';
$lines[] = '| الضمانات الحقيقية | ' . $realGuarantees . ' |';
$lines[] = '| الضمانات الاختبارية | ' . $testGuarantees . ' |';
$lines[] = '| ضمانات بدون occurrence | ' . $missingOccurrences . ' |';
$lines[] = '';
$lines[] = '## 2) أرقام التدفق التشغيلي';
$lines[] = '';
$lines[] = '| المؤشر | القيمة |';
$lines[] = '|---|---:|';
$lines[] = '| الإجمالي التشغيلي (absolute) | ' . $absoluteTotal . ' |';
$lines[] = '| قيد التشغيل (غير مفرج) | ' . $openTotal . ' |';
$lines[] = '| جاهز | ' . $readyTotal . ' |';
$lines[] = '| يحتاج قرار | ' . $pendingTotal . ' |';
$lines[] = '| مفرج عنها | ' . $releasedTotal . ' |';
$lines[] = '';
$lines[] = '## 3) أرقام الدفعات';
$lines[] = '';
$lines[] = '| المؤشر | القيمة |';
$lines[] = '|---|---:|';
$lines[] = '| إجمالي الدفعات (من occurrence ledger) | ' . $totalBatches . ' |';
$lines[] = '| دفعات تحتوي بيانات اختبار | ' . $batchesWithTest . ' |';
$lines[] = '| دفعات اختبار فقط | ' . $testOnlyBatches . ' |';
$lines[] = '| دفعات مختلطة (اختبار + حقيقي) | ' . $mixedBatches . ' |';
$lines[] = '';
$lines[] = '## 4) فحوصات الاتساق';
$lines[] = '';
$lines[] = '| الفحص | النتيجة |';
$lines[] = '|---|---|';
$lines[] = '| إجمالي الضمانات = حقيقي + اختبار | ' . ($checks['total_equals_real_plus_test'] ? 'PASS ✅' : 'FAIL ❌') . ' |';
$lines[] = '| absolute = open + released | ' . ($checks['absolute_equals_open_plus_released'] ? 'PASS ✅' : 'FAIL ❌') . ' |';
$lines[] = '| open = ready + pending | ' . ($checks['open_equals_ready_plus_pending'] ? 'PASS ✅' : 'FAIL ❌') . ' |';
$lines[] = '| لا توجد ضمانات بدون occurrence | ' . ($checks['no_missing_occurrences'] ? 'PASS ✅' : 'FAIL ❌') . ' |';
$lines[] = '| batches_with_test = test_only + mixed | ' . ($checks['batches_with_test_partition'] ? 'PASS ✅' : 'FAIL ❌') . ' |';
$lines[] = '';
$lines[] = '## 5) تفسير سريع';
$lines[] = '';
$lines[] = '- عداد الصفحة الرئيسية (`📊`) يمثل: `ready + pending` (أي قيد التشغيل فقط).';
$lines[] = '- صفحة الصيانة تعرض الإجمالي الكامل للضمانات.';
$lines[] = '- أي اختلاف ظاهري بين الصفحتين طبيعي إذا كانت هناك ضمانات مفرج عنها.';
$lines[] = '';

file_put_contents($reportPath, implode(PHP_EOL, $lines) . PHP_EOL);

echo $reportPath . PHP_EOL;
