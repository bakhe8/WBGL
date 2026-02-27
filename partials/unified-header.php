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
<?php include __DIR__ . '/ui-bootstrap.php'; ?>

<style>
    .user-profile-header {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-inline-start: 16px;
        border-inline-start: 1px solid var(--border-primary);
        padding-inline-start: 16px;
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
        text-align: end;
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
        margin-inline-start: 8px;
        transition: opacity 0.2s;
        white-space: nowrap;
    }

    .btn-logout-header:hover {
        opacity: 0.8;
    }

    .lang-pill {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 28px;
        height: 18px;
        border-radius: 12px;
        background: rgba(37, 99, 235, 0.14);
        color: var(--accent-primary);
        font-size: 10px;
        font-weight: 700;
        padding: 0 6px;
    }

    .global-actions .btn-global[type="button"] {
        border: none;
        background: transparent;
        font-family: inherit;
        cursor: pointer;
    }

    .header-badge {
        position: absolute;
        inset-block-start: -4px;
        inset-inline-end: -4px;
        background: #ef4444;
        color: white;
        font-size: 10px;
        font-weight: 800;
        width: 18px;
        height: 18px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        border: 2px solid white;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        z-index: 10;
    }

    .global-actions {
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .global-nav-links {
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .theme-pill,
    .dir-pill {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 32px;
        height: 18px;
        border-radius: 12px;
        background: rgba(148, 163, 184, 0.2);
        color: var(--text-secondary);
        font-size: 10px;
        font-weight: 700;
        padding: 0 6px;
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
            <span class="brand-text" data-i18n="brand.system">نظام إدارة الضمانات</span>
        </div>
    </div>

    <!-- ✅ Search Bar -->
    <div class="header-search-container">
        <form action="<?= $basePath ?>index.php" method="GET" class="header-search-form">
            <div class="search-input-wrapper">
                <span class="search-icon">🔍</span>
                <input type="text" name="search"
                    value="<?= isset($_GET['search']) ? htmlspecialchars($_GET['search']) : '' ?>"
                    placeholder="بحث برقم الضمان، المورد، أو البنك..." data-i18n-placeholder="search.placeholder" class="search-input" autocomplete="off">
                <?php if (isset($_GET['search']) && !empty($_GET['search'])): ?>
                    <a href="<?= $basePath ?>index.php" class="clear-search" title="إلغاء البحث" data-i18n-title="search.clear_title">✕</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div style="display: flex; align-items: center; gap: 8px;">
        <nav class="global-actions">
            <?php
            // ✅ PHASE 11: Task Guidance - Global Badge (Enforced)
            $taskBadgeCount = 0;
            if ($currentUser) {
                // Fetch real-time count from centralized service
                $db = \App\Support\Database::connect();
                $count = \App\Services\StatsService::getPersonalTaskCount($db);
                if ($count > 0) {
                    $taskBadgeCount = $count;
                }
            }
            ?>
            <div class="global-nav-links"
                data-nav-root
                data-nav-base="<?= htmlspecialchars($basePath) ?>"
                data-nav-current="<?= htmlspecialchars($currentPage) ?>"
                data-nav-home-badge="<?= (int)$taskBadgeCount ?>"
                data-nav-production-mode="<?= $isProductionMode ? '1' : '0' ?>">
                <noscript>
                    <a href="<?= $basePath ?>index.php"
                        class="btn-global <?= isActive('index', $currentPage, $currentDir) ? 'active' : '' ?>"
                        style="position: relative;">
                        <span class="nav-icon">🏠</span>
                        <span class="nav-label" data-i18n="nav.home">الرئيسية</span>
                        <?php if ($taskBadgeCount > 0): ?>
                            <span class="header-badge"><?= (int)$taskBadgeCount ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="<?= $basePath ?>views/batches.php"
                        class="btn-global <?= isActive('batches', $currentPage, $currentDir) ? 'active' : '' ?>">
                        <span class="nav-icon">📦</span>
                        <span class="nav-label" data-i18n="nav.batches">الدفعات</span>
                    </a>
                    <a href="<?= $basePath ?>views/statistics.php"
                        class="btn-global <?= isActive('statistics', $currentPage, $currentDir) ? 'active' : '' ?>">
                        <span class="nav-icon">📊</span>
                        <span class="nav-label" data-i18n="nav.statistics">إحصائيات</span>
                    </a>
                    <?php if (Guard::has('manage_users')): ?>
                        <a href="<?= $basePath ?>views/settings.php"
                            class="btn-global <?= isActive('settings', $currentPage, $currentDir) ? 'active' : '' ?>">
                            <span class="nav-icon">⚙</span>
                            <span class="nav-label" data-i18n="nav.settings">إعدادات</span>
                        </a>
                    <?php endif; ?>
                </noscript>
            </div>
            <button type="button" class="btn-global" data-wbgl-lang-toggle data-i18n-title="nav.language">
                <span class="nav-icon">🌐</span>
                <span class="nav-label" data-i18n="nav.language">اللغة</span>
                <span class="lang-pill" id="wbgl-lang-current">AR</span>
            </button>
            <button type="button" class="btn-global" data-wbgl-direction-toggle data-i18n-title="nav.direction">
                <span class="nav-icon">↔</span>
                <span class="nav-label" data-i18n="nav.direction">الاتجاه</span>
                <span class="dir-pill" id="wbgl-direction-current">AUTO</span>
            </button>
            <button type="button" class="btn-global" data-wbgl-theme-toggle data-i18n-title="nav.theme">
                <span class="nav-icon">🎨</span>
                <span class="nav-label" data-i18n="nav.theme">المظهر</span>
                <span class="theme-pill" id="wbgl-theme-current">SYS</span>
            </button>
        </nav>

        <?php if ($currentUser): ?>
            <div class="user-profile-header">
                <div class="user-info-header">
                    <span class="user-name-header"><?= htmlspecialchars($currentUser->fullName) ?></span>
                    <span class="user-role-header"><?= htmlspecialchars($currentRole->name ?? 'مستخدم') ?></span>
                </div>
                <div class="user-avatar-header"><?= mb_substr($currentUser->fullName, 0, 1, 'UTF-8') ?></div>
                <a href="<?= $basePath ?>api/logout.php" class="btn-logout-header" title="تسجيل الخروج" data-i18n-title="user.logout_title" data-i18n="user.logout">خروج</a>
            </div>
        <?php endif; ?>

        <!-- Mobile Toggle (Left in RTL - Opens Timeline) -->
        <button class="mobile-toggle-btn" onclick="toggleTimeline()" style="display: none;">
            ⏱️
        </button>
    </div>
</header>

<script src="<?= $basePath ?>public/js/security.js?v=<?= time() ?>"></script>
<script src="<?= $basePath ?>public/js/i18n.js?v=<?= time() ?>"></script>
<script src="<?= $basePath ?>public/js/direction.js?v=<?= time() ?>"></script>
<script src="<?= $basePath ?>public/js/theme.js?v=<?= time() ?>"></script>
<script src="<?= $basePath ?>public/js/policy.js?v=<?= time() ?>"></script>
<script src="<?= $basePath ?>public/js/nav-manifest.js?v=<?= time() ?>"></script>
<script src="<?= $basePath ?>public/js/ui-runtime.js?v=<?= time() ?>"></script>
<script src="<?= $basePath ?>public/js/global-shortcuts.js?v=<?= time() ?>"></script>
