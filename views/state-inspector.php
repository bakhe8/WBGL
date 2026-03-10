<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\AuthService;
use App\Support\AssetVersion;
use App\Support\Database;
use App\Support\DirectionResolver;
use App\Support\LocaleResolver;
use App\Support\Settings;
use App\Support\TestDataVisibility;
use App\Support\ViewPolicy;

ViewPolicy::guardView('state-inspector.php');

$db = Database::connect();
$assetVersion = static fn(string $path): string => rawurlencode(AssetVersion::forPath($path));
$settings = Settings::getInstance();
$currentUser = AuthService::getCurrentUser();
$localeInfo = LocaleResolver::resolve(
    $currentUser,
    $settings,
    $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null
);
$pageLocale = (string)($localeInfo['locale'] ?? 'ar');
$directionInfo = DirectionResolver::resolve(
    $pageLocale,
    $currentUser?->preferredDirection ?? 'auto',
    (string)$settings->get('DEFAULT_DIRECTION', 'auto')
);
$pageDirection = (string)($directionInfo['direction'] ?? ($pageLocale === 'ar' ? 'rtl' : 'ltr'));
$includeTestData = TestDataVisibility::includeTestData($settings, $_GET);
$canAccessTestData = TestDataVisibility::canCurrentUserAccessTestData();

$stateInspectorLocalePrimary = [];
$stateInspectorLocaleFallback = [];
$stateInspectorPrimaryPath = __DIR__ . '/../public/locales/' . $pageLocale . '/state_inspector.json';
$stateInspectorFallbackPath = __DIR__ . '/../public/locales/ar/state_inspector.json';
if (is_file($stateInspectorPrimaryPath)) {
    $decodedLocale = json_decode((string)file_get_contents($stateInspectorPrimaryPath), true);
    if (is_array($decodedLocale)) {
        $stateInspectorLocalePrimary = $decodedLocale;
    }
}
if (is_file($stateInspectorFallbackPath)) {
    $decodedLocale = json_decode((string)file_get_contents($stateInspectorFallbackPath), true);
    if (is_array($decodedLocale)) {
        $stateInspectorLocaleFallback = $decodedLocale;
    }
}
$stateInspectorT = static function (string $key, ?string $fallback = null) use (
    $stateInspectorLocalePrimary,
    $stateInspectorLocaleFallback
): string {
    $value = $stateInspectorLocalePrimary[$key] ?? $stateInspectorLocaleFallback[$key] ?? null;
    if (!is_string($value) || trim($value) === '') {
        return $fallback ?? $key;
    }
    return $value;
};

$searchTerm = trim((string)($_GET['search'] ?? ''));
$statusFilter = strtolower(trim((string)($_GET['status'] ?? '')));
$workflowFilter = strtolower(trim((string)($_GET['workflow_step'] ?? '')));
$actionFilter = strtolower(trim((string)($_GET['active_action'] ?? '')));
$lockedFilter = strtolower(trim((string)($_GET['locked'] ?? '')));
$limit = (int)($_GET['limit'] ?? 100);
$limit = max(10, min(200, $limit));

$where = [];
$params = [];

if ($searchTerm !== '') {
    $where[] = '(g.guarantee_number ILIKE :search OR s.official_name ILIKE :search OR b.arabic_name ILIKE :search)';
    $params['search'] = '%' . $searchTerm . '%';
}

if ($statusFilter !== '') {
    $where[] = 'LOWER(COALESCE(d.status, \'\')) = :status';
    $params['status'] = $statusFilter;
}

if ($workflowFilter !== '') {
    $where[] = 'LOWER(COALESCE(d.workflow_step, \'\')) = :workflow_step';
    $params['workflow_step'] = $workflowFilter;
}

if ($actionFilter !== '') {
    if ($actionFilter === 'none') {
        $where[] = '(d.active_action IS NULL OR d.active_action = \'\')';
    } else {
        $where[] = 'LOWER(COALESCE(d.active_action, \'\')) = :active_action';
        $params['active_action'] = $actionFilter;
    }
}

if ($lockedFilter === 'locked') {
    $where[] = 'd.is_locked = TRUE';
} elseif ($lockedFilter === 'open') {
    $where[] = '(d.is_locked IS NULL OR d.is_locked = FALSE)';
}

