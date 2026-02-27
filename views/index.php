<?php
require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\AuthService;
use App\Repositories\RoleRepository;

// ✅ STRICT AUTH: Redirect to login if not authenticated
if (!AuthService::isLoggedIn()) {
    header('Location: /views/login.php');
    exit;
}

$currentUser = AuthService::getCurrentUser();
$db = \App\Support\Database::connect();
$roleRepo = new RoleRepository($db);
$currentRole = $roleRepo->find($currentUser->roleId);

header('Content-Type: text/html; charset=utf-8');

// --- Mock Data & State Handling ---
$recordId = $_GET['id'] ?? 14180;

// In a real app, these would come from Repositories
$mockRecord = [
    'id' => $recordId,
    'session_id' => 517,
    'guarantee_number' => 'BG-2024-' . substr($recordId, -5),
    'supplier_name' => 'شركة الاختبار التجريبية',
    'bank_name' => 'البنك الأهلي السعودي',
    'amount' => 500000,
    'expiry_date' => '2025-06-30',
    'issue_date' => '2024-01-15',
    'contract_number' => 'CNT-2024-001',
    'type' => 'ابتدائي',
    'status' => 'pending'
];

$mockTimeline = [
    ['id' => 7, 'type' => 'release', 'date' => '2025-01-15 11:45:00', 'description' => 'إصدار إفراج', 'details' => 'تم إصدار خطاب إفراج الضمان'],
    ['id' => 1, 'type' => 'import', 'date' => '2024-12-01 10:30:15', 'description' => 'استيراد من ملف Excel', 'details' => 'ملف: guarantees_dec_2024.xlsx']
];

$mockCandidates = [
    'suppliers' => [
        ['id' => 1, 'name' => 'شركة الاختبار التجريبية', 'confidence' => 95, 'source' => 'learned', 'usage_count' => 15],
        ['id' => 2, 'name' => 'شركة الاختبار', 'confidence' => 85, 'source' => 'excel', 'usage_count' => 3]
    ],
    'banks' => [
        ['id' => 1, 'name' => 'البنك الأهلي السعودي', 'confidence' => 95, 'source' => 'learned']
    ]
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WBGL System v3.0 (Vanilla)</title>

    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- App Logic -->

    <style>
        /* CSS Variables (Keep original design system) */
        :root {
            --bg-body: #f1f5f9;
            --bg-card: #ffffff;
            --bg-secondary: #f8fafc;
            --border-primary: #e2e8f0;
            --accent-primary: #3b82f6;
            --accent-success: #16a34a;
            --text-primary: #1e293b;
            --text-muted: #64748b;
            --font-family: 'Tajawal', sans-serif;
            --radius-md: 8px;
        }

        /* Reset & Base */
        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: var(--font-family);
            background: var(--bg-body);
            color: var(--text-primary);
            display: flex;
            flex-direction: column;
            height: 100vh;
            overflow: hidden;
        }

        /* Layout */
        .app-container {
            display: flex;
            flex: 1;
            overflow: hidden;
        }

        .top-bar {
            height: 56px;
            background: var(--bg-card);
            border-bottom: 1px solid var(--border-primary);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 24px;
        }

        .center-section {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .content-wrapper {
            display: flex;
            flex: 1;
            overflow: hidden;
        }

        /* Sidebar Components */
        .sidebar {
            width: 290px;
            background: var(--bg-card);
            border-right: 1px solid var(--border-primary);
            display: flex;
            flex-direction: column;
        }

        .timeline-panel {
            width: 360px;
            background: var(--bg-card);
            border-right: 1px solid var(--border-primary);
            overflow-y: auto;
            padding: 16px;
        }

        .main-content {
            flex: 1;
            padding: 24px;
            overflow-y: auto;
            background: var(--bg-body);
        }

        /* Components */
        .user-profile {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .user-avatar {
            width: 32px;
            height: 32px;
            background: var(--accent-primary);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 14px;
        }

        .user-info {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
        }

        .user-name {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .user-role {
            font-size: 10px;
            color: var(--text-muted);
        }

        .btn-logout {
            color: #ef4444;
            text-decoration: none;
            font-size: 12px;
            margin-right: 12px;
            transition: opacity 0.2s;
        }

        .btn-logout:hover {
            opacity: 0.8;
        }
    </style>
</head>

<body dir="rtl">
    <div class="top-bar">
        <div class="brand">
            <span style="background: var(--accent-primary); color: white; padding: 4px 10px; border-radius: 6px; margin-left:12px;">B</span>
            <span>WBGL System <small style="font-size: 12px; color: var(--text-muted); font-weight: 400;">v3.0</small></span>
        </div>

        <div class="global-actions">
            <div class="user-profile">
                <div class="user-info">
                    <span class="user-name"><?php echo htmlspecialchars($currentUser->fullName); ?></span>
                    <span class="user-role"><?php echo htmlspecialchars($currentRole->name ?? 'مستخدم'); ?></span>
                </div>
                <div class="user-avatar"><?php echo mb_substr($currentUser->fullName, 0, 1, 'UTF-8'); ?></div>
                <a href="/api/logout.php" class="btn-logout" title="تسجيل الخروج">تسجيل خروج</a>
            </div>
        </div>
    </div>

    <div class="app-container">
        <!-- Sidebar (Left) -->
        <aside class="sidebar">
            <div style="padding: 16px; border-bottom: 1px solid var(--border-primary);">
                <div style="font-size: 12px; color: var(--text-muted); margin-bottom: 8px;">إحصائيات الاستيراد</div>
                <div style="display: flex; justify-content: space-between;">
                    <span style="font-weight: bold;">517</span>
                    <span class="badge badge-pending">قيد المعالجة</span>
                </div>
            </div>
            <div style="flex: 1; overflow-y: auto; padding: 16px; background: var(--bg-secondary);">
                <!-- Sidebar contents -->
            </div>
        </aside>

        <!-- Center Section -->
        <div class="center-section">
            <div class="content-wrapper">
                <main class="main-content">
                    <!-- Main record view -->
                </main>
            </div>
        </div>
    </div>
</body>

</html>
