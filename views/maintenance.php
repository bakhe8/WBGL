<?php
require_once __DIR__ . '/../app/Support/autoload.php';

use App\Repositories\GuaranteeRepository;
use App\Services\OperationalAlertService;
use App\Services\OperationalMetricsService;
use App\Support\Database;
use App\Support\Settings;
use App\Support\ViewPolicy;

ViewPolicy::guardView('maintenance.php');

$db = Database::connect();
$repo = new GuaranteeRepository($db);
$settings = Settings::getInstance();
$isProd = $settings->isProductionMode();
$maintenanceLocale = [];
$maintenanceLocalePath = __DIR__ . '/../public/locales/ar/maintenance.json';
if (is_file($maintenanceLocalePath)) {
    $decodedLocale = json_decode((string)file_get_contents($maintenanceLocalePath), true);
    if (is_array($decodedLocale)) {
        $maintenanceLocale = $decodedLocale;
    }
}
$t = static function (string $key, array $params = []) use ($maintenanceLocale): string {
    $value = $maintenanceLocale[$key] ?? $key;
    if (!is_string($value)) {
        $value = $key;
    }
    foreach ($params as $token => $replacement) {
        $value = str_replace('{{' . (string)$token . '}}', (string)$replacement, $value);
    }
    return $value;
};

// Get statistics
$stats = $repo->getTestDataStats();
$realCount = $repo->count();
$testCount = $repo->count(['test_data_only' => true]);
$totalCount = $repo->count(['include_test_data' => true]);
$opsMetrics = OperationalMetricsService::snapshot();
$opsAlerts = OperationalAlertService::evaluate($opsMetrics);
$opsCounters = is_array($opsMetrics['counters'] ?? null) ? $opsMetrics['counters'] : [];
$opsAlertRows = is_array($opsAlerts['alerts'] ?? null) ? $opsAlerts['alerts'] : [];
$opsTriggered = array_values(array_filter(
    $opsAlertRows,
    static fn(array $row): bool => (string)($row['status'] ?? '') === 'triggered'
));
$opsGeneratedAt = (string)($opsMetrics['generated_at'] ?? date('c'));

