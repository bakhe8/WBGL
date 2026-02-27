(function () {
    'use strict';

    const FALLBACK_THEME = 'system';
    const DEFAULT_THEMES = ['system', 'light', 'dark', 'desert'];
    const STYLE_ID = 'wbgl-theme-style';

    const state = {
        selected: FALLBACK_THEME,
        resolved: 'light',
        source: 'fallback',
        allowed: DEFAULT_THEMES.slice(),
    };

    let systemMediaQuery = null;

    function getBootstrap() {
        return window.WBGL_BOOTSTRAP || {};
    }

    function hasAuthenticatedUser() {
        const boot = getBootstrap();
        return Boolean(boot?.user?.id);
    }

    function normalizeTheme(value) {
        const candidate = String(value || '').trim().toLowerCase();
        return state.allowed.includes(candidate) ? candidate : FALLBACK_THEME;
    }

    function systemTheme() {
        if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
            return 'dark';
        }
        return 'light';
    }

    function ensureThemeStylesheet() {
        if (document.getElementById(STYLE_ID)) {
            return;
        }
        const link = document.createElement('link');
        link.id = STYLE_ID;
        link.rel = 'stylesheet';
        link.href = '/public/css/themes.css';
        document.head.appendChild(link);
    }

    function resolveSelectedTheme(selected) {
        if (selected === 'system') {
            return systemTheme();
        }
        return selected;
    }

    function themeLabelKey(theme) {
        return 'theme.label.' + theme;
    }

    function updateThemeBadge() {
        const i18n = window.WBGLI18n;
        const label = i18n && typeof i18n.t === 'function'
            ? i18n.t(themeLabelKey(state.selected), state.selected.toUpperCase().slice(0, 3))
            : state.selected.toUpperCase().slice(0, 3);
        document.querySelectorAll('#wbgl-theme-current').forEach((badge) => {
            badge.textContent = label;
        });
    }

    function applyTheme() {
        state.resolved = resolveSelectedTheme(state.selected);
        document.documentElement.setAttribute('data-theme', state.resolved);
        document.documentElement.setAttribute('data-theme-preference', state.selected);
        if (document.body) {
            document.body.setAttribute('data-theme', state.resolved);
            document.body.setAttribute('data-theme-preference', state.selected);
        }
        updateThemeBadge();
    }

    async function persistTheme(theme) {
        try {
            const response = await fetch('/api/user-preferences.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ theme: theme }),
            });
            return response.ok;
        } catch (error) {
            return false;
        }
    }

    async function setTheme(theme, options) {
        const config = Object.assign({ persist: true, source: 'ui' }, options || {});
        state.selected = normalizeTheme(theme);
        state.source = config.source || 'ui';
        localStorage.setItem('wbgl_theme', state.selected);

        applyTheme();

        if (config.persist !== false && hasAuthenticatedUser()) {
            await persistTheme(state.selected);
        }

        document.dispatchEvent(new CustomEvent('wbgl:theme-changed', {
            detail: {
                selected: state.selected,
                resolved: state.resolved,
                source: state.source,
            },
        }));
        return state.selected;
    }

    function toggleTheme() {
        const index = state.allowed.indexOf(state.selected);
        const next = state.allowed[(index + 1) % state.allowed.length];
        return setTheme(next, { source: 'toggle' });
    }

    function bindThemeToggleButtons() {
        document.querySelectorAll('[data-wbgl-theme-toggle]').forEach((button) => {
            if (button.dataset.boundThemeToggle === '1') {
                return;
            }
            button.dataset.boundThemeToggle = '1';
            button.addEventListener('click', function () {
                toggleTheme();
            });
        });
    }

    function bindSystemThemeListener() {
        if (!window.matchMedia) {
            return;
        }
        systemMediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
        const handler = function () {
            if (state.selected === 'system') {
                applyTheme();
            }
        };
        if (typeof systemMediaQuery.addEventListener === 'function') {
            systemMediaQuery.addEventListener('change', handler);
        } else if (typeof systemMediaQuery.addListener === 'function') {
            systemMediaQuery.addListener(handler);
        }
    }

    function init() {
        const boot = getBootstrap();
        const allowed = boot?.ui?.allowed?.themes;
        if (Array.isArray(allowed) && allowed.length > 0) {
            state.allowed = Array.from(new Set(allowed.map((item) => String(item).toLowerCase())));
            if (!state.allowed.includes(FALLBACK_THEME)) {
                state.allowed.unshift(FALLBACK_THEME);
            }
        }

        ensureThemeStylesheet();

        const userTheme = normalizeTheme(boot?.user?.preferences?.theme);
        const defaultTheme = normalizeTheme(boot?.defaults?.theme);
        const cachedTheme = normalizeTheme(localStorage.getItem('wbgl_theme'));

        if (boot?.user?.id) {
            state.selected = userTheme;
            state.source = 'user_preference';
        } else if (cachedTheme !== FALLBACK_THEME) {
            state.selected = cachedTheme;
            state.source = 'local_cache';
        } else if (defaultTheme !== FALLBACK_THEME) {
            state.selected = defaultTheme;
            state.source = 'org_default';
        } else {
            state.selected = FALLBACK_THEME;
            state.source = 'fallback';
        }

        applyTheme();
        bindThemeToggleButtons();
        bindSystemThemeListener();
    }

    window.WBGLTheme = {
        init: init,
        getTheme: function () {
            return state.selected;
        },
        getResolvedTheme: function () {
            return state.resolved;
        },
        setTheme: setTheme,
        toggleTheme: toggleTheme,
        allowedThemes: function () {
            return state.allowed.slice();
        },
    };

    document.addEventListener('DOMContentLoaded', function () {
        init();
    });
})();
