<?php
/**
 * Batches List Page
 * Shows all batches (active and completed)
 */

require_once __DIR__ . '/../app/Support/autoload.php';
use App\Support\AuthService;
use App\Support\Database;
use App\Support\DirectionResolver;
use App\Support\LocaleResolver;
use App\Support\Settings;
use App\Support\TestDataVisibility;
use App\Support\ViewPolicy;

ViewPolicy::guardView('batches.php');

$db = Database::connect();
$driver = Database::currentDriver();
$importedByAggregate = $driver === 'pgsql'
    ? "STRING_AGG(DISTINCT g.imported_by, ', ')"
    : 'GROUP_CONCAT(DISTINCT g.imported_by)';
$settings = Settings::getInstance();
$includeTestData = TestDataVisibility::includeTestData($settings, $_GET);
$currentUser = AuthService::getCurrentUser();
$localeInfo = LocaleResolver::resolve(
    $currentUser,
    $settings,
    $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null
);
$batchesLocaleCode = (string)($localeInfo['locale'] ?? 'ar');
$directionInfo = DirectionResolver::resolve(
    $batchesLocaleCode,
    $currentUser?->preferredDirection ?? 'auto',
    (string)$settings->get('DEFAULT_DIRECTION', 'auto')
);
$pageDirection = (string)($directionInfo['direction'] ?? ($batchesLocaleCode === 'ar' ? 'rtl' : 'ltr'));
$batchesLocalePrimary = [];
$batchesLocaleFallback = [];
$batchesPrimaryPath = __DIR__ . '/../public/locales/' . $batchesLocaleCode . '/batches.json';
$batchesFallbackPath = __DIR__ . '/../public/locales/ar/batches.json';
if (is_file($batchesPrimaryPath)) {
    $decodedLocale = json_decode((string)file_get_contents($batchesPrimaryPath), true);
    if (is_array($decodedLocale)) {
        $batchesLocalePrimary = $decodedLocale;
    }
}
if (is_file($batchesFallbackPath)) {
    $decodedLocale = json_decode((string)file_get_contents($batchesFallbackPath), true);
    if (is_array($decodedLocale)) {
        $batchesLocaleFallback = $decodedLocale;
    }
}
$batchesTodoArPrefix = '__' . 'TODO_AR__';
$batchesTodoEnPrefix = '__' . 'TODO_EN__';
$batchesIsPlaceholder = static function ($value) use ($batchesTodoArPrefix, $batchesTodoEnPrefix): bool {
    if (!is_string($value)) {
        return false;
    }
    $trimmed = trim($value);
    return str_starts_with($trimmed, $batchesTodoArPrefix) || str_starts_with($trimmed, $batchesTodoEnPrefix);
};
$batchesT = static function (string $key, ?string $fallback = null) use ($batchesLocalePrimary, $batchesLocaleFallback, $batchesIsPlaceholder): string {
    $value = $batchesLocalePrimary[$key] ?? null;
    if (!is_string($value) || $batchesIsPlaceholder($value)) {
        $value = $batchesLocaleFallback[$key] ?? null;
    }
    if (!is_string($value) || $batchesIsPlaceholder($value)) {
        $value = $fallback ?? $key;
    }
    return $value;
};
$batchesPrefixMarker = 'BATCH_';

