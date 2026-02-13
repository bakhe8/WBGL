<?php
require_once __DIR__ . '/../vendor/autoload.php';

use App\Repositories\GuaranteeRepository;
use App\Support\Database;
use App\Support\Settings;

$db = Database::connect();
$repo = new GuaranteeRepository($db);

// Get statistics
$stats = $repo->getTestDataStats();
$realCount = $repo->count();
$testCount = $repo->count(['test_data_only' => true]);
$totalCount = $repo->count(['include_test_data' => true]);

// Handle deletion requests
$deleteResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $confirmation = $_POST['confirmation'] ?? '';
    
    if ($confirmation !== 'DELETE') {
        $deleteResult = ['success' => false, 'message' => 'كلمة التأكيد غير صحيحة'];
    } else {
        try {
            $action = $_POST['action'];
            
            switch ($action) {
                case 'delete_test_data':
                    $deleted = $repo->deleteTestData();
                    $deleteResult = ['success' => true, 'message' => "تم حذف {$deleted} ضمان اختباري بنجاح"];
                    break;
                    
                default:
                    $deleteResult = ['success' => false, 'message' => 'إجراء غير معروف'];
            }
            
            // Refresh stats after deletion
            if ($deleteResult['success']) {
                $stats = $repo->getTestDataStats();
                $realCount = $repo->count();
                $testCount = $repo->count(['test_data_only' => true]);
                $totalCount = $repo->count(['include_test_data' => true]);
            }
            
        } catch (Exception $e) {
            $deleteResult = ['success' => false, 'message' => 'خطأ: ' . $e->getMessage()];
        }
    }
}

$settings = Settings::getInstance();
$isProd = $settings->isProductionMode();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>أدوات الصيانة - BGL System v3.0</title>
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
    </style>
