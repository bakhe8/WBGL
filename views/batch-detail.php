<?php
/**
 * Batch Detail Page - Refactored for WBGL
 * Features: Modern UI, Toast Notifications, Modal Inputs, Loading States
 * Uses Standard Design System
 */

require_once __DIR__ . '/../app/Support/autoload.php';
use App\Support\Database;
use App\Support\Settings;
use App\Support\TestDataVisibility;
use App\Support\ViewPolicy;
use App\Support\Guard;
use App\Support\AuthService;
use App\Repositories\RoleRepository;

ViewPolicy::guardView('batch-detail.php');

$db = Database::connect();
$importSource = $_GET['import_source'] ?? '';
$settings = Settings::getInstance();
$includeTestData = TestDataVisibility::includeTestData($settings, $_GET);
$canReopenBatch = Guard::has('reopen_batch') || Guard::has('break_glass_override');
$currentUserRoleSlug = '';
try {
    $currentUser = AuthService::getCurrentUser();
    if ($currentUser && $currentUser->roleId !== null) {
        $roleRepo = new RoleRepository($db);
        $role = $roleRepo->find((int)$currentUser->roleId);
        $currentUserRoleSlug = strtolower(trim((string)($role->slug ?? '')));
    }
} catch (\Throwable) {
    $currentUserRoleSlug = '';
}
$legacyPrintRoleDefault = in_array($currentUserRoleSlug, ['developer', 'admin', 'system_admin'], true);
$canPrintLetters = Guard::hasOrLegacy('letters_print', $legacyPrintRoleDefault);
$printBypassForSystemManager = in_array($currentUserRoleSlug, ['developer', 'admin', 'system_admin'], true);
$batchDetailLocaleCode = strtolower((string)$settings->get('DEFAULT_LOCALE', 'ar'));
if (!in_array($batchDetailLocaleCode, ['ar', 'en'], true)) {
    $batchDetailLocaleCode = 'ar';
}
$batchDetailLocalePrimary = [];
$batchDetailLocaleFallback = [];
$batchDetailPrimaryPath = __DIR__ . '/../public/locales/' . $batchDetailLocaleCode . '/batch_detail.json';
$batchDetailFallbackPath = __DIR__ . '/../public/locales/ar/batch_detail.json';
if (is_file($batchDetailPrimaryPath)) {
    $decodedLocale = json_decode((string)file_get_contents($batchDetailPrimaryPath), true);
    if (is_array($decodedLocale)) {
        $batchDetailLocalePrimary = $decodedLocale;
    }
}
if (is_file($batchDetailFallbackPath)) {
    $decodedLocale = json_decode((string)file_get_contents($batchDetailFallbackPath), true);
    if (is_array($decodedLocale)) {
        $batchDetailLocaleFallback = $decodedLocale;
    }
}
$batchDetailTodoArPrefix = '__' . 'TODO_AR__';
$batchDetailTodoEnPrefix = '__' . 'TODO_EN__';
$batchDetailIsPlaceholder = static function ($value) use ($batchDetailTodoArPrefix, $batchDetailTodoEnPrefix): bool {
    if (!is_string($value)) {
        return false;
    }
    $trimmed = trim($value);
    return str_starts_with($trimmed, $batchDetailTodoArPrefix) || str_starts_with($trimmed, $batchDetailTodoEnPrefix);
};
$batchDetailT = static function (string $key, ?string $fallback = null) use ($batchDetailLocalePrimary, $batchDetailLocaleFallback, $batchDetailIsPlaceholder): string {
    $value = $batchDetailLocalePrimary[$key] ?? null;
    if (!is_string($value) || $batchDetailIsPlaceholder($value)) {
        $value = $batchDetailLocaleFallback[$key] ?? null;
    }
    if (!is_string($value) || $batchDetailIsPlaceholder($value)) {
        $value = $fallback ?? $key;
    }
    return $value;
};

if (!$importSource) {
    die('<div class="p-5 text-center text-danger font-bold">' . htmlspecialchars($batchDetailT('batch_detail.ui.txt_b1639a5d'), ENT_QUOTES, 'UTF-8') . '</div>');
}

// 1. Fetch Metadata
$metadataStmt = $db->prepare("SELECT * FROM batch_metadata WHERE import_source = ?");
$metadataStmt->execute([$importSource]);
$metadata = $metadataStmt->fetch(PDO::FETCH_ASSOC);

$rawSupplierExpr = "(g.raw_data::jsonb ->> 'supplier')";
$rawBankExpr = "(g.raw_data::jsonb ->> 'bank')";

