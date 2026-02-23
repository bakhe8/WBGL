<?php

/**
 * Unified Header Component
 * Used across all WBGL pages for consistent navigation
 */

use App\Support\Guard;

// Detect current page for active state
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$currentDir = basename(dirname($_SERVER['PHP_SELF']));

// Helper function to check if link is active
function isActive($page, $currentPage, $currentDir)
{
    if ($currentDir === 'views' && $page === $currentPage) {
        return true;
    }
    if ($currentDir !== 'views' && $page === 'index' && $currentPage === 'index') {
        return true;
    }
    return false;
}

// Determine base path (root or views/)
$basePath = ($currentDir === 'views') ? '../' : './';

// Check Production Mode for conditional menu items
$headerSettings = \App\Support\Settings::getInstance();
$isProductionMode = $headerSettings->isProductionMode();

// ✅ RBAC: Load User and Role
$currentUser = \App\Support\AuthService::getCurrentUser();
$currentRole = null;
if ($currentUser && $currentUser->roleId) {
    $db = \App\Support\Database::connect();
    $roleRepo = new \App\Repositories\RoleRepository($db);
    $currentRole = $roleRepo->find($currentUser->roleId);
}
?>

<style>
    .user-profile-header {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-right: 16px;
        border-right: 1px solid var(--border-primary);
        padding-right: 16px;
    }

    .user-avatar-header {
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
        flex-shrink: 0;
    }

    .user-info-header {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
    }

    .user-name-header {
        font-size: 13px;
        font-weight: 600;
        color: var(--text-primary);
        white-space: nowrap;
    }

    .user-role-header {
        font-size: 10px;
        color: var(--text-muted);
    }

    .btn-logout-header {
        color: #ef4444;
        text-decoration: none;
        font-size: 11px;
        margin-right: 8px;
        transition: opacity 0.2s;
        white-space: nowrap;
    }

    .btn-logout-header:hover {
        opacity: 0.8;
    }
</style>

<header class="top-bar">
    <div style="display: flex; align-items: center; gap: 12px;">
        <!-- Mobile Toggle (Right in RTL - Opens Sidebar) -->
        <button class="mobile-toggle-btn" onclick="toggleSidebar()" style="display: none;">
            ☰
        </button>
        <div class="brand">
            <div class="brand-icon">&#x1F4CB;</div>
            <span class="brand-text">نظام إدارة الضمانات</span>
        </div>
    </div>

    <!-- ✅ Search Bar -->
    <div class="header-search-container">
        <form action="<?= $basePath ?>index.php" method="GET" class="header-search-form">
            <div class="search-input-wrapper">
                <span class="search-icon">🔍</span>
                <input type="text" name="search"
                    value="<?= isset($_GET['search']) ? htmlspecialchars($_GET['search']) : '' ?>"
                    placeholder="بحث برقم الضمان، المورد، أو البنك..." class="search-input" autocomplete="off">
                <?php if (isset($_GET['search']) && !empty($_GET['search'])): ?>
                    <a href="<?= $basePath ?>index.php" class="clear-search" title="إلغاء البحث">✕</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div style="display: flex; align-items: center; gap: 8px;">
        <nav class="global-actions">
            <a href="<?= $basePath ?>index.php"
                class="btn-global <?= isActive('index', $currentPage, $currentDir) ? 'active' : '' ?>">
                <span class="nav-icon">🏠</span>
                <span class="nav-label">الرئيسية</span>
            </a>
            <a href="<?= $basePath ?>views/batches.php"
                class="btn-global <?= isActive('batches', $currentPage, $currentDir) ? 'active' : '' ?>">
                <span class="nav-icon">📦</span>
                <span class="nav-label">الدفعات</span>
            </a>
            <a href="<?= $basePath ?>views/statistics.php"
                class="btn-global <?= isActive('statistics', $currentPage, $currentDir) ? 'active' : '' ?>">
                <span class="nav-icon">📊</span>
                <span class="nav-label">إحصائيات</span>
            </a>
            <a href="<?= $basePath ?>views/settings.php"
                class="btn-global <?= isActive('settings', $currentPage, $currentDir) ? 'active' : '' ?>">
                <span class="nav-icon">⚙</span>
                <span class="nav-label">إعدادات</span>
            </a>
            <?php if (!$isProductionMode): ?>
                <a href="<?= $basePath ?>views/maintenance.php"
                    class="btn-global <?= isActive('maintenance', $currentPage, $currentDir) ? 'active' : '' ?>">
                    <span class="nav-icon">🛠️</span>
                    <span class="nav-label">صيانة</span>
                </a>
            <?php endif; ?>
            <?php if (Guard::has('manage_users')): ?>
                <a href="<?= $basePath ?>views/users.php"
                    class="btn-global <?= isActive('users', $currentPage, $currentDir) ? 'active' : '' ?>">
                    <span class="nav-icon">👥</span>
                    <span class="nav-label">إدارة المستخدمين</span>
                </a>
            <?php endif; ?>
        </nav>

        <?php if ($currentUser): ?>
            <div class="user-profile-header">
                <div class="user-info-header">
                    <span class="user-name-header"><?= htmlspecialchars($currentUser->fullName) ?></span>
                    <span class="user-role-header"><?= htmlspecialchars($currentRole->name ?? 'مستخدم') ?></span>
                </div>
                <div class="user-avatar-header"><?= mb_substr($currentUser->fullName, 0, 1, 'UTF-8') ?></div>
                <a href="<?= $basePath ?>api/logout.php" class="btn-logout-header" title="تسجيل الخروج">خروج</a>
            </div>
        <?php endif; ?>

        <!-- Mobile Toggle (Left in RTL - Opens Timeline) -->
        <button class="mobile-toggle-btn" onclick="toggleTimeline()" style="display: none;">
            ⏱️
        </button>
    </div>
</header>