// Handle deletion requests
$deleteResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($isProd) {
        $deleteResult = [
            'success' => false,
            'message_key' => 'maintenance.feedback.production_mode_blocked',
            'message' => $t('maintenance.feedback.production_mode_blocked'),
        ];
    } else {
        $confirmation = $_POST['confirmation'] ?? '';

        if ($confirmation !== 'DELETE') {
            $deleteResult = [
                'success' => false,
                'message_key' => 'maintenance.feedback.invalid_confirmation',
                'message' => $t('maintenance.feedback.invalid_confirmation'),
            ];
        } else {
            try {
                $action = (string)($_POST['action'] ?? '');

                switch ($action) {
                    case 'delete_test_data':
                    case 'delete_all':
                        $deleted = $repo->deleteTestData();
                        $deleteResult = [
                            'success' => true,
                            'message_key' => 'maintenance.feedback.delete_all_success',
                            'message' => $t('maintenance.feedback.delete_all_success', ['count' => $deleted]),
                        ];
                        break;

                    case 'delete_batch':
                        $batchId = trim((string)($_POST['batch_id'] ?? ''));
                        if ($batchId === '') {
                            $deleteResult = [
                                'success' => false,
                                'message_key' => 'maintenance.feedback.batch_id_required',
                                'message' => $t('maintenance.feedback.batch_id_required'),
                            ];
                            break;
                        }
                        $deleted = $repo->deleteTestData($batchId);
                        $deleteResult = [
                            'success' => true,
                            'message_key' => 'maintenance.feedback.delete_batch_success',
                            'message' => $t('maintenance.feedback.delete_batch_success', ['count' => $deleted, 'batch_id' => $batchId]),
                        ];
                        break;

                    case 'delete_older':
                        $olderThan = trim((string)($_POST['older_than'] ?? ''));
                        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $olderThan)) {
                            $deleteResult = [
                                'success' => false,
                                'message_key' => 'maintenance.feedback.invalid_date',
                                'message' => $t('maintenance.feedback.invalid_date'),
                            ];
                            break;
                        }
                        $deleted = $repo->deleteTestData(null, $olderThan);
                        $deleteResult = [
                            'success' => true,
                            'message_key' => 'maintenance.feedback.delete_older_success',
                            'message' => $t('maintenance.feedback.delete_older_success', ['count' => $deleted, 'older_than' => $olderThan]),
                        ];
                        break;

                    default:
                        $deleteResult = [
                            'success' => false,
                            'message_key' => 'maintenance.feedback.unknown_action',
                            'message' => $t('maintenance.feedback.unknown_action'),
                        ];
                }

                // Refresh stats after deletion
                if ($deleteResult['success']) {
                    $stats = $repo->getTestDataStats();
                    $realCount = $repo->count();
                    $testCount = $repo->count(['test_data_only' => true]);
                    $totalCount = $repo->count(['include_test_data' => true]);
                }

            } catch (Exception $e) {
                $deleteResult = [
                    'success' => false,
                    'message_key' => 'maintenance.feedback.generic_error',
                    'message' => $t('maintenance.feedback.generic_error', ['error' => $e->getMessage()]),
                ];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-i18n="maintenance.meta.title">أدوات الصيانة - WBGL System v3.0</title>
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Core Styles -->
    <link rel="stylesheet" href="../public/css/design-system.css">
    <link rel="stylesheet" href="../public/css/components.css">
    <link rel="stylesheet" href="../public/css/layout.css">
    <style>
        /* FIX: Enable scrolling for maintenance page */
        body {
            overflow-y: auto !important;
        }

        .maintenance-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 12px;
            margin-bottom: 2rem;
        }
        
        .danger-zone {
            border: 2px solid #ef4444;
            border-radius: 8px;
            padding: 1.5rem;
            background: #fef2f2;
            margin-top: 2rem;
        }
        
        .danger-zone h3 {
            color: #dc2626;
            margin-top: 0;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: bold;
            color: #3b82f6;
        }
        
        .stat-label {
            color: #6b7280;
            font-size: 0.875rem;
            margin-top: 0.5rem;
        }
        
        .warning-box {
            background: #fef3c7;
            border: 1px solid #f59e0b;
            border-radius: 6px;
            padding: 1rem;
            margin: 1rem 0;
        }
        
        .delete-option {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            border: 1px solid #e5e7eb;
        }
        
        .delete-option:hover {
            border-color: #3b82f6;
        }
        
        .btn-danger {
            background: #ef4444;
            color: white;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 1rem;
        }
        
        .btn-danger:hover {
            background: #dc2626;
        }
        
        .btn-danger:disabled {
            background: #9ca3af;
            cursor: not-allowed;
        }
        
        .confirmation-input {
            padding: 0.5rem;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            width: 200px;
            margin-left: 1rem;
        }

        .ops-section {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
        }

        .ops-meta {
            color: #6b7280;
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }

        .ops-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .ops-card {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 0.9rem;
            background: #f9fafb;
        }

        .ops-card-value {
            font-size: 1.4rem;
            font-weight: 800;
            color: #111827;
        }

        .ops-card-label {
            font-size: 0.8rem;
            color: #6b7280;
            margin-top: 0.25rem;
        }

        .ops-alerts {
            border-top: 1px solid #e5e7eb;
            padding-top: 0.85rem;
        }

        .ops-alert-row {
            display: flex;
            justify-content: space-between;
            gap: 0.75rem;
            padding: 0.5rem 0.65rem;
            border: 1px solid #ef4444;
            background: #fef2f2;
            color: #991b1b;
            border-radius: 6px;
            margin-bottom: 0.5rem;
        }

        .ops-alert-ok {
            border: 1px solid #10b981;
            background: #ecfdf5;
            color: #065f46;
            border-radius: 6px;
            padding: 0.65rem;
        }

        .maintenance-container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1rem;
        }

        .maintenance-title {
            margin: 0;
        }

        .maintenance-subtitle {
            margin: 0.5rem 0 0 0;
            opacity: 0.9;
        }

        .ops-title {
            margin-top: 0;
        }

        .ops-alert-title {
            margin: 0 0 0.75rem 0;
        }

        .delete-result {
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1rem;
        }

        .delete-result--success {
            background: #d1fae5;
            border: 1px solid #10b981;
        }

        .delete-result--error {
            background: #fee2e2;
            border: 1px solid #ef4444;
        }

        .warning-box--info {
            background: #eff6ff;
            border-color: #3b82f6;
        }

        .warning-box--danger {
            background: #fff7ed;
            border-color: #ea580c;
        }

        .stat-value--success {
            color: #10b981;
        }

        .stat-value--warning {
            color: #f59e0b;
        }

        .stat-value--indigo {
            color: #6366f1;
        }

        .delete-option-title {
            margin-top: 0;
        }

        .maintenance-empty-state {
            text-align: center;
            padding: 2rem;
            color: #10b981;
        }

        .maintenance-tips {
            margin-top: 2rem;
            padding: 1rem;
            background: #f9fafb;
            border-radius: 6px;
        }
    </style>
