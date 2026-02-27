(function () {
    'use strict';

    if (window.__wbglUiRuntimeBound) {
        return;
    }
    window.__wbglUiRuntimeBound = true;

    function getBootstrap() {
        if (!window.WBGL_BOOTSTRAP || typeof window.WBGL_BOOTSTRAP !== 'object') {
            window.WBGL_BOOTSTRAP = {};
        }
        return window.WBGL_BOOTSTRAP;
    }

    async function hydrateSessionUser() {
        const boot = getBootstrap();
        if (boot?.user?.id) {
            return;
        }
        if (!document.body || document.body.classList.contains('login-page')) {
            return;
        }

        try {
            const response = await fetch('/api/me.php', {
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) {
                return;
            }
            const payload = await response.json();
            if (!payload || !payload.success || !payload.user) {
                return;
            }
            boot.user = payload.user;
            if (window.WBGLPolicy && typeof window.WBGLPolicy.setPermissions === 'function') {
                window.WBGLPolicy.setPermissions(payload.user.permissions || []);
            }
        } catch (error) {
            // Non-blocking hydration.
        }
    }

    function applyRouteGuard() {
        const boot = getBootstrap();
        if (!window.WBGLPolicy || typeof window.WBGLPolicy.guardRoute !== 'function') {
            return true;
        }
        return window.WBGLPolicy.guardRoute(boot.route || null, {
            redirectTo: '/index.php',
        });
    }

    function renderNavigation() {
        if (!window.WBGLNav || typeof window.WBGLNav.render !== 'function') {
            return;
        }
        document.querySelectorAll('[data-nav-root]').forEach((root) => {
            window.WBGLNav.render(root);
        });
    }

    function applyPolicyGuards() {
        if (!window.WBGLPolicy || typeof window.WBGLPolicy.applyDomGuards !== 'function') {
            return;
        }
        window.WBGLPolicy.applyDomGuards(document);
    }

    function applyTranslations() {
        if (!window.WBGLI18n || typeof window.WBGLI18n.applyTranslations !== 'function') {
            return;
        }
        window.WBGLI18n.applyTranslations();
    }

    function refreshUiSurfaces() {
        renderNavigation();
        applyPolicyGuards();
        applyTranslations();
    }

    function bindRuntimeEvents() {
        document.addEventListener('wbgl:language-changed', function () {
            refreshUiSurfaces();
        });
        document.addEventListener('wbgl:theme-changed', function () {
            applyPolicyGuards();
        });
        document.addEventListener('wbgl:direction-changed', function () {
            applyPolicyGuards();
        });
        document.addEventListener('wbgl:http-auth-error', function (event) {
            const detail = event && event.detail ? event.detail : {};
            const status = Number(detail.status || 0);
            if (status === 401 && !document.body.classList.contains('login-page')) {
                window.location.href = '/views/login.php';
                return;
            }
            if (status === 403 && typeof window.showToast === 'function') {
                window.showToast('ليس لديك صلاحية لتنفيذ هذا الإجراء', 'error');
            }
        });
    }

    async function init() {
        await hydrateSessionUser();

        if (!applyRouteGuard()) {
            return;
        }

        refreshUiSurfaces();
        bindRuntimeEvents();
    }

    window.WBGLUiRuntime = {
        init: init,
        refresh: refreshUiSurfaces,
    };

    document.addEventListener('DOMContentLoaded', function () {
        init();
    });
})();