if (!$includeTestData) {
    $where[] = 'COALESCE(g.is_test_data, 0) = 0';
}

$whereSql = '';
if (!empty($where)) {
    $whereSql = ' AND ' . implode(' AND ', $where);
}

$sql = "
    SELECT
        g.id,
        g.guarantee_number,
        d.status,
        d.workflow_step,
        d.active_action,
        d.is_locked,
        d.signatures_received,
        d.supplier_id,
        d.bank_id,
        d.last_modified_at,
        d.last_modified_by,
        g.is_test_data,
        g.test_batch_id,
        s.official_name AS supplier_name,
        b.arabic_name AS bank_name
    FROM guarantees g
    LEFT JOIN guarantee_decisions d ON d.guarantee_id = g.id
    LEFT JOIN suppliers s ON s.id = d.supplier_id
    LEFT JOIN banks b ON b.id = d.bank_id
    WHERE 1=1
    {$whereSql}
    ORDER BY g.id DESC
    LIMIT {$limit}
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$classifyState = static function (array $row): array {
    $status = strtolower(trim((string)($row['status'] ?? '')));
    $step = strtolower(trim((string)($row['workflow_step'] ?? '')));
    $action = strtolower(trim((string)($row['active_action'] ?? '')));
    $locked = (bool)($row['is_locked'] ?? false);

    if ($locked || $status === 'released') {
        $isCanonical = ($status === 'released' && $step === 'signed' && $action === 'release' && $locked);
        return [
            'label_key' => $isCanonical
                ? 'state_inspector.state.released_finalized'
                : 'state_inspector.state.released_locked_needs_audit',
            'anomaly' => !$isCanonical,
        ];
    }

    if ($status === 'ready' && $step === 'draft' && $action === '') {
        return ['label_key' => 'state_inspector.state.data_entry_queue', 'anomaly' => false];
    }

    if ($status === 'ready' && $step === 'draft' && $action !== '') {
        return ['label_key' => 'state_inspector.state.action_selected_waiting_audit', 'anomaly' => false];
    }

    if ($status === 'ready' && in_array($step, ['audited', 'analyzed', 'supervised', 'approved'], true) && $action !== '') {
        return ['label_key' => 'state_inspector.state.workflow_in_progress', 'anomaly' => false];
    }

    if ($status === 'ready' && $step === 'signed' && $action !== '') {
        return ['label_key' => 'state_inspector.state.signed_operational', 'anomaly' => false];
    }

    if ($status === 'pending') {
        return ['label_key' => 'state_inspector.state.pending_decision', 'anomaly' => false];
    }

    return ['label_key' => 'state_inspector.state.unclassified_combination', 'anomaly' => true];
};

$summary = [
    'total' => count($rows),
    'anomalies' => 0,
];