</head>
<body data-i18n-namespaces="common,maintenance,messages">
    
    <!-- Unified Header -->
    <?php include __DIR__ . '/../partials/unified-header.php'; ?>
    
    <div class="container maintenance-container">
        <div class="maintenance-header">
            <h1 class="maintenance-title" data-i18n="maintenance.ui.header_title">🛠️ أدوات الصيانة والتنظيف</h1>
            <p class="maintenance-subtitle" data-i18n="maintenance.ui.header_subtitle">إدارة بيانات الاختبار وتنظيف قاعدة البيانات</p>
        </div>

        <div class="ops-section">
            <h2 class="ops-title" data-i18n="maintenance.ui.ops_title">📈 لوحة المراقبة التشغيلية</h2>
            <div class="ops-meta">
                <span data-i18n="maintenance.ui.ops_last_update">آخر تحديث:</span>
                <?= htmlspecialchars(date(DATE_ATOM, strtotime($opsGeneratedAt) ?: time())) ?> |
                <span data-i18n="maintenance.ui.ops_active_alerts">التنبيهات النشطة:</span>
                <?= count($opsTriggered) ?>
            </div>
            <div class="ops-grid">
                <div class="ops-card">
                    <div class="ops-card-value"><?= (int)($opsCounters['open_dead_letters'] ?? 0) ?></div>
                    <div class="ops-card-label" data-i18n="maintenance.ui.ops_dead_letters_open">Dead Letters مفتوحة</div>
                </div>
                <div class="ops-card">
                    <div class="ops-card-value"><?= (int)($opsCounters['scheduler_failures_24h'] ?? 0) ?></div>
                    <div class="ops-card-label" data-i18n="maintenance.ui.ops_scheduler_failures_24h">فشل Scheduler (24h)</div>
                </div>
                <div class="ops-card">
                    <div class="ops-card-value"><?= (int)($opsCounters['api_access_denied_24h'] ?? 0) ?></div>
                    <div class="ops-card-label" data-i18n="maintenance.ui.ops_api_denied_24h">رفض API (24h)</div>
                </div>
                <div class="ops-card">
                    <div class="ops-card-value"><?= (int)($opsCounters['unread_notifications'] ?? 0) ?></div>
                    <div class="ops-card-label" data-i18n="maintenance.ui.ops_unread_notifications">إشعارات غير مقروءة</div>
                </div>
                <div class="ops-card">
                    <div class="ops-card-value"><?= (int)($opsCounters['pending_undo_requests'] ?? 0) ?></div>
                    <div class="ops-card-label" data-i18n="maintenance.ui.ops_pending_undo_requests">Undo Requests معلقة</div>
                </div>
            </div>
            <div class="ops-alerts">
                <h3 class="ops-alert-title" data-i18n="maintenance.ui.ops_alerts_title">🚨 التنبيهات</h3>
                <?php if (empty($opsTriggered)): ?>
                    <div class="ops-alert-ok" data-i18n="maintenance.ui.ops_no_active_alerts">لا توجد تنبيهات تشغيلية نشطة حاليًا.</div>
                <?php else: ?>
                    <?php foreach ($opsTriggered as $alert): ?>
                        <div class="ops-alert-row">
                            <div><?= htmlspecialchars((string)($alert['label'] ?? $t('maintenance.ui.alert_default'))) ?></div>
                            <div>
                                <span data-i18n="maintenance.ui.ops_value_label">القيمة:</span> <?= htmlspecialchars((string)($alert['value'] ?? '-')) ?> |
                                <span data-i18n="maintenance.ui.ops_threshold_label">الحد:</span> <?= htmlspecialchars((string)($alert['threshold'] ?? '-')) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if ($deleteResult): ?>
            <div class="<?= $deleteResult['success'] ? 'alert-success delete-result delete-result--success' : 'alert-error delete-result delete-result--error' ?>"
                 data-i18n="<?= htmlspecialchars((string)($deleteResult['message_key'] ?? '')) ?>"
                 data-i18n-fallback="<?= htmlspecialchars((string)($deleteResult['message'] ?? '')) ?>">
                <?= htmlspecialchars((string)($deleteResult['message'] ?? '')) ?>
            </div>
        <?php endif; ?>
        
        <?php 
        $settings = Settings::getInstance();
        if ($settings->isProductionMode()): 
        ?>
            <div class="warning-box warning-box--info">
                <strong data-i18n="maintenance.ui.production_mode_active">🚀 Production Mode Active:</strong><br>
                <span data-i18n="maintenance.ui.production_mode_line_1">أدوات إدارة وحذف بيانات الاختبار غير متاحة في وضع الإنتاج لضمان سلامة البيانات.</span><br>
                <span data-i18n="maintenance.ui.production_mode_line_2">لإدارة بيانات الاختبار، يرجى تعطيل Production Mode من الإعدادات.</span>
            </div>
        <?php else: ?>
        
        <h2 data-i18n="maintenance.ui.stats_title">📊 إحصائيات قاعدة البيانات</h2>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?= $totalCount ?></div>
                <div class="stat-label" data-i18n="maintenance.ui.stat_total_guarantees">إجمالي الضمانات</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-value stat-value--success"><?= $realCount ?></div>
                <div class="stat-label" data-i18n="maintenance.ui.stat_real_data">بيانات حقيقية</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-value stat-value--warning"><?= $testCount ?></div>
                <div class="stat-label" data-i18n="maintenance.ui.stat_test_data">بيانات اختبار</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-value stat-value--indigo"><?= $stats['unique_batches'] ?></div>
                <div class="stat-label" data-i18n="maintenance.ui.stat_test_batches">دفعات اختبار</div>
            </div>
        </div>
        
        <?php if ($stats['oldest_test_data']): ?>
            <div class="warning-box">
                <strong data-i18n="maintenance.ui.note_label">⚠️ ملاحظة:</strong> 
                <span data-i18n="maintenance.ui.note_oldest_test_data">أقدم بيانات اختبار:</span> <?= date(DATE_ATOM, strtotime($stats['oldest_test_data'])) ?> | 
                <span data-i18n="maintenance.ui.note_newest_test_data">أحدث بيانات اختبار:</span> <?= date(DATE_ATOM, strtotime($stats['newest_test_data'])) ?>
            </div>
        <?php endif; ?>
        
        <div class="danger-zone">
            <h3 data-i18n="maintenance.ui.danger_zone_title">⚠️ منطقة الخطر: حذف بيانات الاختبار</h3>
            
            <div class="warning-box warning-box--danger">
                <strong data-i18n="maintenance.ui.danger_warning_title">🚨 تحذير شديد:</strong>
                <ul>
                    <li><span data-i18n="maintenance.ui.danger_line_1">عمليات الحذف</span> <strong data-i18n="maintenance.ui.danger_line_1_emphasis">لا يمكن التراجع عنها</strong></li>
                    <li data-i18n="maintenance.ui.danger_line_2">سيتم حذف جميع السجلات المرتبطة (القرارات، الأحداث، الدفعات)</li>
                    <li data-i18n="maintenance.ui.danger_line_3">الترقيم التسلسلي (Auto-Increment) لن يتأثر - الأرقام المحذوفة لن تُعاد استخدامها</li>
                    <li data-i18n="maintenance.ui.danger_line_4">تأكد من وجود نسخة احتياطية قبل المتابعة</li>
                </ul>
            </div>
            
            <?php if ($testCount > 0): ?>
                
                <!-- Option 1: Delete All Test Data -->
                <div class="delete-option">
                    <h4 class="delete-option-title" data-i18n="maintenance.ui.delete_all_title">🗑️ حذف جميع بيانات الاختبار</h4>
                    <p>
                        <span data-i18n="maintenance.ui.delete_all_description_prefix">سيتم حذف</span>
                        <strong><?= $testCount ?></strong>
                        <span data-i18n="maintenance.ui.delete_all_description_suffix">ضماناً تجريبياً وجميع السجلات المرتبطة بها.</span>
                    </p>
                    
                    <form method="POST" data-confirm-key="maintenance.confirm.delete_all">
                        <?= wbgl_csrf_input() ?>
                        <input type="hidden" name="action" value="delete_all">
                        <label>
                            <span data-i18n="maintenance.ui.confirm_input_prefix">اكتب</span> <code>DELETE</code> <span data-i18n="maintenance.ui.confirm_input_suffix">للتأكيد:</span>
                            <input type="text" name="confirmation" class="confirmation-input" required>
                        </label>
                        <button type="submit" class="btn-danger" data-i18n="maintenance.ui.delete_all_button">حذف الكل</button>
                    </form>
                </div>
                
                <!-- Option 2: Delete by Batch ID -->
                <div class="delete-option">
                    <h4 class="delete-option-title" data-i18n="maintenance.ui.delete_batch_title">📦 حذف دفعة اختبار محددة</h4>
                    <p data-i18n="maintenance.ui.delete_batch_description">حذف فقط بيانات الاختبار التي تنتمي لدفعة معينة.</p>
                    
                    <form method="POST" data-confirm-key="maintenance.confirm.delete_batch">
                        <?= wbgl_csrf_input() ?>
                        <input type="hidden" name="action" value="delete_batch">
                        <label>
                            <span data-i18n="maintenance.ui.batch_id_label">معرف الدفعة</span> (<code>test_batch_id</code>):
                            <input type="text" name="batch_id" class="confirmation-input" required>
                        </label>
                        <label>
                            <span data-i18n="maintenance.ui.confirm_input_prefix">اكتب</span> <code>DELETE</code> <span data-i18n="maintenance.ui.confirm_input_suffix">للتأكيد:</span>
                            <input type="text" name="confirmation" class="confirmation-input" required>
                        </label>
                        <button type="submit" class="btn-danger" data-i18n="maintenance.ui.delete_batch_button">حذف الدفعة</button>
                    </form>
                </div>
                
                <!-- Option 3: Delete Older Than -->
                <div class="delete-option">
                    <h4 class="delete-option-title" data-i18n="maintenance.ui.delete_older_title">📅 حذف بيانات أقدم من تاريخ معين</h4>
                    <p data-i18n="maintenance.ui.delete_older_description">حذف بيانات الاختبار التي تم إنشاؤها قبل التاريخ المحدد.</p>
                    
                    <form method="POST" data-confirm-key="maintenance.confirm.delete_older">
                        <?= wbgl_csrf_input() ?>
                        <input type="hidden" name="action" value="delete_older">
                        <label>
                            <span data-i18n="maintenance.ui.date_label">التاريخ:</span>
                            <input type="date" name="older_than" class="confirmation-input" required>
                        </label>
                        <label>
                            <span data-i18n="maintenance.ui.confirm_input_prefix">اكتب</span> <code>DELETE</code> <span data-i18n="maintenance.ui.confirm_input_suffix">للتأكيد:</span>
                            <input type="text" name="confirmation" class="confirmation-input" required>
                        </label>
                        <button type="submit" class="btn-danger" data-i18n="maintenance.ui.delete_older_button">حذف القديم</button>
                    </form>
                </div>
                
            <?php else: ?>
                <div class="maintenance-empty-state">
                    ✅ <strong data-i18n="maintenance.ui.empty_clean_title">قاعدة البيانات نظيفة!</strong><br>
                    <span data-i18n="maintenance.ui.empty_clean_body">لا توجد بيانات اختبار حالياً.</span>
                </div>
            <?php endif; ?>
        </div>
        
        <?php endif; // End Production Mode Check ?>

        
        <div class="maintenance-tips">
            <h4 data-i18n="maintenance.ui.tips_title">💡 نصائح:</h4>
            <ul>
                <li data-i18n="maintenance.ui.tip_batch_id">استخدم معرف الدفعة (batch_id) لتنظيم بيانات الاختبار الخاصة بك</li>
                <li data-i18n="maintenance.ui.tip_cleanup_regularly">احذف بيانات الاختبار بانتظام لتجنب تلوث الإحصائيات</li>
                <li data-i18n="maintenance.ui.tip_no_sequence_impact">تذكر: حذف بيانات الاختبار لا يؤثر على الترقيم التسلسلي</li>
                <li data-i18n="maintenance.ui.tip_backup_first">قم بعمل نسخة احتياطية قبل أي عملية حذف كبيرة</li>
            </ul>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('form[data-confirm-key]').forEach(function (form) { window.WBGLI18n && typeof window.WBGLI18n.t === 'function' && window.WBGLI18n.t('maintenance.meta.title', 'maintenance.meta.title');
                form.addEventListener('submit', function (event) {
                    const key = form.getAttribute('data-confirm-key') || '';
                    const translated = window.WBGLI18n && typeof window.WBGLI18n.t === 'function'
                        ? window.WBGLI18n.t(key, key)
                        : key;
                    if (!window.confirm(translated)) {
                        event.preventDefault();
                    }
                });
            });
        });
    </script>
</body>
</html>
