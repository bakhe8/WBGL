<?php
/**
 * Batch Detail Page - Refactored for WBGL
 * Features: Modern UI, Toast Notifications, Modal Inputs, Loading States
 * Uses Standard Design System
 */

require_once __DIR__ . '/../app/Support/autoload.php';
use App\Support\Database;
use App\Support\Settings;
use App\Support\ViewPolicy;

ViewPolicy::guardView('batch-detail.php');

$db = Database::connect();
$importSource = $_GET['import_source'] ?? '';

if (!$importSource) {
    die('<div class="p-5 text-center text-danger font-bold">خطأ: import_source مطلوب</div>');
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
           d.supplier_id,
           d.bank_id,
           CASE
               WHEN h.event_subtype IN ('extension', 'reduction', 'release') THEN h.event_subtype
               WHEN h.event_type = 'release' THEN 'release'
               ELSE NULL
           END as last_action,
           h.created_at as last_action_at,
           o.occurred_at as occurrence_date
    FROM guarantee_occurrences o
    JOIN guarantees g ON g.id = o.guarantee_id
    
    -- 1. Decision row (single-row per guarantee)
    LEFT JOIN guarantee_decisions d ON d.guarantee_id = g.id
    LEFT JOIN guarantee_history h ON h.id = (
        SELECT h_sub.id
        FROM guarantee_history h_sub
        WHERE h_sub.guarantee_id = g.id
          AND (h_sub.event_subtype IN ('extension', 'reduction', 'release') OR h_sub.event_type = 'release')
        ORDER BY h_sub.created_at DESC, h_sub.id DESC
        LIMIT 1
    )
    
    -- 2. Join Supplier from Decision (Highest Priority)
    LEFT JOIN suppliers s_decided ON d.supplier_id = s_decided.id
    
    -- 3. Join for inferred supplier (Fallback)
    LEFT JOIN suppliers s ON g.normalized_supplier_name = s.official_name 
         OR {$rawSupplierExpr} = s.official_name
         OR {$rawSupplierExpr} = s.english_name
         
    LEFT JOIN banks b ON {$rawBankExpr} = b.english_name 
         OR {$rawBankExpr} = b.arabic_name
    WHERE o.batch_identifier = ?
    ORDER BY g.id ASC
");
$stmt->execute([$importSource]);
$guarantees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Production Mode: Filter out test guarantees
$settings = Settings::getInstance();
if ($settings->isProductionMode()) {
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
$batchName = $metadata['batch_name'] ?? 'دفعة ' . substr($importSource, 0, 30);
$status = $metadata['status'] ?? 'active';
$isClosed = ($status === 'completed');
$batchNotes = $metadata['batch_notes'] ?? '';

// Helper to parse JSON safely
foreach ($guarantees as &$g) {
    $g['parsed'] = json_decode($g['raw_data'], true) ?? [];
    $g['supplier_name'] = $g['supplier_name'] ?: ($g['parsed']['supplier'] ?? '-');
    $g['bank_name'] = $g['bank_name'] ?: ($g['parsed']['bank'] ?? '-');
}
unset($g);
// Calculate counts for UI logic
// 1. Actionable Count: Matched but no action yet (Ready for batch extend/release)
$actionableCount = count(array_filter($guarantees, fn($g) => 
    ($g['decision_status'] ?? '') === 'ready' && 
    $g['supplier_id'] && 
    $g['bank_id'] && 
    empty($g['last_action'])
));

// 2. Print Ready Count: Matched AND has action (from history)
$printReadyCount = count(array_filter($guarantees, fn($g) => 
    in_array($g['decision_status'] ?? '', ['ready', 'released']) && 
    $g['supplier_id'] && 
    $g['bank_id'] && 
    !empty($g['last_action'])
));
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
    
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body>

    <!-- Unified Header -->
    <?php include __DIR__ . '/../partials/unified-header.php'; ?>
    
    <!-- Toast Container -->
    <div id="toast-container" role="status" aria-live="polite" style="position: fixed; top: var(--space-md); right: var(--space-md); z-index: var(--z-toast); display: flex; flex-direction: column; gap: var(--space-sm);"></div>

    <!-- Modal Container -->
    <div id="modal-backdrop" class="modal-backdrop" style="display: none;" aria-hidden="true">
        <div id="modal-content" class="modal-content" role="dialog" aria-modal="true" aria-labelledby="modal-title" tabindex="-1">
            <!-- Dynamic Content -->
        </div>
    </div>

    <!-- Main Content -->
    <div class="page-container">
        
        <!-- Batch Header (Redesigned) -->
        <div class="card mb-5 border-0 shadow-sm">
            <div class="card-body p-6">
                <div class="row align-items-start gap-4" style="display: flex; flex-wrap: wrap; justify-content: space-between;">
                    
                    <!-- Right Side: Info -->
                    <div style="flex: 1; min-width: 300px;">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="p-3 bg-primary-light rounded-circle text-primary">
                                <i data-lucide="layers" style="width: 24px; height: 24px;"></i>
                            </div>
                            <div>
                                <h1 class="text-2xl font-bold mb-1 d-flex align-items-center gap-2">
                                    <?= htmlspecialchars($batchName) ?>
                                    <span class="badge text-xs <?= $isClosed ? 'badge-neutral' : 'badge-success' ?>">
                                        <?= $isClosed ? 'مغلقة' : 'نشطة' ?>
                                    </span>
                                </h1>
                                <div class="text-secondary text-sm d-flex align-items-center gap-4">
                                    <span class="d-flex align-items-center gap-1" title="تاريخ الاستيراد">
                                        <i data-lucide="calendar" style="width: 14px;"></i> 
                                        <?= date('Y-m-d H:i', strtotime($guarantees[0]['occurrence_date'] ?? 'now')) ?>
                                    </span>
                                    <span class="d-flex align-items-center gap-1" title="المصدر">
                                        <i data-lucide="file-spreadsheet" style="width: 14px;"></i> 
                                        <?= htmlspecialchars(substr($importSource, 0, 20)) . (strlen($importSource)>20 ? '...' : '') ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <?php if ($batchNotes): ?>
                            <div class="mt-4 p-3 bg-warning-light text-warning-dark rounded-lg text-sm border-0 d-flex gap-2">
                                <i data-lucide="sticky-note" style="width: 18px; min-width: 18px;"></i>
                                <p class="m-0"><?= htmlspecialchars($batchNotes) ?></p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Left Side: Statistics & Actions -->
                    <div class="d-flex flex-column align-items-end gap-3" style="min-width: 250px;">
                        
                        <!-- Quick Stats Box -->
                        <div class="d-flex gap-4 p-3 bg-subtle rounded-lg mb-2">
                            <div class="text-center px-2">
                                <div class="text-xs text-secondary mb-1">عدد الضمانات</div>
                                <div class="font-bold text-lg"><?= count($guarantees) ?></div>
                            </div>
                            <div class="vr bg-gray-200"></div>
                            <div class="text-center px-2">
                                <div class="text-xs text-secondary mb-1">اجمالي القيمة</div>
                                <div class="font-bold text-lg text-primary"><?= number_format($totalAmount, 0) ?></div>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="d-flex align-items-center gap-2">
                            <button onclick="openMetadataModal()" class="btn btn-outline-secondary btn-sm" title="تعديل الاسم والملاحظات" aria-label="تعديل اسم وملاحظات الدفعة">
                                <i data-lucide="edit-3" style="width: 16px;"></i>
                            </button>
                            
                            <?php if (!$isClosed): ?>
                                <button onclick="handleBatchAction('close')" class="btn btn-outline-danger btn-sm" title="إغلاق الدفعة للأرشفة" aria-label="إغلاق الدفعة">
                                    <i data-lucide="lock" style="width: 16px;"></i>
                                </button>
                                <?php if ($printReadyCount > 0): ?>
                                <button onclick="printReadyGuarantees()" class="btn btn-success shadow-md">
                                    <i data-lucide="printer" style="width: 18px;"></i> طباعة خطابات (<?= $printReadyCount ?>)
                                </button>
                                <?php endif; ?>
                            <?php else: ?>
                                <button onclick="handleBatchAction('reopen')" class="btn btn-warning shadow-md">
                                    <i data-lucide="unlock" style="width: 16px;"></i> إعادة فتح الدفعة
                                </button>
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
                    <button id="btn-extend" onclick="executeBulkAction('extend')" class="btn btn-primary btn-sm">
                        <i data-lucide="calendar-plus" style="width: 16px;"></i> تمديد المحدد
                    </button>
                    <button id="btn-release" onclick="executeBulkAction('release')" class="btn btn-success btn-sm">
                        <i data-lucide="check-circle-2" style="width: 16px;"></i> إفراج المحدد
                    </button>
                </div>
                
                <div class="text-sm">
                    <button onclick="TableManager.toggleSelectAll(true)" class="btn-link">تحديد الكل</button>
                    <span class="text-muted mx-2">|</span>
                    <button onclick="TableManager.toggleSelectAll(false)" class="btn-link">إلغاء التحديد</button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Guarantees Table -->
        <div class="card overflow-hidden">
            <div id="table-loading" class="loading-overlay" style="display: none;">
                <div class="spinner"></div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <?php if (!$isClosed): ?>
                                <th style="width: 40px;">
                                    <input type="checkbox" onchange="TableManager.toggleSelectAll(this.checked)" class="form-checkbox">
                                </th>
                            <?php endif; ?>
                            <th>رقم الضمان</th>
                            <th>المورد</th>
                            <th>البنك</th>
                            <th class="text-center">الإجراء</th>
                            <th class="text-left">القيمة</th>
                            <th class="text-center">الحالة</th>
                            <th class="text-center">تفاصيل</th>
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
                                    <?php if ($g['last_action'] == 'release'): ?>
                                        <span class="badge badge-success">إفراج</span>
                                    <?php elseif ($g['last_action'] == 'extension'): ?>
                                        <span class="badge badge-info">تمديد</span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
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
                                            <i data-lucide="unlock" style="width: 14px;"></i> مُفرج عنه
                                        </div>
                                    <?php elseif ($statusVal === 'ready' && $hasBasicData): ?>
                                        <div class="text-success flex-center gap-1 text-sm font-bold">
                                            <i data-lucide="check" style="width: 14px;"></i> جاهز
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted text-xs">يحتاج قرار</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <a href="/index.php?id=<?= $g['id'] ?>" class="btn-icon" aria-label="عرض تفاصيل الضمان <?= htmlspecialchars($g['guarantee_number']) ?>">
                                        <i data-lucide="arrow-left" style="width: 18px;"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if (empty($guarantees)): ?>
                <div class="p-5 text-center text-muted">
                    <i data-lucide="inbox" style="width: 48px; height: 48px; margin-bottom: 1rem; opacity: 0.5;"></i>
                    <p>لا توجد بيانات للعرض</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="/public/js/print-audit.js?v=<?= time() ?>"></script>

    <!-- JavaScript Application Logic -->
    <script>
        // --- 1. System Components (Toast, Modal, API) ---
        // Kept lightweight and clean

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
                    success: '<i data-lucide="check-circle" style="color:var(--accent-success)"></i>',
                    error: '<i data-lucide="alert-circle" style="color:var(--accent-danger)"></i>',
                    warning: '<i data-lucide="alert-triangle" style="color:var(--accent-warning)"></i>',
                    info: '<i data-lucide="info" style="color:var(--accent-info)"></i>'
                };
                
                toast.innerHTML = `${icons[type] || icons.info} <span class="font-medium">${message}</span>`;
                
                container.appendChild(toast);
                lucide.createIcons();

                setTimeout(() => {
                    toast.style.opacity = '0';
                    toast.style.transform = 'translateY(-20px)';
                    toast.style.transition = 'all 0.3s ease';
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
                this.el.setAttribute('aria-hidden', 'false');
                this.el.style.display = 'flex';
                // Trigger reflow to enable transition
                void this.el.offsetWidth; 
                this.el.classList.add('active');

                // Ensure dialog has a label for screen readers
                const heading = this.content.querySelector('h1, h2, h3');
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
                    
                    if (!json.success) throw new Error(json.error || 'Server Error');
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

        function handleBatchAction(action) {
            const actionText = action === 'close' ? 'إغلاق الدفعة' : 'إعادة فتح الدفعة';
            const actionColor = action === 'close' ? 'text-danger' : 'text-warning';
            const needsReason = action === 'reopen';
            
            Modal.open(`
                <div class="p-5 text-center">
                    <div class="mb-4 flex-center">
                        <div class="p-3 rounded-full bg-warning-light">
                            <i data-lucide="alert-triangle" style="width: 32px; height: 32px; color: var(--accent-warning);"></i>
                        </div>
                    </div>
                    <h3 id="modal-title" class="text-xl font-bold mb-2">تأكيد الإجراء</h3>
                    <p class="text-secondary mb-6">هل أنت متأكد من رغبتك في <span class="${actionColor} font-bold">${actionText}</span>؟</p>
                    ${needsReason ? `
                    <div style="text-align: right; margin-bottom: 12px;">
                        <label for="batch-action-reason" class="font-semibold text-sm">سبب إعادة الفتح <span style="color:#dc2626">*</span></label>
                        <textarea id="batch-action-reason" rows="3" class="form-textarea" placeholder="اكتب سبب إعادة فتح الدفعة..." style="margin-top:8px;"></textarea>
                    </div>
                    <div style="text-align: right; margin-bottom: 16px; border:1px dashed #f59e0b; border-radius:10px; padding:10px;">
                        <label style="display:flex; align-items:center; gap:8px;">
                            <input type="checkbox" id="break-glass-enabled">
                            <span class="font-semibold">وضع الطوارئ (Break-glass)</span>
                        </label>
                        <div id="break-glass-fields" style="display:none; margin-top:10px;">
                            <input id="break-glass-ticket" type="text" class="form-input" placeholder="رقم التذكرة/الحادث (إلزامي بالطوارئ)" style="margin-bottom:8px;">
                            <textarea id="break-glass-reason" rows="2" class="form-textarea" placeholder="سبب الطوارئ"></textarea>
                        </div>
                    </div>` : ''}
                    <div class="flex-center gap-3">
                        <button onclick="Modal.close()" class="btn btn-secondary w-32">إلغاء</button>
                        <button onclick="confirmBatchAction('${action}')" class="btn btn-primary w-32">نعم، نفذ</button>
                    </div>
                </div>
            `);
            lucide.createIcons();
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
                        Toast.show('سبب إعادة فتح الدفعة مطلوب', 'warning');
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
                Toast.show('تم تنفيذ العملية بنجاح', 'success');
                setTimeout(() => location.reload(), 1000);
            } catch (e) {
                document.getElementById('table-loading').style.display = 'none';
                Toast.show(e.message, 'error');
            }
        }

        async function executeBulkAction(type) {
            const ids = TableManager.getSelected();
            if (ids.length === 0) {
                Toast.show('الرجاء اختيار ضمان واحد على الأقل', 'warning');
                return;
            }

            try {
                document.getElementById('table-loading').style.display = 'flex';
                
                let data = { guarantee_ids: ids };
                
                if (type === 'extend') {
                    // Let server resolve +1 year from old expiry
                    data.new_expiry = null;
                } else if (type === 'release') {
                    data.reason = 'إفراج جماعي';
                }

                const res = await API.post(type, data);
                
                Toast.show(
                    type === 'extend' ? `تم تمديد ${res.extended_count} ضمان` : `تم إفراج ${res.released_count} ضمان`, 
                    'success'
                );
                setTimeout(() => location.reload(), 1000);

            } catch (e) {
                document.getElementById('table-loading').style.display = 'none';
                Toast.show(e.message, 'error');
            }
        }

        function openMetadataModal() {
            Modal.open(`
                <div class="p-4">
                    <h3 id="modal-title" class="text-xl font-bold mb-4">تعديل بيانات الدفعة</h3>
                    <div class="form-group mb-3">
                        <label class="form-label">اسم الدفعة</label>
                        <input type="text" id="modal-batch-name" value="<?= htmlspecialchars($batchName) ?>" class="form-input">
                    </div>
                    <div class="form-group mb-3">
                        <label class="form-label">ملاحظات</label>
                        <textarea id="modal-batch-notes" rows="3" class="form-textarea"><?= htmlspecialchars($batchNotes) ?></textarea>
                    </div>
                    <div class="flex-end gap-2 mt-4">
                        <button onclick="Modal.close()" class="btn btn-secondary">إلغاء</button>
                        <button onclick="saveMetadata()" class="btn btn-primary">حفظ التغييرات</button>
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
                Toast.show('تم حفظ البيانات بنجاح', 'success');
                setTimeout(() => location.reload(), 800);
            } catch (e) {
                Toast.show(e.message, 'error');
            }
        }

        function printReadyGuarantees() {
            const guarantees = <?= json_encode($guarantees) ?>;
            const ready = guarantees.filter(g => g.supplier_id && g.bank_id && g.last_action);
            
            if (ready.length === 0) {
                Toast.show('لا توجد ضمانات جاهزة للطباعة', 'warning');
                return;
            }

            const ids = ready.map(g => g.id);
            const params = new URLSearchParams({
                ids: ids.join(','),
                batch_identifier: <?= json_encode($importSource, JSON_UNESCAPED_UNICODE) ?>
            });
            window.open(`/views/batch-print.php?${params.toString()}`);
            Toast.show(`تم فتح نافذة الطباعة لـ ${ids.length} خطاب`, 'success');
        }

        // Initialize Icons
        lucide.createIcons();

    </script>
</body>
</html>