// Get all batches from occurrence ledger only (target contract).
$batches = $db->query("
    SELECT 
        o.batch_identifier as import_source,
        COALESCE(MAX(bm.batch_name), '{$batchesPrefixMarker}' || SUBSTR(o.batch_identifier, 1, 25)) as batch_name,
        COALESCE(MAX(bm.status), 'active') as status,
        COALESCE(MAX(bm.batch_notes), '') as batch_notes,
        COUNT(DISTINCT o.guarantee_id) as guarantee_count_total,
        COUNT(DISTINCT CASE WHEN COALESCE(g.is_test_data, 0) = 0 THEN o.guarantee_id END) as guarantee_count_non_test,
        COUNT(DISTINCT CASE WHEN COALESCE(g.is_test_data, 0) = 1 THEN o.guarantee_id END) as guarantee_count_test,
        MIN(o.occurred_at) as created_at,
        {$importedByAggregate} as imported_by
    FROM guarantee_occurrences o
    JOIN guarantees g ON g.id = o.guarantee_id
    LEFT JOIN batch_metadata bm ON bm.import_source = o.batch_identifier
    GROUP BY o.batch_identifier
    ORDER BY MIN(o.occurred_at) DESC
")->fetchAll(PDO::FETCH_ASSOC);

$filteredBatches = [];
foreach ($batches as $batch) {
    $nonTestCount = (int)($batch['guarantee_count_non_test'] ?? 0);
    $testCount = (int)($batch['guarantee_count_test'] ?? 0);
    $totalCount = (int)($batch['guarantee_count_total'] ?? 0);
    $isTestOnly = $nonTestCount === 0 && $testCount > 0;
    $hasTestData = $testCount > 0;

    // Default mode: hide pure test batches everywhere (prod + non-prod).
    if (!$includeTestData && $nonTestCount <= 0) {
        continue;
    }

    $batch['is_test_only'] = $isTestOnly;
    $batch['has_test_data'] = $hasTestData;
    $batch['guarantee_count'] = $includeTestData ? $totalCount : $nonTestCount;
    $filteredBatches[] = $batch;
}
$batches = $filteredBatches;
foreach ($batches as &$batch) {
    $batchName = (string)($batch['batch_name'] ?? '');
    if (str_starts_with($batchName, $batchesPrefixMarker)) {
        $batch['batch_name'] = $batchesT('batches.ui.txt_fb13a024') . ' ' . substr($batchName, strlen($batchesPrefixMarker));
    }
}
unset($batch);

// Separate active and completed
$active = array_filter($batches, fn($b) => $b['status'] === 'active');
$completed = array_filter($batches, fn($b) => $b['status'] === 'completed');
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($batchesLocaleCode, ENT_QUOTES, 'UTF-8') ?>" dir="<?= htmlspecialchars($pageDirection, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-i18n="batches.ui.txt_bb4a0e24">الدفعات - نظام إدارة الضمانات</title>
    
    <!-- Design System CSS -->
    <link rel="stylesheet" href="../public/css/design-system.css">
    <link rel="stylesheet" href="../public/css/components.css">
    <link rel="stylesheet" href="../public/css/layout.css">
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    
    <style>
        /* Page-specific styles */
        .page-container {
            width: 100%;
            padding: var(--space-lg);
        }
        
        .page-title {
            font-size: var(--font-size-2xl);
            font-weight: var(--font-weight-bold);
            color: var(--text-primary);
            margin-bottom: var(--space-xs);
        }
        
        .page-subtitle {
            color: var(--text-secondary);
            margin-bottom: var(--space-xl);
            font-size: var(--font-size-sm);
        }
        
        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: var(--space-md);
        }
        
        .section-title {
            font-size: var(--font-size-xl);
            font-weight: var(--font-weight-bold);
            color: var(--text-primary);
        }
        
        .batch-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: var(--space-md);
            margin-bottom: var(--space-2xl);
            width: 100%;
        }
        
        .batch-card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-primary);
            box-shadow: var(--shadow-sm);
            padding: var(--space-md);
            transition: all var(--transition-base);
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 180px;
        }
        
        .batch-card:hover {
            box-shadow: var(--shadow-lg);
            transform: translateY(-4px);
            border-color: var(--accent-primary);
        }
        
        .batch-card.active {
            border-top: 4px solid var(--accent-success);
            background: linear-gradient(180deg, var(--bg-card) 0%, var(--accent-success-light) 100%);
        }
        
        .batch-card.completed {
            border-top: 4px solid var(--border-neutral);
            background: var(--bg-secondary);
            opacity: 0.85;
        }
        
        .batch-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: var(--space-sm);
        }

        .batch-card-title {
            font-size: var(--font-size-md);
            font-weight: var(--font-weight-bold);
            color: var(--text-primary);
            line-height: var(--line-height-tight);
            margin: 0;
        }
        
        .batch-type-icon {
            font-size: 1.2rem;
            opacity: 0.7;
        }

        .batch-type-icon-muted {
            filter: grayscale(1);
        }

        .batch-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
            font-size: var(--font-size-sm);
            color: var(--text-secondary);
            margin-bottom: var(--space-md);
        }
        
        .batch-meta-item {
            display: flex;
            align-items: center;
            gap: var(--space-xs);
        }

        .batch-notes {
            font-size: var(--font-size-xs);
            color: var(--text-muted);
            font-style: italic;
            border-top: 1px solid var(--border-light);
            padding-top: var(--space-xs);
            margin-top: var(--space-xs);
        }
        
        .empty-state {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: var(--space-2xl);
            text-align: center;
            color: var(--text-muted);
            box-shadow: var(--shadow-sm);
        }
        
        .back-link {
            display: inline-block;
            margin-top: var(--space-xl);
            color: var(--accent-primary);
            text-decoration: none;
            transition: color var(--transition-base);
        }
        
        .back-link:hover {
            color: var(--accent-primary-hover);
            text-decoration: underline;
        }

        .batches-back-link-wrap {
            text-align: center;
        }
    </style>
