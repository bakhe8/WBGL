<?php

/**
 * Unified Header Component
 * Used across all WBGL pages for consistent navigation
 */

use App\Support\Guard;
use App\Support\AssetVersion;
use App\Services\BatchAccessPolicyService;

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

$canManageUsers = $currentUser && Guard::has('manage_users');
$isDeveloperUser = \App\Support\ViewPolicy::isCurrentUserDeveloper();
$canAccessBatchSurfaces = BatchAccessPolicyService::canAccessBatchSurfaces();
$assetVersion = static fn(string $path): string => rawurlencode(AssetVersion::forPath($path));
?>
<?php include __DIR__ . '/ui-bootstrap.php'; ?>

<style>
    .user-profile-header {
        position: relative;
        display: flex;
        align-items: center;
        gap: 8px;
        margin-inline-start: 16px;
        border-inline-start: 1px solid var(--border-primary);
        padding-inline-start: 16px;
    }

    .user-menu {
        position: relative;
    }

    .user-menu-trigger {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        border: 1px solid transparent;
        background: transparent;
        border-radius: 10px;
        padding: 6px 8px;
        cursor: pointer;
        color: inherit;
        font-family: inherit;
        transition: all var(--transition-fast);
    }

    .user-menu-trigger:hover,
    .user-menu.is-open .user-menu-trigger {
        background: var(--bg-hover);
        border-color: var(--border-primary);
    }

    .user-menu-caret {
        font-size: 10px;
        color: var(--text-muted);
        transition: transform var(--transition-fast);
    }

    .user-menu.is-open .user-menu-caret {
        transform: rotate(180deg);
    }

    .user-menu-popover {
        position: absolute;
        inset-inline-end: 0;
        inset-block-start: calc(100% + 8px);
        width: min(320px, calc(100vw - 24px));
        background: var(--bg-card);
        border: 1px solid var(--border-primary);
        border-radius: 12px;
        box-shadow: var(--shadow-lg);
        z-index: 1100;
        padding: 8px;
    }

    .user-menu-section {
        border-bottom: 1px solid var(--border-primary);
        padding: 6px 0;
    }

    .user-menu-section:last-child {
        border-bottom: none;
    }

    .user-menu-section-title {
        font-size: 11px;
        font-weight: 700;
        color: var(--text-muted);
        padding: 4px 10px 6px;
        margin: 0;
    }

    .user-menu-item {
        width: 100%;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        border: 1px solid transparent;
        background: transparent;
        color: var(--text-primary);
        text-decoration: none;
        border-radius: 8px;
        padding: 8px 10px;
        font-size: 13px;
        font-family: inherit;
        cursor: pointer;
        text-align: start;
    }

    .user-menu-item:hover {
        background: var(--bg-hover);
    }

    .user-menu-item.active {
        background: var(--accent-primary-light);
        border-color: var(--accent-primary);
        color: var(--accent-primary);
        font-weight: 600;
    }

    .user-menu-item-main {
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .user-menu-icon {
        width: 18px;
        text-align: center;
        opacity: 0.9;
    }

    .user-menu-item-danger {
        color: #ef4444;
    }

    .user-menu-item-danger:hover {
        background: rgba(239, 68, 68, 0.08);
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

    .header-brand-group {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .header-actions-group {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .btn-global-home {
        position: relative;
    }

    .mobile-toggle-btn-hidden {
        display: none;
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

    @media (max-width: 768px) {
        .user-profile-header {
            margin-inline-start: 0;
            border-inline-start: none;
            padding-inline-start: 0;
        }

        .user-info-header {
            display: none;
        }

        .user-menu-popover {
            inset-inline-end: -8px;
        }
    }
</style>

<header class="top-bar">
    <div class="header-brand-group">
        <!-- Mobile Toggle (Right in RTL - Opens Sidebar) -->
        <button class="mobile-toggle-btn mobile-toggle-btn-hidden" onclick="toggleSidebar()">
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
                    <a href="<?= $basePath ?>index.php" class="clear-search" title="" data-i18n-title="search.clear_title">✕</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="header-actions-group">
        <nav class="global-actions">
            <div class="global-nav-links"
                data-nav-root
                data-nav-base="<?= htmlspecialchars($basePath) ?>"
                data-nav-current="<?= htmlspecialchars($currentPage) ?>"
                data-nav-production-mode="<?= $isProductionMode ? '1' : '0' ?>">
                <noscript>
                    <a href="<?= $basePath ?>index.php"
                        class="btn-global btn-global-home <?= isActive('index', $currentPage, $currentDir) ? 'active' : '' ?>">
                        <span class="nav-icon">🏠</span>
                        <span class="nav-label" data-i18n="nav.home">الرئيسية</span>
                    </a>
                    <?php if ($canAccessBatchSurfaces): ?>
                        <a href="<?= $basePath ?>views/batches.php"
                            class="btn-global <?= isActive('batches', $currentPage, $currentDir) ? 'active' : '' ?>">
                            <span class="nav-icon">📦</span>
                            <span class="nav-label" data-i18n="nav.batches">الدفعات</span>
                        </a>
                    <?php endif; ?>
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
        </nav>

        <?php if ($currentUser): ?>
            <div class="user-profile-header">
                <?php
                $displayName = trim((string)($currentUser->fullName ?: $currentUser->username));
                if ($displayName === '') {
                    $displayName = '—';
                }
                $avatarLetter = mb_substr($displayName, 0, 1, 'UTF-8');
                $roleDisplay = trim((string)($currentRole->name ?? ''));
                $roleSlug = strtolower(trim((string)($currentRole->slug ?? '')));
                $roleI18nMap = [
                    'developer' => 'common.roles.developer',
                    'data_entry' => 'common.roles.data_entry',
                    'data_auditor' => 'common.roles.data_auditor',
                    'analyst' => 'common.roles.analyst',
                    'supervisor' => 'common.roles.supervisor',
                    'approver' => 'common.roles.approver',
                    'signatory' => 'common.roles.signatory',
                ];
                $roleI18nKey = $roleI18nMap[$roleSlug] ?? null;
                ?>
                <div class="user-menu" data-user-menu>
                    <button type="button"
                        class="user-menu-trigger"
                        data-user-menu-trigger
                        aria-haspopup="menu"
                        aria-expanded="false"
                        title=""
                        data-i18n-title="user.open_menu">
                        <div class="user-info-header">
                            <span class="user-name-header"><?= htmlspecialchars($displayName) ?></span>
                            <?php if ($roleDisplay !== ''): ?>
                                <?php if ($roleI18nKey !== null): ?>
                                    <span class="user-role-header" data-i18n="<?= htmlspecialchars($roleI18nKey, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($roleDisplay) ?></span>
                                <?php else: ?>
                                    <span class="user-role-header"><?= htmlspecialchars($roleDisplay) ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="user-role-header" data-i18n="user.role_default">مستخدم</span>
                            <?php endif; ?>
                        </div>
                        <div class="user-avatar-header"><?= htmlspecialchars($avatarLetter) ?></div>
                        <span class="user-menu-caret">▾</span>
                    </button>

                    <div class="user-menu-popover" data-user-menu-popover role="menu" hidden>
                        <div class="user-menu-section">
                            <p class="user-menu-section-title" data-i18n="user.preferences">تفضيلات الواجهة</p>
                            <button type="button"
                                class="user-menu-item"
                                data-user-menu-close
                                data-wbgl-lang-toggle
                                data-i18n-title="nav.language"
                                data-authorize-resource="ui"
                                data-authorize-action="change-language"
                                data-authorize-mode="disable"
                                data-authorize-denied-key="policy.denied.change_language">
                                <span class="user-menu-item-main">
                                    <span class="user-menu-icon">🌐</span>
                                    <span data-i18n="nav.language">اللغة</span>
                                </span>
                                <span class="lang-pill" id="wbgl-lang-current">AR</span>
                            </button>
                            <?php if ($isDeveloperUser): ?>
                                <button type="button"
                                    class="user-menu-item"
                                    data-user-menu-close
                                    data-wbgl-direction-toggle
                                    data-i18n-title="nav.direction"
                                    data-authorize-resource="ui"
                                    data-authorize-action="change-direction"
                                    data-authorize-mode="disable"
                                    data-authorize-denied-key="policy.denied.change_direction">
                                    <span class="user-menu-item-main">
                                        <span class="user-menu-icon">↔</span>
                                        <span data-i18n="nav.direction">الاتجاه</span>
                                    </span>
                                    <span class="dir-pill" id="wbgl-direction-current">AUTO</span>
                                </button>
                            <?php endif; ?>
                            <button type="button"
                                class="user-menu-item"
                                data-user-menu-close
                                data-wbgl-theme-toggle
                                data-i18n-title="nav.theme"
                                data-authorize-resource="ui"
                                data-authorize-action="change-theme"
                                data-authorize-mode="disable"
                                data-authorize-denied-key="policy.denied.change_theme">
                                <span class="user-menu-item-main">
                                    <span class="user-menu-icon">🎨</span>
                                    <span data-i18n="nav.theme">المظهر</span>
                                </span>
                                <span class="theme-pill" id="wbgl-theme-current">SYS</span>
                            </button>
                        </div>

                        <?php if ($canManageUsers): ?>
                            <div class="user-menu-section">
                                <p class="user-menu-section-title" data-i18n="user.settings_group">إعدادات المستخدم</p>
                                <a href="<?= $basePath ?>views/settings.php"
                                    class="user-menu-item <?= isActive('settings', $currentPage, $currentDir) ? 'active' : '' ?>"
                                    data-user-menu-close>
                                    <span class="user-menu-item-main">
                                        <span class="user-menu-icon">⚙</span>
                                        <span data-i18n="nav.settings">إعدادات</span>
                                    </span>
                                </a>
                                <a href="<?= $basePath ?>views/users.php"
                                    class="user-menu-item <?= isActive('users', $currentPage, $currentDir) ? 'active' : '' ?>"
                                    data-user-menu-close>
                                    <span class="user-menu-item-main">
                                        <span class="user-menu-icon">👥</span>
                                        <span data-i18n="nav.users">إدارة المستخدمين</span>
                                    </span>
                                </a>
                            </div>
                        <?php endif; ?>

                        <div class="user-menu-section">
                            <a href="<?= $basePath ?>api/logout.php"
                                class="user-menu-item user-menu-item-danger"
                                data-user-menu-close
                                data-i18n-title="user.logout_title">
                                <span class="user-menu-item-main">
                                    <span class="user-menu-icon">↩</span>
                                    <span data-i18n="user.logout">خروج</span>
                                </span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Mobile Toggle (Left in RTL - Opens Timeline) -->
        <button class="mobile-toggle-btn mobile-toggle-btn-hidden" onclick="toggleTimeline()">
            ⏱️
        </button>
    </div>
</header>

<script>
    (function() {
        function bindUserMenu(menuRoot) {
            if (!(menuRoot instanceof HTMLElement)) {
                return;
            }
            const trigger = menuRoot.querySelector('[data-user-menu-trigger]');
            const popover = menuRoot.querySelector('[data-user-menu-popover]');
            if (!(trigger instanceof HTMLElement) || !(popover instanceof HTMLElement)) {
                return;
            }

            let open = false;
            const openMenu = () => {
                popover.hidden = false;
                menuRoot.classList.add('is-open');
                trigger.setAttribute('aria-expanded', 'true');
                open = true;
            };
            const closeMenu = () => {
                popover.hidden = true;
                menuRoot.classList.remove('is-open');
                trigger.setAttribute('aria-expanded', 'false');
                open = false;
            };

            trigger.addEventListener('click', function(event) {
                event.preventDefault();
                event.stopPropagation();
                if (open) {
                    closeMenu();
                } else {
                    openMenu();
                }
            });

            popover.addEventListener('click', function(event) {
                const closeTarget = event.target instanceof Element
                    ? event.target.closest('[data-user-menu-close]')
                    : null;
                if (closeTarget) {
                    closeMenu();
                }
            });

            document.addEventListener('click', function(event) {
                if (!(event.target instanceof Node)) {
                    return;
                }
                if (!menuRoot.contains(event.target)) {
                    closeMenu();
                }
            });

            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape') {
                    closeMenu();
                }
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('[data-user-menu]').forEach(bindUserMenu);
        });
    })();
</script>

<script src="<?= $basePath ?>public/js/security.js?v=<?= $assetVersion('public/js/security.js') ?>"></script>
<script src="<?= $basePath ?>public/js/i18n.js?v=<?= $assetVersion('public/js/i18n.js') ?>"></script>
<script src="<?= $basePath ?>public/js/dialog-system.js?v=<?= $assetVersion('public/js/dialog-system.js') ?>"></script>
<script src="<?= $basePath ?>public/js/direction.js?v=<?= $assetVersion('public/js/direction.js') ?>"></script>
<script src="<?= $basePath ?>public/js/theme.js?v=<?= $assetVersion('public/js/theme.js') ?>"></script>
<script src="<?= $basePath ?>public/js/policy.js?v=<?= $assetVersion('public/js/policy.js') ?>"></script>
<script src="<?= $basePath ?>public/js/nav-manifest.js?v=<?= $assetVersion('public/js/nav-manifest.js') ?>"></script>
<script src="<?= $basePath ?>public/js/ui-runtime.js?v=<?= $assetVersion('public/js/ui-runtime.js') ?>"></script>
<script src="<?= $basePath ?>public/js/global-shortcuts.js?v=<?= $assetVersion('public/js/global-shortcuts.js') ?>"></script>
