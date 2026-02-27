(function () {
    'use strict';

    const state = {
        permissions: [],
        capabilityMap: {},
    };

    function getBootstrap() {
        return window.WBGL_BOOTSTRAP || {};
    }

    function normalizePermission(value) {
        return String(value || '').trim();
    }

    function setPermissions(permissions) {
        if (!Array.isArray(permissions)) {
            state.permissions = [];
            return;
        }
        state.permissions = Array.from(new Set(permissions.map(normalizePermission).filter(Boolean)));
    }

    function setCapabilityMap(map) {
        if (!map || typeof map !== 'object') {
            state.capabilityMap = {};
            return;
        }
        state.capabilityMap = Object.assign({}, map);
    }

    function hasPermission(requiredPermission) {
        const required = normalizePermission(requiredPermission);
        if (!required) {
            return true;
        }
        if (state.permissions.includes('*')) {
            return true;
        }
        if (state.permissions.includes(required)) {
            return true;
        }

        if (required.includes(':')) {
            const resource = required.split(':')[0];
            if (state.permissions.includes(resource + ':*')) {
                return true;
            }
        }

        if (state.permissions.includes(required + ':*')) {
            return true;
        }

        return false;
    }

    function resolveRequired(resource, action, overridePermission) {
        if (overridePermission) {
            return normalizePermission(overridePermission);
        }
        const capability = normalizePermission((resource || '') + ':' + (action || ''));
        if (!capability) {
            return '';
        }
        if (state.capabilityMap[capability]) {
            return normalizePermission(state.capabilityMap[capability]);
        }
        return capability;
    }

    function can(resource, action, context) {
        if (typeof resource === 'object' && resource !== null) {
            const input = resource;
            return can(input.resource, input.action, input);
        }
        const ctx = context && typeof context === 'object' ? context : {};
        const required = resolveRequired(resource, action, ctx.permission || ctx.requiredPermission || null);
        return hasPermission(required);
    }

    function useCan(resource, action, context) {
        return can(resource, action, context);
    }

    function applyElementPolicy(element) {
        if (!(element instanceof HTMLElement)) {
            return;
        }

        const permission = element.getAttribute('data-authorize-permission');
        const resource = element.getAttribute('data-authorize-resource');
        const action = element.getAttribute('data-authorize-action');
        const mode = (element.getAttribute('data-authorize-mode') || 'hide').toLowerCase();
        const deniedText = element.getAttribute('data-authorize-denied-text') || 'غير مسموح';

        const allowed = can(resource, action, { permission: permission || null });

        if (allowed) {
            element.classList.remove('wbgl-hidden-by-policy');
            element.removeAttribute('aria-disabled');
            element.removeAttribute('data-policy-denied');
            if (mode === 'hide') {
                element.hidden = false;
            } else if ('disabled' in element) {
                element.disabled = false;
            }
            return;
        }

        element.setAttribute('data-policy-denied', '1');
        if (mode === 'disable') {
            if ('disabled' in element) {
                element.disabled = true;
            }
            element.setAttribute('aria-disabled', 'true');
            if (!element.getAttribute('title')) {
                element.setAttribute('title', deniedText);
            }
            return;
        }

        element.hidden = true;
        element.classList.add('wbgl-hidden-by-policy');
    }

    function applyDomGuards(root) {
        const target = root && root.querySelectorAll ? root : document;
        target.querySelectorAll('[data-authorize-permission], [data-authorize-resource]').forEach((el) => {
            applyElementPolicy(el);
        });
    }

    function guardRoute(requirement, options) {
        const config = Object.assign({ redirectTo: '/index.php' }, options || {});
        const requiredPermission = requirement && requirement.required_permission
            ? String(requirement.required_permission)
            : '';

        if (!requiredPermission) {
            return true;
        }

        if (hasPermission(requiredPermission)) {
            return true;
        }

        if (config.redirectTo) {
            window.location.href = config.redirectTo;
        }
        return false;
    }

    function init() {
        const boot = getBootstrap();
        setPermissions(boot?.user?.permissions || []);
        setCapabilityMap(boot?.policy?.capability_map || {});
        applyDomGuards(document);
    }

    function Authorized(options) {
        const input = options && typeof options === 'object' ? options : {};
        const element = input.element instanceof HTMLElement ? input.element : null;
        const allowed = can(input.resource, input.action, { permission: input.permission });

        if (!element) {
            return allowed;
        }

        if (allowed) {
            element.hidden = false;
            element.classList.remove('wbgl-hidden-by-policy');
        } else if (input.mode === 'disable' && 'disabled' in element) {
            element.disabled = true;
            element.setAttribute('aria-disabled', 'true');
        } else {
            element.hidden = true;
            element.classList.add('wbgl-hidden-by-policy');
        }
        return allowed;
    }

    Authorized.apply = applyDomGuards;

    window.WBGLPolicy = {
        init: init,
        can: can,
        useCan: useCan,
        hasPermission: hasPermission,
        guardRoute: guardRoute,
        applyDomGuards: applyDomGuards,
        setPermissions: setPermissions,
        getPermissions: function () {
            return state.permissions.slice();
        },
        setCapabilityMap: setCapabilityMap,
    };

    window.Authorized = Authorized;

    document.addEventListener('DOMContentLoaded', function () {
        init();
    });
})();