</head>
<body data-i18n-namespaces="common,batches">
    
    <!-- Unified Header -->
    <?php include __DIR__ . '/../partials/unified-header.php'; ?>
    
    <div class="page-container">
        
        <div class="page-title" data-i18n="batches.ui.txt_8ea08ec4">الدفعات</div>
        <p class="page-subtitle" data-i18n="batches.ui.txt_c31dea35">إدارة مجموعات الضمانات للعمل الجماعي</p>
        <?php if (!$settings->isProductionMode()): ?>
            <?php
            $toggleParams = $_GET;
            if ($includeTestData) {
                $toggleParams['include_test_data'] = '0';
            } else {
                $toggleParams['include_test_data'] = '1';
            }
            $toggleHref = '/views/batches.php';
            if (!empty($toggleParams)) {
                $toggleHref .= '?' . http_build_query($toggleParams);
            }
            ?>
            <p class="page-subtitle">
                <a href="<?= htmlspecialchars($toggleHref, ENT_QUOTES, 'UTF-8') ?>">
                    <?= $includeTestData ? 'إخفاء الدفعات التجريبية' : 'عرض الدفعات التجريبية' ?>
                </a>
            </p>
        <?php endif; ?>
        
        <!-- Active Batches -->
        <section class="mb-5">
            <div class="section-header">
                <h2 class="section-title" data-i18n="batches.ui.txt_c5f04e9f">دفعات مفتوحة</h2>
                <span class="badge badge-success">
                    <?= count($active) ?> <span data-i18n="batches.ui.txt_fb13a024">دفعة</span>
                </span>
            </div>
            
            <?php if (empty($active)): ?>
                <div class="empty-state">
                    <span data-i18n="batches.ui.txt_ea9517a5">لا توجد دفعات مفتوحة حالياً</span>
                </div>
            <?php else: ?>
                <div class="batch-grid">
                    <?php foreach ($active as $batch): 
                        $isExcel = strpos($batch['import_source'], 'excel_') === 0;
                        $typeIcon = $isExcel ? '📄' : '📝';
                        $batchLinkParams = ['import_source' => $batch['import_source']];
                        $batchLinkParams = TestDataVisibility::withQueryFlag($batchLinkParams, $includeTestData);
                        $batchDetailHref = '/views/batch-detail.php?' . http_build_query($batchLinkParams);
                    ?>
                    <div class="batch-card active">
                        <div>
                            <div class="batch-card-header">
                                <h3 class="batch-card-title">
                                    <?= htmlspecialchars($batch['batch_name']) ?>
                                </h3>
                                <?php if (!empty($batch['is_test_only'])): ?>
                                    <span class="badge badge-warning">🧪 اختبار</span>
                                <?php elseif (!empty($batch['has_test_data'])): ?>
                                    <span class="badge badge-warning">🧪 مختلطة</span>
                                <?php endif; ?>
                                <span class="batch-type-icon" title="<?= $isExcel ? 'ملف Excel' : 'إدخال يدووي/لصق' ?>">
                                    <?= $typeIcon ?>
                                </span>
                            </div>
                            <div class="batch-info">
                                <div class="batch-meta-item">
                                    <span class="icon-sm">📦</span>
                                    <span><?= $batch['guarantee_count'] ?> <span data-i18n="batches.ui.txt_7e99567a">ضمان</span></span>
                                </div>
                                <div class="batch-meta-item">
                                    <span class="icon-sm">📅</span>
                                    <span><?= date('Y-m-d' . ' ' . 'H:i', strtotime($batch['created_at'])) ?></span>
                                </div>
                                <?php if ($batch['batch_notes']): ?>
                                <p class="batch-notes">
                                    <?= htmlspecialchars(substr($batch['batch_notes'], 0, 40)) ?>
                                    <?= strlen($batch['batch_notes']) > 40 ? '...' : '' ?>
                                </p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <a href="<?= htmlspecialchars($batchDetailHref, ENT_QUOTES, 'UTF-8') ?>" 
                           class="btn btn-primary btn-sm w-full">
                            <span data-i18n="batches.ui.txt_80e2ef88">فتح الدفعة</span>
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        
        <!-- Completed Batches -->
        <section>
            <div class="section-header">
                <h2 class="section-title" data-i18n="batches.ui.txt_9eb8616c">دفعات مغلقة</h2>
                <span class="badge badge-neutral">
                    <?= count($completed) ?> <span data-i18n="batches.ui.txt_fb13a024">دفعة</span>
                </span>
            </div>
            
            <?php if (empty($completed)): ?>
                <div class="empty-state">
                    <span data-i18n="batches.ui.txt_d93631de">لا توجد دفعات مغلقة</span>
                </div>
            <?php else: ?>
                <div class="batch-grid">
                    <?php foreach ($completed as $batch): 
                        $isExcel = strpos($batch['import_source'], 'excel_') === 0;
                        $typeIcon = $isExcel ? '📄' : '📝';
                        $batchLinkParams = ['import_source' => $batch['import_source']];
                        $batchLinkParams = TestDataVisibility::withQueryFlag($batchLinkParams, $includeTestData);
                        $batchDetailHref = '/views/batch-detail.php?' . http_build_query($batchLinkParams);
                    ?>
                    <div class="batch-card completed">
                        <div>
                            <div class="batch-card-header">
                                <h3 class="batch-card-title text-muted">
                                    <?= htmlspecialchars($batch['batch_name']) ?>
                                </h3>
                                <?php if (!empty($batch['is_test_only'])): ?>
                                    <span class="badge badge-warning">🧪 اختبار</span>
                                <?php elseif (!empty($batch['has_test_data'])): ?>
                                    <span class="badge badge-warning">🧪 مختلطة</span>
                                <?php endif; ?>
                                <span class="batch-type-icon batch-type-icon-muted">
                                    <?= $typeIcon ?>
                                </span>
                            </div>
                            <div class="batch-info">
                                <div class="batch-meta-item">
                                    <span>📦 <?= $batch['guarantee_count'] ?> <span data-i18n="batches.ui.txt_7e99567a">ضمان</span></span>
                                </div>
                                <div class="batch-meta-item">
                                    <span>📅 <?= date('Y-m-d', strtotime($batch['created_at'])) ?></span>
                                </div>
                            </div>
                        </div>
                        <a href="<?= htmlspecialchars($batchDetailHref, ENT_QUOTES, 'UTF-8') ?>" 
                           class="btn btn-secondary btn-sm w-full">
                            <span data-i18n="batches.ui.txt_85b89ef7">عرض التفاصيل</span>
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        
        <!-- Back to home -->
        <?php
        $backParams = TestDataVisibility::withQueryFlag([], $includeTestData);
        $backHref = '/index.php';
        if (!empty($backParams)) {
            $backHref .= '?' . http_build_query($backParams);
        }
        ?>
        <div class="batches-back-link-wrap">
            <a href="<?= htmlspecialchars($backHref, ENT_QUOTES, 'UTF-8') ?>" class="back-link" data-i18n="batches.ui.txt_188a63a9">← العودة للصفحة الرئيسية</a>
        </div>
    </div>
</body>
</html>
