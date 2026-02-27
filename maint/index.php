<?php

/**
 * System Maintenance Dashboard (V3)
 * Provides direct database access and utility links
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;
use App\Repositories\GuaranteeRepository;
use App\Support\AuthService;

// Basic protection: Only show if not in production or if specifically allowed
if (!AuthService::isLoggedIn()) {
    header('Location: ../views/login.php');
    exit;
}

$db = Database::connect();
$repo = new GuaranteeRepository($db);

$searchId = $_GET['search_id'] ?? '';
$foundGuarantee = null;
$foundStatus = 'pending';

if ($searchId) {
    $foundGuarantee = $repo->find((int)$searchId);
    if ($foundGuarantee) {
        // Fetch status from decisions
        $stmt = $db->prepare("SELECT status FROM guarantee_decisions WHERE guarantee_id = ?");
        $stmt->execute([$foundGuarantee->id]);
        $foundStatus = $stmt->fetchColumn() ?: 'pending';
    }
}

// List all maint scripts
$scripts = array_diff(scandir(__DIR__), ['.', '..', 'index.php']);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة الصيانة - WBGL</title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --bg: #f8fafc;
            --card: #ffffff;
            --text: #1e293b;
            --muted: #64748b;
            --border: #e2e8f0;
        }

        body {
            background: var(--bg);
            color: var(--text);
            font-family: 'Tajawal', sans-serif;
            padding: 20px;
            line-height: 1.6;
        }

        .maint-container {
            max-width: 1000px;
            margin: 0 auto;
        }

        .maint-card {
            background: var(--card);
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);
            padding: 24px;
            margin-bottom: 24px;
            border: 1px solid var(--border);
        }

        .maint-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 16px;
        }

        .script-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px;
            background: #f8fafc;
            border: 1px solid var(--border);
            border-radius: 8px;
            text-decoration: none;
            color: var(--text);
            transition: all 0.2s;
        }

        .script-link:hover {
            background: #eff6ff;
            border-color: var(--primary);
            transform: translateY(-2px);
        }

        .script-icon {
            font-size: 24px;
        }

        .search-box {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
        }

        .input-maint {
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 6px;
            flex: 1;
            font-family: inherit;
        }

        .btn-maint {
            background: var(--primary);
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: bold;
            font-family: inherit;
        }

        .btn-maint:hover {
            opacity: 0.9;
        }

        .guarantee-edit-form {
            border-top: 2px solid var(--border);
            padding-top: 24px;
            margin-top: 24px;
        }

        .field-group {
            margin-bottom: 16px;
        }

        .field-label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            color: var(--muted);
            margin-bottom: 6px;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
        }

        .status-ready {
            background: #dcfce7;
            color: #166534;
        }

        .status-pending {
            background: #fef9c3;
            color: #854d0e;
        }

        label code {
            background: #fee2e2;
            color: #991b1b;
            padding: 2px 4px;
            border-radius: 3px;
        }
    </style>
</head>

<body>
    <div class="maint-container">
        <header style="margin-bottom: 40px; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h1 style="margin:0; font-size: 28px;">🛠️ لوحة التحكم والصيانة</h1>
                <p style="margin: 5px 0 0 0; color: var(--muted); font-size: 16px;">الوصول المباشر لقاعدة البيانات وأدوات التشخيص</p>
            </div>
            <a href="../index.php" style="text-decoration: none; font-weight: bold; background: white; padding: 8px 16px; border-radius: 8px; border: 1px solid var(--border); color: var(--text);">← النظام الأساسي</a>
        </header>

        <div class="maint-card">
            <h2 style="margin-top: 0; font-size: 20px; margin-bottom: 20px;">🔍 محرر الضمان المباشر (Direct DB Editor)</h2>
            <form action="index.php" method="GET" class="search-box">
                <input type="number" name="search_id" value="<?= htmlspecialchars($searchId) ?>" placeholder="أدخل ID الضمان (مثلاً: 123)..." class="input-maint" required>
                <button type="submit" class="btn-maint">عرض البيانات</button>
            </form>

            <?php if ($foundGuarantee): ?>
                <div class="guarantee-edit-form" id="editForm">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; background: #f8fafc; padding: 15px; border-radius: 8px;">
                        <div>
                            <div style="font-size: 12px; color: var(--muted);">رقم الضمان</div>
                            <h3 style="margin:0; font-size: 22px;"><?= htmlspecialchars($foundGuarantee->guaranteeNumber) ?></h3>
                        </div>
                        <div style="text-align: left;">
                            <span class="status-badge status-<?= $foundStatus ?>"><?= strtoupper($foundStatus) ?></span>
                            <div style="font-size: 11px; margin-top: 4px; color: var(--muted);">INTERNAL ID: <?= $foundGuarantee->id ?></div>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 24px;">
                        <div class="field-group">
                            <label class="field-label">رقم الضمان (BG Number)</label>
                            <input type="text" id="edit_number" value="<?= htmlspecialchars($foundGuarantee->guaranteeNumber) ?>" class="input-maint" style="width: 100%;">
                        </div>
                        <div class="field-group">
                            <label class="field-label">اسم المورد</label>
                            <input type="text" id="edit_supplier" value="<?= htmlspecialchars($foundGuarantee->rawData['supplier'] ?? '') ?>" class="input-maint" style="width: 100%;">
                        </div>
                        <div class="field-group">
                            <label class="field-label">اسم البنك</label>
                            <input type="text" id="edit_bank" value="<?= htmlspecialchars($foundGuarantee->rawData['bank'] ?? '') ?>" class="input-maint" style="width: 100%;">
                        </div>
                        <div class="field-group">
                            <label class="field-label">المبلغ (Numeric)</label>
                            <input type="text" id="edit_amount" value="<?= htmlspecialchars($foundGuarantee->rawData['amount'] ?? '') ?>" class="input-maint" style="width: 100%;">
                        </div>
                        <div class="field-group">
                            <label class="field-label">رقم العقد</label>
                            <input type="text" id="edit_contract" value="<?= htmlspecialchars($foundGuarantee->rawData['contract_number'] ?? '') ?>" class="input-maint" style="width: 100%;">
                        </div>
                        <div class="field-group">
                            <label class="field-label">تاريخ الانتهاء</label>
                            <input type="date" id="edit_expiry" value="<?= htmlspecialchars($foundGuarantee->rawData['expiry_date'] ?? '') ?>" class="input-maint" style="width: 100%;">
                        </div>
                    </div>

                    <div style="margin-top: 30px; display: flex; gap: 15px; border-top: 1px solid var(--border); padding-top: 20px;">
                        <button onclick="saveChanges(<?= $foundGuarantee->id ?>)" class="btn-maint" style="background: #059669; flex: 2; font-size: 16px;">💾 حفظ وتحديث قاعدة البيانات</button>
                        <button onclick="window.open('../index.php?id=<?= $foundGuarantee->id ?>', '_blank')" class="btn-maint" style="background: var(--muted); flex: 1;">👁️ عرض في الواجهة</button>
                    </div>
                </div>
            <?php elseif ($searchId): ?>
                <div style="padding: 24px; background: #fee2e2; color: #991b1b; border-radius: 8px; border: 1px solid #fecaca; text-align: center; font-weight: bold;">
                    ⚠️ عذراً، لا يوجد سجل في قاعدة البيانات يحمل الرقم المعرف: <?= htmlspecialchars($searchId) ?>
                </div>
            <?php endif; ?>
        </div>

        <h2 style="font-size: 20px; margin-bottom: 20px; color: var(--text);">⚡ اختصارات وأدوات سريعة</h2>
        <div class="maint-grid">
            <a href="../views/maintenance.php" class="script-link" style="border-right: 4px solid #f59e0b; background: #fffcf0;">
                <span class="script-icon">🧹</span>
                <div>
                    <div style="font-weight: bold;">تنظيف بيانات الاختبار</div>
                    <div style="font-size: 12px; color: var(--muted);">حذف شامل لبيانات الـ Test وتصفير العدادات</div>
                </div>
            </a>
            <?php foreach ($scripts as $script): ?>
                <?php if ($script !== 'index.php'): ?>
                    <a href="<?= htmlspecialchars($script) ?>" class="script-link">
                        <span class="script-icon">⚙️</span>
                        <div>
                            <div style="font-weight: bold; font-family: monospace;"><?= htmlspecialchars($script) ?></div>
                            <div style="font-size: 11px; color: var(--muted);">ملف سكربت برمي</div>
                        </div>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>

    <script>
        async function saveChanges(id) {
            const btn = event.target;
            const originalText = btn.innerHTML;
            btn.innerHTML = '... جاري الحفظ والتحديث';
            btn.disabled = true;

            const data = {
                guarantee_id: id,
                guarantee_number: document.getElementById('edit_number').value,
                supplier: document.getElementById('edit_supplier').value,
                bank: document.getElementById('edit_bank').value,
                amount: document.getElementById('edit_amount').value,
                contract_number: document.getElementById('edit_contract').value,
                expiry_date: document.getElementById('edit_expiry').value,
                issue_date: '',
                type: 'Initial'
            };

            try {
                const response = await fetch('../api/update-guarantee.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(data)
                });

                const result = await response.json();
                if (result.success) {
                    alert('✅ نجاح: ' + result.message);
                    location.reload();
                } else {
                    alert('❌ خطأ في التحديث: ' + (result.error || 'فشل التعديل'));
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                }
            } catch (error) {
                alert('❌ خطأ تقني: ' + error.message);
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        }
    </script>
</body>

</html>
