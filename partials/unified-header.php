<?php
/**
 * Unified Header Component
 * Used across all BGL3 pages for consistent navigation
 */

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
?>

<header class="top-bar">
    <div class="brand">
        <div class="brand-icon">&#x1F4CB;</div>
        <span>نظام إدارة الضمانات</span>
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

    <nav class="global-actions">
        <a href="<?= $basePath ?>index.php"
            class="btn-global <?= isActive('index', $currentPage, $currentDir) ? 'active' : '' ?>">
            🏠 الرئيسية
        </a>
        <a href="<?= $basePath ?>views/batches.php"
            class="btn-global <?= isActive('batches', $currentPage, $currentDir) ? 'active' : '' ?>">
            📦 الدفعات
        </a>
        <a href="<?= $basePath ?>views/statistics.php"
            class="btn-global <?= isActive('statistics', $currentPage, $currentDir) ? 'active' : '' ?>">
            📊 إحصائيات
        </a>
        <a href="<?= $basePath ?>views/settings.php"
            class="btn-global <?= isActive('settings', $currentPage, $currentDir) ? 'active' : '' ?>">
            ⚙ إعدادات
        </a>
        <?php if (!$isProductionMode): ?>
        <a href="<?= $basePath ?>views/maintenance.php"
            class="btn-global <?= isActive('maintenance', $currentPage, $currentDir) ? 'active' : '' ?>">
            🛠️ صيانة
        </a>
        <?php endif; ?>
    </nav>
</header>