</head>
<body>
    
    <!-- Unified Header -->
    <?php include __DIR__ . '/../partials/unified-header.php'; ?>
    
    <div class="container" style="max-width: 1200px; margin: 2rem auto; padding: 0 1rem;">
        <div class="maintenance-header">
            <h1 style="margin: 0;">🛠️ أدوات الصيانة والتنظيف</h1>
            <p style="margin: 0.5rem 0 0 0; opacity: 0.9;">إدارة بيانات الاختبار وتنظيف قاعدة البيانات</p>
        </div>
        
        <?php if ($deleteResult): ?>
            <div class="<?= $deleteResult['success'] ? 'alert-success' : 'alert-error' ?>" style="padding: 1rem; border-radius: 6px; margin-bottom: 1rem; background: <?= $deleteResult['success'] ? '#d1fae5' : '#fee2e2' ?>; border: 1px solid <?= $deleteResult['success'] ? '#10b981' : '#ef4444' ?>;">
                <?= htmlspecialchars($deleteResult['message']) ?>
            </div>
        <?php endif; ?>
        
        <?php 
        $settings = Settings::getInstance();
        if ($settings->isProductionMode()): 
        ?>
            <div class="warning-box" style="background: #eff6ff; border-color: #3b82f6;">
                <strong>🚀 Production Mode Active:</strong><br>
                أدوات إدارة وحذف بيانات الاختبار غير متاحة في وضع الإنتاج لضمان سلامة البيانات.<br>
                لإدارة بيانات الاختبار، يرجى تعطيل Production Mode من الإعدادات.
            </div>
        <?php else: ?>
        
        <h2>📊 إحصائيات قاعدة البيانات</h2>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?= $totalCount ?></div>
                <div class="stat-label">إجمالي الضمانات</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-value" style="color: #10b981;"><?= $realCount ?></div>
                <div class="stat-label">بيانات حقيقية</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-value" style="color: #f59e0b;"><?= $testCount ?></div>
                <div class="stat-label">بيانات اختبار</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-value" style="color: #6366f1;"><?= $stats['unique_batches'] ?></div>
                <div class="stat-label">دفعات اختبار</div>
            </div>
        </div>
        
        <?php if ($stats['oldest_test_data']): ?>
            <div class="warning-box">
                <strong>⚠️ ملاحظة:</strong> 
                أقدم بيانات اختبار: <?= date('Y-m-d H:i', strtotime($stats['oldest_test_data'])) ?> | 
                أحدث بيانات اختبار: <?= date('Y-m-d H:i', strtotime($stats['newest_test_data'])) ?>
            </div>
        <?php endif; ?>
        
        <div class="danger-zone">
            <h3>⚠️ منطقة الخطر: حذف بيانات الاختبار</h3>
            
            <div class="warning-box" style="background: #fff7ed; border-color: #ea580c;">
                <strong>🚨 تحذير شديد:</strong><br>
                - عمليات الحذف <strong>لا يمكن التراجع عنها</strong><br>
                - سيتم حذف جميع السجلات المرتبطة (القرارات، الأحداث، الدفعات)<br>
                - الترقيم التسلسلي (Auto-Increment) لن يتأثر - الأرقام المحذوفة لن تُعاد استخدامها<br>
                - تأكد من وجود نسخة احتياطية قبل المتابعة
            </div>
            
            <?php if ($testCount > 0): ?>
                
                <!-- Option 1: Delete All Test Data -->
                <div class="delete-option">
                    <h4 style="margin-top: 0;">🗑️ حذف جميع بيانات الاختبار</h4>
                    <p>سيتم حذف <strong><?= $testCount ?></strong> ضماناً تجريبياً وجميع السجلات المرتبطة بها.</p>
                    
                    <form method="POST" onsubmit="return confirm('هل أنت متأكد تماماً؟ هذا الإجراء لا يمكن التراجع عنه!');">
                        <input type="hidden" name="action" value="delete_all">
                        <label>
                            اكتب <code>DELETE</code> للتأكيد:
                            <input type="text" name="confirmation" class="confirmation-input" required>
                        </label>
                        <button type="submit" class="btn-danger">حذف الكل</button>
                    </form>
                </div>
                
                <!-- Option 2: Delete by Batch ID -->
                <div class="delete-option">
                    <h4 style="margin-top: 0;">📦 حذف دفعة اختبار محددة</h4>
                    <p>حذف فقط بيانات الاختبار التي تنتمي لدفعة معينة.</p>
                    
                    <form method="POST" onsubmit="return confirm('حذف هذه الدفعة؟');">
                        <input type="hidden" name="action" value="delete_batch">
                        <label>
                            معرف الدفعة (test_batch_id):
                            <input type="text" name="batch_id" class="confirmation-input" required>
                        </label>
                        <label>
                            اكتب <code>DELETE</code> للتأكيد:
                            <input type="text" name="confirmation" class="confirmation-input" required>
                        </label>
                        <button type="submit" class="btn-danger">حذف الدفعة</button>
                    </form>
                </div>
                
                <!-- Option 3: Delete Older Than -->
                <div class="delete-option">
                    <h4 style="margin-top: 0;">📅 حذف بيانات أقدم من تاريخ معين</h4>
                    <p>حذف بيانات الاختبار التي تم إنشاؤها قبل التاريخ المحدد.</p>
                    
                    <form method="POST" onsubmit="return confirm('حذف البيانات الأقدم من هذا التاريخ؟');">
                        <input type="hidden" name="action" value="delete_older">
                        <label>
                            التاريخ:
                            <input type="date" name="older_than" class="confirmation-input" required>
                        </label>
                        <label>
                            اكتب <code>DELETE</code> للتأكيد:
                            <input type="text" name="confirmation" class="confirmation-input" required>
                        </label>
                        <button type="submit" class="btn-danger">حذف القديم</button>
                    </form>
                </div>
                
            <?php else: ?>
                <div style="text-align: center; padding: 2rem; color: #10b981;">
                    ✅ <strong>قاعدة البيانات نظيفة!</strong><br>
                    لا توجد بيانات اختبار حالياً.
                </div>
            <?php endif; ?>
        </div>
        
        <?php endif; // End Production Mode Check ?>

        
        <div style="margin-top: 2rem; padding: 1rem; background: #f9fafb; border-radius: 6px;">
            <h4>💡 نصائح:</h4>
            <ul>
                <li>استخدم معرف الدفعة (batch_id) لتنظيم بيانات الاختبار الخاصة بك</li>
                <li>احذف بيانات الاختبار بانتظام لتجنب تلوث الإحصائيات</li>
                <li>تذكر: حذف بيانات الاختبار لا يؤثر على الترقيم التسلسلي</li>
                <li>قم بعمل نسخة احتياطية قبل أي عملية حذف كبيرة</li>
            </ul>
        </div>
    </div>
</body>
</html>