foreach ($rows as &$stateRow) {
    $stateMeta = $classifyState($stateRow);
    $stateRow['state_label'] = $stateInspectorT((string)$stateMeta['label_key']);
    $stateRow['state_anomaly'] = $stateMeta['anomaly'];
    if ($stateMeta['anomaly']) {
        $summary['anomalies']++;
    }
}
unset($stateRow);
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($pageLocale, ENT_QUOTES, 'UTF-8') ?>" dir="<?= htmlspecialchars($pageDirection, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-i18n="state_inspector.page_title"><?= htmlspecialchars($stateInspectorT('state_inspector.page_title', 'State Inspector | WBGL'), ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="../public/css/design-system.css?v=<?= $assetVersion('public/css/design-system.css') ?>">
    <link rel="stylesheet" href="../public/css/themes.css?v=<?= $assetVersion('public/css/themes.css') ?>">
    <link rel="stylesheet" href="../public/css/components.css?v=<?= $assetVersion('public/css/components.css') ?>">
    <link rel="stylesheet" href="../public/css/layout.css?v=<?= $assetVersion('public/css/layout.css') ?>">
    <style>
        .inspector-shell {
            max-width: 1500px;
            margin: 0 auto;
            padding: 20px;
        }
        .inspector-card {
            background: var(--bg-card);
            border: 1px solid var(--border-primary);
            border-radius: 14px;
            padding: 16px;
            margin-bottom: 16px;
        }
        .inspector-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px;
        }
        .state-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 12px;
            border: 1px solid var(--border-primary);
            background: var(--bg-hover);
        }
        .state-chip.is-anomaly {
            color: var(--accent-danger, #b91c1c);
            border-color: var(--accent-danger, #fca5a5);
            background: var(--accent-danger-light, #fef2f2);
        }
        .inspector-table-wrap {
            overflow: auto;
        }
        .inspector-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1200px;
        }
        .inspector-table th,
        .inspector-table td {
            border-bottom: 1px solid var(--border-primary);
            padding: 10px;
            font-size: 13px;
            text-align: right;
            vertical-align: top;
        }
        .inspector-table th {
            background: var(--bg-hover);
            font-weight: 700;
        }
        .inspector-row-anomaly {
            background: var(--accent-warning-light, #fff7ed);
        }
        .mono {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
        }
        .hint {
            font-size: 12px;
            color: var(--text-muted);
        }
    </style>
</head>
<body data-i18n-namespaces="common,state_inspector">
<?php include __DIR__ . '/../partials/unified-header.php'; ?>

<main class="inspector-shell">
    <section class="inspector-card">
        <h1 style="margin-top:0;" data-i18n="state_inspector.heading"><?= htmlspecialchars($stateInspectorT('state_inspector.heading', 'State Inspector (Read Only)'), ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="hint" data-i18n="state_inspector.description"><?= htmlspecialchars($stateInspectorT('state_inspector.description', 'أداة تدقيق منطقية للحالة التشغيلية الفعلية مقابل المرحلة والإجراء والقفل.'), ENT_QUOTES, 'UTF-8') ?></p>
        <form method="GET" class="inspector-grid">
            <label>
                <span class="hint" data-i18n="state_inspector.filters.search"><?= htmlspecialchars($stateInspectorT('state_inspector.filters.search', 'بحث'), ENT_QUOTES, 'UTF-8') ?></span>
                <input type="text" name="search" class="form-input" value="<?= htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8') ?>" placeholder="<?= htmlspecialchars($stateInspectorT('state_inspector.filters.search_placeholder', 'رقم الضمان/المورد/البنك'), ENT_QUOTES, 'UTF-8') ?>" data-i18n-placeholder="state_inspector.filters.search_placeholder">
            </label>
            <label>
                <span class="hint" data-i18n="state_inspector.filters.status"><?= htmlspecialchars($stateInspectorT('state_inspector.filters.status', 'الحالة'), ENT_QUOTES, 'UTF-8') ?></span>
                <input type="text" name="status" class="form-input" value="<?= htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8') ?>" placeholder="<?= htmlspecialchars($stateInspectorT('state_inspector.filters.status_placeholder', 'ready / released / pending'), ENT_QUOTES, 'UTF-8') ?>" data-i18n-placeholder="state_inspector.filters.status_placeholder">
            </label>
            <label>
                <span class="hint" data-i18n="state_inspector.filters.workflow_step"><?= htmlspecialchars($stateInspectorT('state_inspector.filters.workflow_step', 'مرحلة السير'), ENT_QUOTES, 'UTF-8') ?></span>
                <input type="text" name="workflow_step" class="form-input" value="<?= htmlspecialchars($workflowFilter, ENT_QUOTES, 'UTF-8') ?>" placeholder="<?= htmlspecialchars($stateInspectorT('state_inspector.filters.workflow_step_placeholder', 'draft / signed ...'), ENT_QUOTES, 'UTF-8') ?>" data-i18n-placeholder="state_inspector.filters.workflow_step_placeholder">
            </label>
            <label>
                <span class="hint" data-i18n="state_inspector.filters.active_action"><?= htmlspecialchars($stateInspectorT('state_inspector.filters.active_action', 'الإجراء'), ENT_QUOTES, 'UTF-8') ?></span>
                <input type="text" name="active_action" class="form-input" value="<?= htmlspecialchars($actionFilter, ENT_QUOTES, 'UTF-8') ?>" placeholder="<?= htmlspecialchars($stateInspectorT('state_inspector.filters.active_action_placeholder', 'release / extension / none'), ENT_QUOTES, 'UTF-8') ?>" data-i18n-placeholder="state_inspector.filters.active_action_placeholder">
            </label>
            <label>
                <span class="hint" data-i18n="state_inspector.filters.locked"><?= htmlspecialchars($stateInspectorT('state_inspector.filters.locked', 'القفل'), ENT_QUOTES, 'UTF-8') ?></span>
                <select name="locked" class="form-select">
                    <option value="" <?= $lockedFilter === '' ? 'selected' : '' ?>><?= htmlspecialchars($stateInspectorT('state_inspector.filters.locked_all', 'الكل'), ENT_QUOTES, 'UTF-8') ?></option>
                    <option value="open" <?= $lockedFilter === 'open' ? 'selected' : '' ?>><?= htmlspecialchars($stateInspectorT('state_inspector.filters.locked_open', 'مفتوح'), ENT_QUOTES, 'UTF-8') ?></option>
                    <option value="locked" <?= $lockedFilter === 'locked' ? 'selected' : '' ?>><?= htmlspecialchars($stateInspectorT('state_inspector.filters.locked_locked', 'مقفل'), ENT_QUOTES, 'UTF-8') ?></option>
                </select>
            </label>
            <label>
                <span class="hint" data-i18n="state_inspector.filters.limit"><?= htmlspecialchars($stateInspectorT('state_inspector.filters.limit', 'الحد'), ENT_QUOTES, 'UTF-8') ?></span>
                <input type="number" name="limit" min="10" max="200" class="form-input" value="<?= (int)$limit ?>">
            </label>
            <?php if (!$settings->isProductionMode() && $canAccessTestData): ?>
                <label>
                    <span class="hint" data-i18n="state_inspector.filters.test_data"><?= htmlspecialchars($stateInspectorT('state_inspector.filters.test_data', 'بيانات الاختبار'), ENT_QUOTES, 'UTF-8') ?></span>
                    <select name="include_test_data" class="form-select">
                        <option value="0" <?= !$includeTestData ? 'selected' : '' ?>><?= htmlspecialchars($stateInspectorT('state_inspector.filters.test_data_hide', 'إخفاء'), ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="1" <?= $includeTestData ? 'selected' : '' ?>><?= htmlspecialchars($stateInspectorT('state_inspector.filters.test_data_show', 'إظهار'), ENT_QUOTES, 'UTF-8') ?></option>
                    </select>
                </label>
            <?php endif; ?>
            <div style="display:flex;align-items:flex-end;gap:8px;">
                <button type="submit" class="btn btn-primary" data-i18n="state_inspector.actions.apply"><?= htmlspecialchars($stateInspectorT('state_inspector.actions.apply', 'تطبيق'), ENT_QUOTES, 'UTF-8') ?></button>
                <a class="btn btn-secondary" href="state-inspector.php" data-i18n="state_inspector.actions.reset"><?= htmlspecialchars($stateInspectorT('state_inspector.actions.reset', 'إعادة ضبط'), ENT_QUOTES, 'UTF-8') ?></a>
            </div>
        </form>
    </section>

    <section class="inspector-card">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
            <div class="state-chip"><?= htmlspecialchars($stateInspectorT('state_inspector.summary.total', 'إجمالي النتائج'), ENT_QUOTES, 'UTF-8') ?>: <strong><?= (int)$summary['total'] ?></strong></div>
            <div class="state-chip <?= $summary['anomalies'] > 0 ? 'is-anomaly' : '' ?>">
                <?= htmlspecialchars($stateInspectorT('state_inspector.summary.anomalies', 'حالات غير معيارية'), ENT_QUOTES, 'UTF-8') ?>: <strong><?= (int)$summary['anomalies'] ?></strong>
            </div>
        </div>
    </section>

    <section class="inspector-card inspector-table-wrap">
        <table class="inspector-table">
            <thead>
                <tr>
                    <th><?= htmlspecialchars($stateInspectorT('state_inspector.table.id', 'ID'), ENT_QUOTES, 'UTF-8') ?></th>
                    <th><?= htmlspecialchars($stateInspectorT('state_inspector.table.guarantee_number', 'رقم الضمان'), ENT_QUOTES, 'UTF-8') ?></th>
                    <th><?= htmlspecialchars($stateInspectorT('state_inspector.table.status', 'الحالة'), ENT_QUOTES, 'UTF-8') ?></th>
                    <th><?= htmlspecialchars($stateInspectorT('state_inspector.table.workflow_step', 'مرحلة السير'), ENT_QUOTES, 'UTF-8') ?></th>
                    <th><?= htmlspecialchars($stateInspectorT('state_inspector.table.active_action', 'الإجراء'), ENT_QUOTES, 'UTF-8') ?></th>
                    <th><?= htmlspecialchars($stateInspectorT('state_inspector.table.locked', 'مقفل'), ENT_QUOTES, 'UTF-8') ?></th>
                    <th><?= htmlspecialchars($stateInspectorT('state_inspector.table.signatures', 'التواقيع'), ENT_QUOTES, 'UTF-8') ?></th>
                    <th><?= htmlspecialchars($stateInspectorT('state_inspector.table.supplier', 'المورد'), ENT_QUOTES, 'UTF-8') ?></th>
                    <th><?= htmlspecialchars($stateInspectorT('state_inspector.table.bank', 'البنك'), ENT_QUOTES, 'UTF-8') ?></th>
                    <th><?= htmlspecialchars($stateInspectorT('state_inspector.table.is_test', 'اختباري'), ENT_QUOTES, 'UTF-8') ?></th>
                    <th><?= htmlspecialchars($stateInspectorT('state_inspector.table.test_batch', 'دفعة الاختبار'), ENT_QUOTES, 'UTF-8') ?></th>
                    <th><?= htmlspecialchars($stateInspectorT('state_inspector.table.state_classification', 'تصنيف الحالة'), ENT_QUOTES, 'UTF-8') ?></th>
                    <th><?= htmlspecialchars($stateInspectorT('state_inspector.table.last_modified', 'آخر تعديل'), ENT_QUOTES, 'UTF-8') ?></th>
                    <th><?= htmlspecialchars($stateInspectorT('state_inspector.table.view', 'عرض'), ENT_QUOTES, 'UTF-8') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="14" class="hint"><?= htmlspecialchars($stateInspectorT('state_inspector.table.no_results', 'لا توجد نتائج.'), ENT_QUOTES, 'UTF-8') ?></td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <?php $link = '/index.php?' . http_build_query(['id' => (int)$row['id'], 'search' => (string)$row['guarantee_number']]); ?>
                        <tr class="<?= !empty($row['state_anomaly']) ? 'inspector-row-anomaly' : '' ?>">
                            <td class="mono"><?= (int)$row['id'] ?></td>
                            <td class="mono"><?= htmlspecialchars((string)$row['guarantee_number'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="mono"><?= htmlspecialchars((string)($row['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="mono"><?= htmlspecialchars((string)($row['workflow_step'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="mono"><?= htmlspecialchars((string)($row['active_action'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= !empty($row['is_locked']) ? htmlspecialchars($stateInspectorT('state_inspector.common.yes', 'نعم'), ENT_QUOTES, 'UTF-8') : htmlspecialchars($stateInspectorT('state_inspector.common.no', 'لا'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="mono"><?= (int)($row['signatures_received'] ?? 0) ?></td>
                            <td><?= htmlspecialchars((string)($row['supplier_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($row['bank_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= !empty($row['is_test_data']) ? htmlspecialchars($stateInspectorT('state_inspector.common.yes', 'نعم'), ENT_QUOTES, 'UTF-8') : htmlspecialchars($stateInspectorT('state_inspector.common.no', 'لا'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="mono"><?= htmlspecialchars((string)($row['test_batch_id'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <span class="state-chip <?= !empty($row['state_anomaly']) ? 'is-anomaly' : '' ?>">
                                    <?= htmlspecialchars((string)$row['state_label'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>
                            <td class="mono"><?= htmlspecialchars((string)($row['last_modified_at'] ?? '—'), ENT_QUOTES, 'UTF-8') ?><br><?= htmlspecialchars((string)($row['last_modified_by'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><a href="<?= htmlspecialchars($link, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-primary"><?= htmlspecialchars($stateInspectorT('state_inspector.actions.open', 'فتح'), ENT_QUOTES, 'UTF-8') ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
</body>
</html>