// Batch query from occurrence ledger only (target contract).
$stmt = $db->prepare("
    SELECT g.*, 
           -- Prefer simple resolved name from decision, then fallback to inferred match, then raw
           COALESCE(s_decided.official_name, s.official_name) as supplier_name,
           b.arabic_name as bank_name,
           d.status as decision_status,
           d.active_action,
           d.workflow_step,
           d.signatures_received,
           d.supplier_id,
           d.bank_id,
           o_latest.occurred_at as occurrence_date
    FROM (
        SELECT
            o.guarantee_id,
            MAX(o.occurred_at) AS occurred_at
        FROM guarantee_occurrences o
        WHERE o.batch_identifier = ?
        GROUP BY o.guarantee_id
    ) o_latest
    JOIN guarantees g ON g.id = o_latest.guarantee_id
    
    -- 1. Decision row (single-row per guarantee)
    LEFT JOIN guarantee_decisions d ON d.guarantee_id = g.id
    
    -- 2. Join Supplier from Decision (Highest Priority)
    LEFT JOIN suppliers s_decided ON d.supplier_id = s_decided.id
    
    -- 3. Join for inferred supplier (Fallback)
    LEFT JOIN suppliers s ON g.normalized_supplier_name = s.official_name 
         OR {$rawSupplierExpr} = s.official_name
         OR {$rawSupplierExpr} = s.english_name
         
    LEFT JOIN banks b ON {$rawBankExpr} = b.english_name 
         OR {$rawBankExpr} = b.arabic_name
    WHERE 1=1
    ORDER BY g.id ASC
");
$stmt->execute([$importSource]);
$guarantees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Default mode: hide test guarantees unless explicitly requested.
if (!$includeTestData) {
    $guarantees = array_filter($guarantees, static fn($g) => (int)($g['is_test_data'] ?? 0) === 0);
    // Re-index array after filtering
    $guarantees = array_values($guarantees);
}

// Calculate stats based on occurrences
$totalAmount = 0;
foreach ($guarantees as $r) {
    $raw = json_decode($r['raw_data'], true);
    $totalAmount += floatval($raw['amount'] ?? 0);
}

// 3. Process Data
$batchName = $metadata['batch_name'] ?? ($batchDetailT('batch_detail.ui.txt_fb13a024') . ' ' . substr($importSource, 0, 30));
$status = $metadata['status'] ?? 'active';
$isClosed = ($status === 'completed');
$batchNotes = $metadata['batch_notes'] ?? '';

// Helper to parse JSON safely
foreach ($guarantees as &$g) {
    $g['parsed'] = json_decode($g['raw_data'], true) ?? [];
    $g['supplier_name'] = $g['supplier_name'] ?: ($g['parsed']['supplier'] ?? '-');
    $g['bank_name'] = $g['bank_name'] ?: ($g['parsed']['bank'] ?? '-');
    $statusValue = strtolower(trim((string)($g['decision_status'] ?? 'pending')));
    $workflowStep = strtolower(trim((string)($g['workflow_step'] ?? 'draft')));
    $activeAction = strtolower(trim((string)($g['active_action'] ?? '')));
    $signaturesReceived = (int)($g['signatures_received'] ?? 0);
    $hasBasicData = !empty($g['supplier_id']) && !empty($g['bank_id']);

    $g['is_actionable'] = $statusValue === 'ready'
        && $hasBasicData
        && $activeAction === ''
        && $workflowStep === 'draft';
    $g['is_print_ready'] = $hasBasicData
        && $activeAction !== ''
        && ($workflowStep === 'signed' || $statusValue === 'released')
        && ($statusValue === 'released' || $signaturesReceived > 0);

    // Human-readable workflow stage for table display.
    $stageMap = [
        'draft' => ['key' => 'batch_detail.workflow.step.draft', 'label' => $batchDetailT('batch_detail.workflow.step.draft', 'بانتظار التدقيق'), 'class' => 'badge-neutral'],
        'audited' => ['key' => 'batch_detail.workflow.step.audited', 'label' => $batchDetailT('batch_detail.workflow.step.audited', 'تم التدقيق'), 'class' => 'badge-info'],
        'analyzed' => ['key' => 'batch_detail.workflow.step.analyzed', 'label' => $batchDetailT('batch_detail.workflow.step.analyzed', 'تم التحليل'), 'class' => 'badge-info'],
        'supervised' => ['key' => 'batch_detail.workflow.step.supervised', 'label' => $batchDetailT('batch_detail.workflow.step.supervised', 'تم الإشراف'), 'class' => 'badge-info'],
        'approved' => ['key' => 'batch_detail.workflow.step.approved', 'label' => $batchDetailT('batch_detail.workflow.step.approved', 'تم الاعتماد'), 'class' => 'badge-warning'],
        'signed' => ['key' => 'batch_detail.workflow.step.signed', 'label' => $batchDetailT('batch_detail.workflow.step.signed', 'تم التوقيع'), 'class' => 'badge-success'],
    ];
    $stageUi = $stageMap[$workflowStep] ?? ['key' => '', 'label' => strtoupper($workflowStep ?: '-'), 'class' => 'badge-neutral'];
    $g['workflow_stage_i18n_key'] = $stageUi['key'];
    $g['workflow_stage_label'] = $stageUi['label'];
    $g['workflow_stage_class'] = $stageUi['class'];
}
unset($g);
// Calculate counts for UI logic
// 1. Actionable Count: current cycle ready, no action selected yet.
$actionableCount = count(array_filter($guarantees, static fn(array $g): bool => !empty($g['is_actionable'])));

// 2. Print Ready Count: current cycle has selected action and reached signed/released.
$printReadyCount = count(array_filter($guarantees, static fn(array $g): bool => !empty($g['is_print_ready'])));
$printBypassCount = count(array_filter(
    $guarantees,
    static fn(array $g): bool => !empty($g['supplier_id']) && !empty($g['bank_id']) && !empty(trim((string)($g['active_action'] ?? '')))
));
$batchPrintableCount = $printBypassForSystemManager ? $printBypassCount : $printReadyCount;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($batchName) ?></title>
    
    <!-- Design System CSS -->
    <link rel="stylesheet" href="../public/css/design-system.css">
    <link rel="stylesheet" href="../public/css/components.css">
    <link rel="stylesheet" href="../public/css/layout.css">
    <link rel="stylesheet" href="../public/css/a11y.css">
    
    <!-- Page Specific Overrides (Cleaned) -->
    <link rel="stylesheet" href="../public/css/batch-detail.css">
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    
    <!-- Lucide Icons (local, CSP-safe) -->
    <script src="../public/js/vendor/lucide.min.js"></script>
</head>
<body
    data-i18n-namespaces="common,batch_detail,messages"
    data-print-permission="<?= $canPrintLetters ? '1' : '0' ?>"
    data-print-audit-bypass="<?= $printBypassForSystemManager ? '1' : '0' ?>">

    <!-- Unified Header -->
    <?php include __DIR__ . '/../partials/unified-header.php'; ?>
    
    <!-- Toast Container -->
    <div id="toast-container" role="status" aria-live="polite" class="bd-toast-container"></div>

    <!-- Modal Container -->
    <div id="modal-backdrop" class="modal-backdrop is-hidden" aria-hidden="true">
        <div id="modal-content" class="modal-content" role="dialog" aria-modal="true" aria-labelledby="modal-title" tabindex="-1">
            <!-- Dynamic Content -->
        </div>
    </div>

    <!-- Main Content -->
    <div class="page-container">
        
        <!-- Batch Header (Redesigned) -->
        <div class="card mb-5 border-0 shadow-sm">
            <div class="card-body p-6">
                <div class="row align-items-start gap-4 bd-flex-wrap-between">
                    
                    <!-- Right Side: Info -->
                    <div class="bd-info-column">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="p-3 bg-primary-light rounded-circle text-primary">
                                <i data-lucide="layers" class="icon-24"></i>
                            </div>
                            <div>
                                <h1 class="text-2xl font-bold mb-1 d-flex align-items-center gap-2">
                                    <?= htmlspecialchars($batchName) ?>
                                    <span class="badge text-xs <?= $isClosed ? 'badge-neutral' : 'badge-success' ?>" data-i18n="<?= $isClosed ? 'batch_detail.ui.txt_249b0f6b' : 'batch_detail.ui.txt_6cf44b8c' ?>">
                                        <?= $isClosed ? $batchDetailT('batch_detail.ui.txt_249b0f6b') : $batchDetailT('batch_detail.ui.txt_6cf44b8c') ?>
                                    </span>
                                </h1>
                                <div class="text-secondary text-sm d-flex align-items-center gap-4">
                                    <span class="d-flex align-items-center gap-1" title="تاريخ الاستيراد" data-i18n-title="batch_detail.ui.txt_30fe32c4">
                                        <i data-lucide="calendar" class="icon-14"></i> 
                                        <?= date('Y-m-d' . ' ' . 'H:i', strtotime($guarantees[0]['occurrence_date'] ?? 'now')) ?>
                                    </span>
                                    <span class="d-flex align-items-center gap-1" title="المصدر" data-i18n-title="batch_detail.ui.txt_7a466b92">
                                        <i data-lucide="file-spreadsheet" class="icon-14"></i> 
                                        <?= htmlspecialchars(substr($importSource, 0, 20)) . (strlen($importSource)>20 ? '...' : '') ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <?php if ($batchNotes): ?>
                            <div class="mt-4 p-3 bg-warning-light text-warning-dark rounded-lg text-sm border-0 d-flex gap-2">
                                <i data-lucide="sticky-note" class="icon-18 icon-min-18"></i>
                                <p class="m-0"><?= htmlspecialchars($batchNotes) ?></p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Left Side: Statistics & Actions -->
                    <div class="d-flex flex-column align-items-end gap-3 bd-actions-column">
                        
                        <!-- Quick Stats Box -->
                        <div class="d-flex gap-4 p-3 bg-subtle rounded-lg mb-2">
                            <div class="text-center px-2">
                                <div class="text-xs text-secondary mb-1" data-i18n="batch_detail.metrics.guarantees_count">عدد الضمانات</div>
                                <div class="font-bold text-lg"><?= count($guarantees) ?></div>
                            </div>
                            <div class="vr bg-gray-200"></div>
                            <div class="text-center px-2">
                                <div class="text-xs text-secondary mb-1" data-i18n="batch_detail.ui.txt_2ba38362">اجمالي القيمة</div>
                                <div class="font-bold text-lg text-primary"><?= number_format($totalAmount, 0) ?></div>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="d-flex align-items-center gap-2">
                            <button id="btn-edit-metadata" type="button" class="btn btn-outline-secondary btn-sm" title="تعديل الاسم والملاحظات" aria-label="تعديل اسم وملاحظات الدفعة" data-i18n-title="batch_detail.ui.txt_c4f6223f" data-i18n-aria-label="batch_detail.ui.txt_9a4e8268">
                                <i data-lucide="edit-3" class="icon-16"></i>
                            </button>
                            
                            <?php if (!$isClosed): ?>
                                <button id="btn-close-batch" type="button" class="btn btn-outline-danger btn-sm" title="إغلاق الدفعة للأرشفة" aria-label="إغلاق الدفعة" data-i18n-title="batch_detail.ui.txt_ea3a670f" data-i18n-aria-label="batch_detail.ui.txt_a3475a01">
                                    <i data-lucide="lock" class="icon-16"></i>
                                </button>
                                <?php if ($batchPrintableCount > 0 && $canPrintLetters): ?>
                                <button id="btn-print-ready" type="button" class="btn btn-success shadow-md">
                                    <i data-lucide="printer" class="icon-18"></i> <span data-i18n="batch_detail.actions.print_letters_prefix">طباعة خطابات</span> (<?= $batchPrintableCount ?>)
                                </button>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php if ($canReopenBatch): ?>
                                <button id="btn-reopen-batch" type="button" class="btn btn-warning shadow-md">
                                    <i data-lucide="unlock" class="icon-16"></i> <span data-i18n="batch_detail.ui.txt_21e3561f">إعادة فتح الدفعة</span>
                                </button>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Actions Toolbar (Visible when there are actionable records with no action yet) -->
        <?php if (!$isClosed && $actionableCount > 0): ?>
        <div class="card mb-4" id="actions-toolbar">
            <div class="card-body p-3 flex-between align-center">
                <div class="flex-align-center gap-2">
                    <button id="btn-extend" type="button" class="btn btn-primary btn-sm">
                        <i data-lucide="calendar-plus" class="icon-16"></i> تمديد المحدد
                    </button>
                    <button id="btn-release" type="button" class="btn btn-success btn-sm">
                        <i data-lucide="check-circle-2" class="icon-16"></i> إفراج المحدد
                    </button>
                </div>
                
                <div class="text-sm">
                    <button id="btn-select-all" type="button" class="btn-link" data-i18n="batch_detail.ui.txt_dc42087e">تحديد الكل</button>
                    <span class="text-muted mx-2">|</span>
                    <button id="btn-clear-selection" type="button" class="btn-link" data-i18n="batch_detail.ui.txt_41640caf">إلغاء التحديد</button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Guarantees Table -->
        <div class="card overflow-hidden">
            <div id="table-loading" class="loading-overlay is-hidden">
                <div class="spinner"></div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <?php if (!$isClosed): ?>
                                <th class="bd-select-col">
                                    <input id="table-select-all" type="checkbox" class="form-checkbox">
                                </th>
                            <?php endif; ?>
                            <th data-i18n="batch_detail.table.headers.guarantee_number">رقم الضمان</th>
                            <th data-i18n="batch_detail.table.headers.supplier">المورد</th>
                            <th data-i18n="batch_detail.table.headers.bank">البنك</th>
                            <th class="text-center" data-i18n="batch_detail.ui.txt_3b0975be">الإجراء</th>
                            <th class="text-center" data-i18n="batch_detail.table.headers.workflow_stage">مرحلة السير</th>
                            <th class="text-left" data-i18n="batch_detail.ui.txt_1a39dcff">القيمة</th>
                            <th class="text-center" data-i18n="batch_detail.table.headers.status">الحالة</th>
                            <th class="text-center" data-i18n="batch_detail.ui.txt_171a27a1">تفاصيل</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($guarantees as $g): ?>
                            <tr>
                                <?php if (!$isClosed): ?>
                                    <td class="text-center">
                                        <input type="checkbox" value="<?= $g['id'] ?>" class="form-checkbox guarantee-checkbox">
                                    </td>
                                <?php endif; ?>
                                <td class="font-bold"><?= htmlspecialchars($g['guarantee_number']) ?></td>
                                <td><?= htmlspecialchars($g['supplier_name']) ?></td>
                                <td><?= htmlspecialchars($g['bank_name']) ?></td>
                                <td class="text-center">
                                    <?php $rowAction = strtolower(trim((string)($g['active_action'] ?? ''))); ?>
                                    <?php if ($rowAction === 'release'): ?>
                                        <span class="badge badge-success" data-i18n="batch_detail.ui.txt_08ba0a7c">إفراج</span>
                                    <?php elseif ($rowAction === 'extension'): ?>
                                        <span class="badge badge-info" data-i18n="batch_detail.ui.txt_5e180f9f">تمديد</span>
                                    <?php elseif ($rowAction === 'reduction'): ?>
                                        <span class="badge badge-warning">تخفيض</span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php $workflowStageI18nKey = trim((string)($g['workflow_stage_i18n_key'] ?? '')); ?>
                                    <span class="badge <?= htmlspecialchars((string)$g['workflow_stage_class']) ?>" <?= $workflowStageI18nKey !== '' ? 'data-i18n="' . htmlspecialchars($workflowStageI18nKey, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
                                        <?= htmlspecialchars((string)$g['workflow_stage_label']) ?>
                                    </span>
                                </td>
                                <td class="font-mono text-left" dir="ltr">
                                    <?= number_format((float)($g['parsed']['amount'] ?? 0), 2) ?>
                                </td>
                                <td class="text-center">
                                    <?php 
                                    $statusVal = $g['decision_status'] ?? 'pending';
                                    $hasBasicData = ($g['supplier_id'] && $g['bank_id']);
                                    
                                    if ($statusVal === 'released'): ?>
                                        <div class="text-info flex-center gap-1 text-sm font-bold">
                                            <i data-lucide="unlock" class="icon-14"></i> مُفرج عنه
                                        </div>
                                    <?php elseif ($statusVal === 'ready' && $hasBasicData): ?>
                                        <div class="text-success flex-center gap-1 text-sm font-bold">
                                            <i data-lucide="check" class="icon-14"></i> جاهز
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted text-xs" data-i18n="batch_detail.timeline.txt_9c870230">يحتاج قرار</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php
                                    $guaranteeIdForLink = (int)($g['id'] ?? 0);
                                    $guaranteeNumberForLink = trim((string)($g['guarantee_number'] ?? ''));
                                    $detailQuery = ['id' => $guaranteeIdForLink];
                                    if ($guaranteeNumberForLink !== '') {
                                        // Keep target guarantee inside scoped navigation on index page.
                                        $detailQuery['search'] = $guaranteeNumberForLink;
                                    }
                                    $detailHref = '/index.php?' . http_build_query($detailQuery);
                                    ?>
                                    <a href="<?= htmlspecialchars($detailHref, ENT_QUOTES, 'UTF-8') ?>" class="btn-icon" aria-label="عرض تفاصيل الضمان <?= htmlspecialchars($g['guarantee_number']) ?>" data-i18n-aria-label="batch_detail.table.actions.view_guarantee_details">
                                        <i data-lucide="arrow-left" class="icon-18"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if (empty($guarantees)): ?>
                <div class="p-5 text-center text-muted">
                    <i data-lucide="inbox" class="icon-48 bd-empty-icon"></i>
                    <p data-i18n="batch_detail.ui.txt_f70076e3">لا توجد بيانات للعرض</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="/public/js/print-audit.js?v=<?= time() ?>"></script>

    <!-- JavaScript Application Logic -->
    <script>
        // --- 1. System Components (Toast, Modal, API) ---
        // Kept lightweight and clean
        const t = (key, fallback, params) => {
            if (window.WBGLI18n && typeof window.WBGLI18n.t === 'function') {
                return window.WBGLI18n.t(key, fallback, params);
            }
            return fallback || key;
        };

        const safeCreateIcons = () => {
            if (window.lucide && typeof window.lucide.createIcons === 'function') {
                window.lucide.createIcons();
            }
        };

        const Toast = {
            show(message, type = 'info', duration = 3000) {
                const container = document.getElementById('toast-container');
                const toast = document.createElement('div');
                
                // Simple standard toast styling
                let typeColor = type === 'success' ? 'var(--accent-success)' : (type === 'error' ? 'var(--accent-danger)' : 'var(--accent-info)');
                
                toast.className = 'card p-3 shadow-md flex-align-center gap-3 animate-slide-in';
                toast.style.borderRight = `4px solid ${typeColor}`;
                toast.style.background = 'white';
                toast.style.minWidth = '300px';

                const icons = {
                    success: '<i data-lucide="check-circle" class="toast-icon-success"></i>',
                    error: '<i data-lucide="alert-circle" class="toast-icon-error"></i>',
                    warning: '<i data-lucide="alert-triangle" class="toast-icon-warning"></i>',
                    info: '<i data-lucide="info" class="toast-icon-info"></i>'
                };
                
                toast.innerHTML = `${icons[type] || icons.info} <span class="font-medium">${message}</span>`;
                
                container.appendChild(toast);
                safeCreateIcons();

                setTimeout(() => {
                    toast.style.opacity = '0';
                    toast.style.transform = 'translateY(-20px)';
                    toast.style.transitionProperty = ['opacity', 'transform'].join(',');
                    toast.style.transitionDuration = '0.3s';
                    toast.style.transitionTimingFunction = 'ease';
                    setTimeout(() => toast.remove(), 300);
                }, duration);
            }
        };

        const Modal = {
            el: document.getElementById('modal-backdrop'),
            content: document.getElementById('modal-content'),
            lastFocusedEl: null,
            
            open(html) {
                this.lastFocusedEl = document.activeElement;
                this.content.innerHTML = html;
                this.el.classList.remove('is-hidden');
                this.el.setAttribute('aria-hidden', 'false');
                this.el.style.display = 'flex';
                // Trigger reflow to enable transition
                void this.el.offsetWidth; 
                this.el.classList.add('active');

                // Ensure dialog has a label for screen readers
                const heading = this.content.querySelector('[id="modal-title"]');
                if (heading && !heading.id) {
                    heading.id = 'modal-title';
                }

                const firstFocusable = this.content.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
                if (firstFocusable) {
                    firstFocusable.focus();
                } else {
                    this.content.focus();
                }
            },
            
            close() {
                this.el.classList.remove('active');
                this.el.setAttribute('aria-hidden', 'true');
                setTimeout(() => {
                    this.el.classList.add('is-hidden');
                    this.el.style.display = 'none';
                    this.content.innerHTML = '';
                    if (this.lastFocusedEl && typeof this.lastFocusedEl.focus === 'function') {
                        this.lastFocusedEl.focus();
                    }
                }, 300); // Wait for transition
            },

            bindA11y() {
                this.el.addEventListener('click', (event) => {
                    if (event.target === this.el) {
                        this.close();
                    }
                });

                document.addEventListener('keydown', (event) => {
                    if (event.key === 'Escape' && this.el.classList.contains('active')) {
                        this.close();
                    }
                });
            }
        };
        Modal.bindA11y();

        const API = {
            async post(action, data = {}) {
                try {
                    let options = {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ 
                            action, 
                            import_source: <?= json_encode($importSource) ?>, 
                            ...data 
                        })
                    };

                    if (action !== 'extend' && action !== 'release') {
                         const formData = new FormData();
                         formData.append('action', action);
                         formData.append('import_source', <?= json_encode($importSource) ?>);
                         for (const [key, value] of Object.entries(data)) {
                             formData.append(key, value);
                         }
                         options = { method: 'POST', body: formData };
                    }

                    const res = await fetch('/api/batches.php', options);
                    const json = await res.json();
                     
                    if (!json.success) {
                        const requestId = (json && typeof json.request_id === 'string') ? json.request_id.trim() : '';
                        const baseError = (json && typeof json.error === 'string' && json.error.trim() !== '')
                            ? json.error.trim()
                            : 'Server Error';
                        throw new Error(requestId !== '' ? `${baseError} [${requestId}]` : baseError);
                    }
                    return json;
                } catch (e) {
                    throw e;
                }
            }
        };

        // --- 2. Feature Logic ---

        const TableManager = {
            toggleSelectAll(checked) {
                document.querySelectorAll('.guarantee-checkbox').forEach(cb => cb.checked = checked);
            },
            
            getSelected() {
                return Array.from(document.querySelectorAll('.guarantee-checkbox:checked')).map(cb => cb.value);
            }
        };

        function closeModal() {
            Modal.close();
        }

        function bindModalActions() {
            if (!Modal.content || Modal.content.dataset.modalActionsBound === '1') {
                return;
            }

            Modal.content.dataset.modalActionsBound = '1';
            Modal.content.addEventListener('click', (event) => {
                const trigger = event.target.closest('[data-modal-action]');
                if (!trigger) {
                    return;
                }

                const modalAction = String(trigger.getAttribute('data-modal-action') || '');
                if (modalAction === 'cancel') {
                    closeModal();
                    return;
                }

                if (modalAction === 'confirm-batch') {
                    const batchAction = String(trigger.getAttribute('data-batch-action') || '');
                    if (batchAction !== '') {
                        confirmBatchAction(batchAction);
                    }
                    return;
                }

                if (modalAction === 'save-metadata') {
                    saveMetadata();
                }
            });
        }

        function bindPageActions() {
            const editMetadataBtn = document.getElementById('btn-edit-metadata');
            if (editMetadataBtn) {
                editMetadataBtn.addEventListener('click', openMetadataModal);
            }

            const closeBatchBtn = document.getElementById('btn-close-batch');
            if (closeBatchBtn) {
                closeBatchBtn.addEventListener('click', () => handleBatchAction('close'));
            }

            const reopenBatchBtn = document.getElementById('btn-reopen-batch');
            if (reopenBatchBtn) {
                reopenBatchBtn.addEventListener('click', () => handleBatchAction('reopen'));
            }

            const printReadyBtn = document.getElementById('btn-print-ready');
            if (printReadyBtn) {
                printReadyBtn.addEventListener('click', printReadyGuarantees);
            }

            const extendBtn = document.getElementById('btn-extend');
            if (extendBtn) {
                extendBtn.addEventListener('click', () => executeBulkAction('extend'));
            }

            const releaseBtn = document.getElementById('btn-release');
            if (releaseBtn) {
                releaseBtn.addEventListener('click', () => executeBulkAction('release'));
            }

            const selectAllBtn = document.getElementById('btn-select-all');
            if (selectAllBtn) {
                selectAllBtn.addEventListener('click', () => {
                    TableManager.toggleSelectAll(true);
                    const master = document.getElementById('table-select-all');
                    if (master) {
                        master.checked = true;
                    }
                });
            }

            const clearSelectionBtn = document.getElementById('btn-clear-selection');
            if (clearSelectionBtn) {
                clearSelectionBtn.addEventListener('click', () => {
                    TableManager.toggleSelectAll(false);
                    const master = document.getElementById('table-select-all');
                    if (master) {
                        master.checked = false;
                    }
                });
            }

            const selectAllCheckbox = document.getElementById('table-select-all');
            if (selectAllCheckbox) {
                selectAllCheckbox.addEventListener('change', () => {
                    TableManager.toggleSelectAll(Boolean(selectAllCheckbox.checked));
                });
            }
        }

        function handleBatchAction(action) {
            const actionText = action === 'close' ? t('batch_detail.ui.txt_a3475a01') : t('batch_detail.ui.txt_21e3561f');
            const actionColor = action === 'close' ? 'text-danger' : 'text-warning';
            const needsReason = action === 'reopen';
            
            Modal.open(`
                <div class="p-5 text-center">
                    <div class="mb-4 flex-center">
                        <div class="bd-modal-warn-icon-wrap">
                            <i data-lucide="alert-triangle" class="icon-32 bd-modal-warn-icon"></i>
                        </div>
                    </div>
                    <h3 id="modal-title" class="text-xl font-bold mb-2">${t('batch_detail.modal.confirm_action_title')}</h3>
                    <p class="text-secondary mb-6">${t('batch_detail.ui.txt_cd4b27e4')} <span class="${actionColor} font-bold">${actionText}</span>؟</p>
                    ${needsReason ? `
                    <div class="bd-modal-reason-wrap">
                        <label for="batch-action-reason" class="font-semibold text-sm">${t('batch_detail.ui.txt_8cbb7c49')} <span class="bd-required">*</span></label>
                        <textarea id="batch-action-reason" rows="3" class="form-textarea bd-reason-textarea" placeholder="${t('batch_detail.ui.txt_a16680d6')}"></textarea>
                    </div>
                    <div class="bd-breakglass-wrap">
                        <label class="bd-breakglass-label">
                            <input type="checkbox" id="break-glass-enabled">
                            <span class="font-semibold">${t('batch_detail.ui.txt_be8e8c15')}</span>
                        </label>
                        <div id="break-glass-fields" class="bd-breakglass-fields">
                            <input id="break-glass-ticket" type="text" class="form-input bd-breakglass-ticket" placeholder="${t('batch_detail.ui.txt_0ddb3458')}">
                            <textarea id="break-glass-reason" rows="2" class="form-textarea" placeholder="${t('batch_detail.ui.txt_30414e70')}"></textarea>
                        </div>
                    </div>` : ''}
                    <div class="flex-center gap-3">
                        <button type="button" data-modal-action="cancel" class="btn btn-secondary w-32">${t('batch_detail.modal.cancel')}</button>
                        <button type="button" data-modal-action="confirm-batch" data-batch-action="${action}" class="btn btn-primary w-32">${t('batch_detail.ui.txt_3b2f27d2')}</button>
                    </div>
                </div>
            `);
            safeCreateIcons();
            const bgEnabled = document.getElementById('break-glass-enabled');
            const bgFields = document.getElementById('break-glass-fields');
            if (bgEnabled && bgFields) {
                bgEnabled.addEventListener('change', () => {
                    bgFields.style.display = bgEnabled.checked ? 'block' : 'none';
                });
            }
        }

        async function confirmBatchAction(action) {
            try {
                const payload = {};
                if (action === 'reopen') {
                    const reasonEl = document.getElementById('batch-action-reason');
                    const reason = reasonEl ? reasonEl.value.trim() : '';
                    if (!reason) {
                        Toast.show(t('batch_detail.ui.txt_6b189c59'), 'warning');
                        return;
                    }
                    payload.reason = reason;

                    const bgEnabled = document.getElementById('break-glass-enabled');
                    if (bgEnabled && bgEnabled.checked) {
                        const bgTicket = document.getElementById('break-glass-ticket');
                        const bgReason = document.getElementById('break-glass-reason');
                        payload.break_glass_enabled = '1';
                        payload.break_glass_ticket = bgTicket ? bgTicket.value.trim() : '';
                        payload.break_glass_reason = bgReason ? bgReason.value.trim() : '';
                    }
                }

                Modal.close();
                document.getElementById('table-loading').style.display = 'flex';
                await API.post(action, payload);
                Toast.show(t('batch_detail.ui.txt_f3c9b30a'), 'success');
                setTimeout(() => location.reload(), 1000);
            } catch (e) {
                document.getElementById('table-loading').style.display = 'none';
                Toast.show(e.message, 'error');
            }
        }

        async function executeBulkAction(type) {
            const ids = TableManager.getSelected();
            if (ids.length === 0) {
                Toast.show(t('batch_detail.ui.txt_68f85d11'), 'warning');
                return;
            }

            try {
                document.getElementById('table-loading').style.display = 'flex';
                
                let data = { guarantee_ids: ids };
                
                if (type === 'extend') {
                    // Let server resolve +1 year from old expiry
                    data.new_expiry = null;
                } else if (type === 'release') {
                    data.reason = t('batch_detail.ui.txt_08ba0a7c');
                }

                const res = await API.post(type, data);

                const successMessage = type === 'extend'
                    ? t('batch_detail.bulk.extend_success', `تم تمديد ${res.extended_count} ضمان`, { count: res.extended_count || 0 })
                    : t('batch_detail.bulk.release_success', `تم اختيار الإفراج لـ ${res.released_count} ضمان`, { count: res.released_count || 0 });
                Toast.show(successMessage, 'success');

                const crossBatchImpacted = Number(res.cross_batch_impacted_count || 0);
                if (crossBatchImpacted > 0) {
                    const warningMessage = t(
                        'batch_detail.bulk.cross_batch_warning',
                        `تنبيه: ${crossBatchImpacted} ضمان/ضمانات من المحدد موجودة في دفعات أخرى، وسيظهر أثر الإجراء هناك أيضًا.`,
                        { count: crossBatchImpacted }
                    );
                    Toast.show(warningMessage, 'warning', 5200);
                }
                setTimeout(() => location.reload(), 1000);

            } catch (e) {
                document.getElementById('table-loading').style.display = 'none';
                Toast.show(e.message, 'error');
            }
        }

        function openMetadataModal() {
            Modal.open(`
                <div class="p-4">
                    <h3 id="modal-title" class="text-xl font-bold mb-4">${t('batch_detail.ui.txt_43f8ea13')}</h3>
                    <div class="form-group mb-3">
                        <label class="form-label">${t('batch_detail.ui.txt_9092e70c')}</label>
                        <input type="text" id="modal-batch-name" value="<?= htmlspecialchars($batchName) ?>" class="form-input">
                    </div>
                    <div class="form-group mb-3">
                        <label class="form-label">${t('batch_detail.modal.notes_label')}</label>
                        <textarea id="modal-batch-notes" rows="3" class="form-textarea"><?= htmlspecialchars($batchNotes) ?></textarea>
                    </div>
                    <div class="flex-end gap-2 mt-4">
                        <button type="button" data-modal-action="cancel" class="btn btn-secondary">${t('batch_detail.modal.cancel')}</button>
                        <button type="button" data-modal-action="save-metadata" class="btn btn-primary">${t('batch_detail.ui.txt_33081e44')}</button>
                    </div>
                </div>
            `);
        }

        async function saveMetadata() {
            const name = document.getElementById('modal-batch-name').value;
            const notes = document.getElementById('modal-batch-notes').value;

            try {
                await API.post('update_metadata', { batch_name: name, batch_notes: notes });
                Modal.close();
                Toast.show(t('batch_detail.ui.txt_4c52748e'), 'success');
                setTimeout(() => location.reload(), 800);
            } catch (e) {
                Toast.show(e.message, 'error');
            }
        }

        function printReadyGuarantees() {
            const canPrintLetters = <?= json_encode($canPrintLetters) ?>;
            const printBypassForSystemManager = <?= json_encode($printBypassForSystemManager) ?>;
            if (!canPrintLetters) {
                Toast.show(t('messages.print.permission_denied', 'لا تملك صلاحية طباعة الخطابات.'), 'warning');
                return;
            }

            const guarantees = <?= json_encode($guarantees) ?>;
            const ready = guarantees.filter((g) => {
                if (printBypassForSystemManager) {
                    const hasBasicData = Boolean(g.supplier_id) && Boolean(g.bank_id);
                    const hasAction = String(g.active_action || '').trim() !== '';
                    return hasBasicData && hasAction;
                }
                return Boolean(g.is_print_ready);
            });
            
            if (ready.length === 0) {
                Toast.show(t('batch_detail.ui.txt_d8a8cf26'), 'warning');
                return;
            }

            const ids = ready.map(g => g.id);
            const params = new URLSearchParams({
                ids: ids.join(','),
                batch_identifier: <?= json_encode($importSource, JSON_UNESCAPED_UNICODE) ?>
            });
            const includeTestData = <?= json_encode($includeTestData ? '1' : '') ?>;
            if (includeTestData) {
                params.set('include_test_data', includeTestData);
            }
            window.open(`/views/batch-print.php?${params.toString()}`);
            Toast.show(`تم فتح نافذة الطباعة لـ ${ids.length} خطاب`, 'success');
        }

        bindModalActions();
        bindPageActions();
        // Initialize Icons
        safeCreateIcons();

    </script>
</body>
</html>
