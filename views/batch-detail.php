<?php
/**
 * Batch Detail Page - Refactored for BGL3
 * Features: Modern UI, Toast Notifications, Modal Inputs, Loading States
 * Uses Standard Design System
 */

require_once __DIR__ . '/../app/Support/autoload.php';
use App\Support\Database;
use App\Support\Settings;

$db = Database::connect();
$importSource = $_GET['import_source'] ?? '';

if (!$importSource) {
    die('<div class="p-5 text-center text-danger font-bold">خطأ: import_source مطلوب</div>');
}

// 1. Fetch Metadata
$metadataStmt = $db->prepare("SELECT * FROM batch_metadata WHERE import_source = ?");
$metadataStmt->execute([$importSource]);
$metadata = $metadataStmt->fetch(PDO::FETCH_ASSOC);

// ✅ UPDATED: Hybrid query handles both old (no occurrences) and new (with occurrences) data
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
           COALESCE(o.occurred_at, g.imported_at) as occurrence_date
    FROM guarantees g
    -- LEFT JOIN for occurrences (old guarantees don't have them)
    LEFT JOIN guarantee_occurrences o ON g.id = o.guarantee_id
    
    -- 1. Decision row (single-row per guarantee)
    LEFT JOIN guarantee_decisions d ON d.guarantee_id = g.id
    LEFT JOIN guarantee_history h ON h.id = (
        SELECT h2.id
        FROM guarantee_history h2
        WHERE h2.guarantee_id = g.id
          AND (h2.event_subtype IN ('extension', 'reduction', 'release') OR h2.event_type = 'release')
        ORDER BY h2.created_at DESC, h2.id DESC
        LIMIT 1
    )
    
    -- 2. Join Supplier from Decision (Highest Priority)
    LEFT JOIN suppliers s_decided ON d.supplier_id = s_decided.id
    
    -- 3. Join for inferred supplier (Fallback)
    LEFT JOIN suppliers s ON g.normalized_supplier_name = s.official_name 
         OR json_extract(g.raw_data, '$.supplier') = s.official_name
         OR json_extract(g.raw_data, '$.supplier') = s.english_name
         
    LEFT JOIN banks b ON json_extract(g.raw_data, '$.bank') = b.english_name 
         OR json_extract(g.raw_data, '$.bank') = b.arabic_name
    -- Match either old (import_source) or new (batch_identifier in occurrences)
    WHERE g.import_source = ? OR o.batch_identifier = ?
    ORDER BY g.id ASC
");
$stmt->execute([$importSource, $importSource]);
$guarantees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Production Mode: Filter out test guarantees
$settings = Settings::getInstance();
if ($settings->isProductionMode()) {
    $guarantees = array_filter($guarantees, fn($g) => empty($g['is_test_data']));
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
?>
<?php 
// Calculate ready count for UI logic - 
// MUST match JS logic: supplier_id, bank_id, and active_action required for printing
$readyCount = count(array_filter($guarantees, fn($g) => 
    in_array($g['decision_status'] ?? '', ['ready', 'released']) && 
    $g['supplier_id'] && 
    $g['bank_id'] && 
    $g['active_action']
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
    <div id="toast-container" style="position: fixed; top: var(--space-md); right: var(--space-md); z-index: var(--z-toast); display: flex; flex-direction: column; gap: var(--space-sm);"></div>

    <!-- Modal Container -->
    <div id="modal-backdrop" class="modal-backdrop" style="display: none;">
        <div id="modal-content" class="modal-content">
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
                            <button onclick="openMetadataModal()" class="btn btn-outline-secondary btn-sm" title="تعديل الاسم والملاحظات">
                                <i data-lucide="edit-3" style="width: 16px;"></i>
                            </button>
                            
                            <?php if (!$isClosed): ?>
                                <button onclick="handleBatchAction('close')" class="btn btn-outline-danger btn-sm" title="إغلاق الدفعة للأرشفة">
                                    <i data-lucide="lock" style="width: 16px;"></i>
                                </button>
                                <?php if ($readyCount > 0): ?>
                                <button onclick="printReadyGuarantees()" class="btn btn-success shadow-md">
                                    <i data-lucide="printer" style="width: 18px;"></i> طباعة خطابات (<?= $readyCount ?>)
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

        <!-- Actions Toolbar -->
        <?php if (!$isClosed && $readyCount > 0): ?>
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
                                    <?php if ($g['active_action'] == 'release'): ?>
                                        <span class="badge badge-success">إفراج</span>
                                    <?php elseif ($g['active_action'] == 'extension'): ?>
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
                                    $isReady = (in_array($g['decision_status'] ?? '', ['ready', 'released']) && $g['supplier_id'] && $g['bank_id'] && $g['active_action']);
                                    if ($isReady): ?>
                                        <div class="text-success flex-center gap-1 text-sm font-bold">
                                            <i data-lucide="check" style="width: 14px;"></i> جاهز
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted text-xs">غير جاهز</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <a href="/index.php?id=<?= $g['id'] ?>" class="btn-icon">
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
            
            open(html) {
                this.content.innerHTML = html;
                this.el.style.display = 'flex';
                // Trigger reflow to enable transition
                void this.el.offsetWidth; 
                this.el.classList.add('active');
            },
            
            close() {
                this.el.classList.remove('active');
                setTimeout(() => {
                    this.el.style.display = 'none';
                    this.content.innerHTML = '';
                }, 300); // Wait for transition
            }
        };

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
            
            Modal.open(`
                <div class="p-5 text-center">
                    <div class="mb-4 flex-center">
                        <div class="p-3 rounded-full bg-warning-light">
                            <i data-lucide="alert-triangle" style="width: 32px; height: 32px; color: var(--accent-warning);"></i>
                        </div>
                    </div>
                    <h3 class="text-xl font-bold mb-2">تأكيد الإجراء</h3>
                    <p class="text-secondary mb-6">هل أنت متأكد من رغبتك في <span class="${actionColor} font-bold">${actionText}</span>؟</p>
                    <div class="flex-center gap-3">
                        <button onclick="Modal.close()" class="btn btn-secondary w-32">إلغاء</button>
                        <button onclick="confirmBatchAction('${action}')" class="btn btn-primary w-32">نعم، نفذ</button>
                    </div>
                </div>
            `);
            lucide.createIcons();
        }

        async function confirmBatchAction(action) {
            Modal.close();
            try {
                document.getElementById('table-loading').style.display = 'flex';
                await API.post(action);
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
                    data.new_expiry = new Date(new Date().setFullYear(new Date().getFullYear() + 1))
                        .toISOString().split('T')[0];
                } else if (type === 'release') {
                    data.reason = 'إفراج جماعي';
                }

                const res = await API.post(type, data);
                
                Toast.show(
                    type === 'extend' ? `تم تمديد ${res.extended} ضمان` : `تم إفراج ${res.released} ضمان`, 
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
                    <h3 class="text-xl font-bold mb-4">تعديل بيانات الدفعة</h3>
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
            const ready = guarantees.filter(g => g.supplier_id && g.bank_id && g.active_action);
            
            if (ready.length === 0) {
                Toast.show('لا توجد ضمانات جاهزة للطباعة', 'warning');
                return;
            }

            const ids = ready.map(g => g.id);
            window.open(`/views/batch-print.php?ids=${ids.join(',')}`);
            Toast.show(`تم فتح نافذة الطباعة لـ ${ids.length} خطاب`, 'success');
        }

        // Initialize Icons
        lucide.createIcons();

    </script>
</body>
</html>