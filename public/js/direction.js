(function () {
    'use strict';

    const OVERRIDES = ['auto', 'rtl', 'ltr'];
    const RTL_LOCALES = ['ar'];

    const state = {
        locale: 'ar',
        override: 'auto',
        defaultOverride: 'auto',
        direction: 'rtl',
        source: 'locale',
    };

    function normalizeOverride(value) {
        const candidate = String(value || '').trim().toLowerCase();
        return OVERRIDES.includes(candidate) ? candidate : 'auto';
    }

    function localeDirection(locale) {
        const normalized = String(locale || '').trim().toLowerCase().split(/[-_]/)[0];
        return RTL_LOCALES.includes(normalized) ? 'rtl' : 'ltr';
    }

    function resolveDirection(locale, override, defaultOverride) {
        const normalizedOverride = normalizeOverride(override);
        if (normalizedOverride !== 'auto') {
            return {
                direction: normalizedOverride,
                source: 'user_override',
                override: normalizedOverride,
            };
        }

        const normalizedDefault = normalizeOverride(defaultOverride);
        if (normalizedDefault !== 'auto') {
            return {
                direction: normalizedDefault,
                source: 'org_default',
                override: 'auto',
            };
        }

        return {
            direction: localeDirection(locale),
            source: 'locale',
            override: 'auto',
        };
    }

    function getBootstrap() {
        return window.WBGL_BOOTSTRAP || {};
    }

    function hasAuthenticatedUser() {
        const boot = getBootstrap();
        return Boolean(boot?.user?.id);
    }

    function applyDirection() {
        document.documentElement.setAttribute('dir', state.direction);
        document.documentElement.setAttribute('data-direction-source', state.source);
        document.documentElement.setAttribute('data-direction-override', state.override);
        if (document.body) {
            document.body.setAttribute('dir', state.direction);
            document.body.classList.toggle('wbgl-rtl', state.direction === 'rtl');
            document.body.classList.toggle('wbgl-ltr', state.direction === 'ltr');
        }

        document.querySelectorAll('#wbgl-direction-current').forEach((badge) => {
            badge.textContent = (state.override === 'auto' ? 'AUTO' : state.override.toUpperCase());
        });

        document.querySelectorAll('[data-dir-override]').forEach((element) => {
            const requested = normalizeOverride(element.getAttribute('data-dir-override'));
            if (requested === 'auto') {
                element.setAttribute('dir', state.direction);
                return;
            }
            element.setAttribute('dir', requested);
        });
    }

    async function persistDirectionOverride(override) {
        try {
            const response = await fetch('/api/user-preferences.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ direction_override: override }),
            });
            return response.ok;
        } catch (error) {
            return false;
        }
    }

    async function setOverride(override, options) {
        const config = Object.assign({ persist: true, source: 'ui' }, options || {});
        state.override = normalizeOverride(override);
        localStorage.setItem('wbgl_direction_override', state.override);

        const resolved = resolveDirection(state.locale, state.override, state.defaultOverride);
        state.direction = resolved.direction;
        state.source = config.source || resolved.source;
        applyDirection();

        if (config.persist !== false && hasAuthenticatedUser()) {
            await persistDirectionOverride(state.override);
        }

        document.dispatchEvent(new CustomEvent('wbgl:direction-changed', {
            detail: {
                direction: state.direction,
                override: state.override,
                source: state.source,
            },
        }));
        return state.direction;
    }

    function toggleOverride() {
        const index = OVERRIDES.indexOf(state.override);
        const next = OVERRIDES[(index + 1) % OVERRIDES.length];
        return setOverride(next, { source: 'toggle' });
    }

    function bindToggleButtons() {
        document.querySelectorAll('[data-wbgl-direction-toggle]').forEach((button) => {
            if (button.dataset.boundDirectionToggle === '1') {
                return;
            }
            button.dataset.boundDirectionToggle = '1';
            button.addEventListener('click', function () {
                toggleOverride();
            });
        });
    }

    function init() {
        const boot = getBootstrap();
        const locale = (window.WBGLI18n && typeof window.WBGLI18n.getLanguage === 'function')
            ? window.WBGLI18n.getLanguage()
            : (boot?.user?.preferences?.language || boot?.defaults?.locale || 'ar');
        state.locale = locale;
        state.defaultOverride = normalizeOverride(boot?.defaults?.direction || 'auto');

        const userOverride = normalizeOverride(boot?.user?.preferences?.direction_override || 'auto');
        const cachedOverride = normalizeOverride(localStorage.getItem('wbgl_direction_override'));
        state.override = boot?.user?.id ? userOverride : cachedOverride;
        if (state.override === 'auto' && !boot?.user?.id) {
            state.override = state.defaultOverride;
        }

        const resolved = resolveDirection(state.locale, state.override, state.defaultOverride);
        state.direction = resolved.direction;
        state.source = resolved.source;

        applyDirection();
        bindToggleButtons();
    }

    function onLocaleChanged(locale) {
        state.locale = locale || state.locale;
        if (state.override !== 'auto') {
            return;
        }
        const resolved = resolveDirection(state.locale, state.override, state.defaultOverride);
        state.direction = resolved.direction;
        state.source = resolved.source;
        applyDirection();
    }

    window.WBGLDirection = {
        init: init,
        getDirection: function () {
            return state.direction;
        },
        getOverride: function () {
            return state.override;
        },
        setOverride: setOverride,
        toggleOverride: toggleOverride,
        onLocaleChanged: onLocaleChanged,
        resolveDirection: resolveDirection,
    };

    document.addEventListener('DOMContentLoaded', function () {
        init();
    });
})();
