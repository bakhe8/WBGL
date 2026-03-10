(function () {
    'use strict';

    function canAccessBatchSurfaces() {
        return window.WBGL_BOOTSTRAP?.policy?.batch?.can_access_surfaces === true;
    }

    const NAV_MANIFEST = [
        {
            id: 'home',
            page: 'index',
            href: 'index.php',
            icon: '🏠',
            labelKey: 'nav.home',
        },
        {
            id: 'batches',
            page: 'batches',
            href: 'views/batches.php',
            icon: '📦',
            labelKey: 'nav.batches',
            requiresBatchAccess: true,
        },
        {
            id: 'statistics',
            page: 'statistics',
            href: 'views/statistics.php',
            icon: '📊',
            labelKey: 'nav.statistics',
        },
        {
            id: 'maintenance',
            page: 'maintenance',
            href: 'views/maintenance.php',
            icon: '🛠️',
            labelKey: 'nav.maintenance',
            capability: { resource: 'navigation', action: 'view-maintenance' },
            hideInProduction: true,
        },
        {
            id: 'state-inspector',
            page: 'state-inspector',
            href: 'views/state-inspector.php',
            icon: '🧭',
            labelKey: 'nav.state_inspector',
            labelFallback: 'State Inspector',
            capability: { resource: 'navigation', action: 'view-state-inspector' },
        },
        {
            id: 'role-simulator',
            page: 'role-simulator',
            href: 'views/role-simulator.php',
            icon: '🧪',
            labelKey: 'nav.role_simulator',
            labelFallback: 'Role Simulator',
            capability: { resource: 'navigation', action: 'view-role-simulator' },
        },
    ];

    function translate(key, fallback) {
        if (window.WBGLI18n && typeof window.WBGLI18n.t === 'function') {
            return window.WBGLI18n.t(key, fallback || key);
        }
        return fallback || key;
    }

    function canSeeItem(item) {
        if (!item || typeof item !== 'object') {
            return false;
        }
        if (item.requiresBatchAccess && !canAccessBatchSurfaces()) {
            return false;
        }
        if (item.capability && window.WBGLPolicy && typeof window.WBGLPolicy.can === 'function') {
            const capability = item.capability;
            return window.WBGLPolicy.can(capability.resource, capability.action, {
                permission: capability.permission || null,
            });
        }
        return true;
    }

    function createNavItem(item, context) {
        const anchor = document.createElement('a');
        anchor.href = context.basePath + item.href;
        anchor.className = 'btn-global' + (context.currentPage === item.page ? ' active' : '');
        if (item.id === 'home') {
            anchor.classList.add('btn-global-home');
        }

        const icon = document.createElement('span');
        icon.className = 'nav-icon';
        icon.textContent = item.icon;

        const label = document.createElement('span');
        label.className = 'nav-label';
        label.setAttribute('data-i18n', item.labelKey);
        label.textContent = translate(item.labelKey, item.labelFallback || item.id);

        anchor.appendChild(icon);
        anchor.appendChild(label);

        if (item.badgeKey && Number(context.badges[item.badgeKey] || 0) > 0) {
            const badge = document.createElement('span');
            badge.className = 'header-badge';
            badge.textContent = String(context.badges[item.badgeKey]);
            anchor.appendChild(badge);
        }

        return anchor;
    }

    function readContext(root) {
        const basePath = root.getAttribute('data-nav-base') || '/';
        const currentPage = (root.getAttribute('data-nav-current') || '').trim();
        const productionMode = root.getAttribute('data-nav-production-mode') === '1';

        return {
            basePath: basePath,
            currentPage: currentPage,
            productionMode: productionMode,
            badges: {},
        };
    }

    function render(root) {
        if (!(root instanceof HTMLElement)) {
            return;
        }

        const context = readContext(root);
        const fragment = document.createDocumentFragment();

        NAV_MANIFEST.forEach((item) => {
            if (item.hideInProduction && context.productionMode) {
                return;
            }
            if (!canSeeItem(item)) {
                return;
            }
            fragment.appendChild(createNavItem(item, context));
        });

        root.innerHTML = '';
        root.appendChild(fragment);
    }

    window.WBGLNavManifest = NAV_MANIFEST;
    window.WBGLNav = {
        render: render,
        all: function () {
            return NAV_MANIFEST.slice();
        },
    };
})();
