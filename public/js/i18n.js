(function () {
    'use strict';

    const SUPPORTED_LOCALES = ['ar', 'en'];
    const FALLBACK_LOCALE = 'ar';
    const DEFAULT_NAMESPACES = ['common'];

    const store = {
        locale: FALLBACK_LOCALE,
        source: 'fallback',
        namespaces: new Set(DEFAULT_NAMESPACES),
        messages: {
            ar: Object.create(null),
            en: Object.create(null),
        },
        loaded: new Set(),
    };

    function getBootstrap() {
        return window.WBGL_BOOTSTRAP || {};
    }

    function hasAuthenticatedUser() {
        const boot = getBootstrap();
        return Boolean(boot?.user?.id);
    }

    function normalizeLocale(value) {
        if (typeof value !== 'string') {
            return null;
        }
        const trimmed = value.trim().toLowerCase();
        if (!trimmed) {
            return null;
        }
        const short = trimmed.split(/[-_]/)[0];
        return SUPPORTED_LOCALES.includes(short) ? short : null;
    }

    function resolveInitialLocale() {
        const boot = getBootstrap();
        const userLocale = normalizeLocale(boot?.user?.preferences?.language);
        if (userLocale) {
            return { locale: userLocale, source: 'user_preference' };
        }

        const orgDefault = normalizeLocale(boot?.defaults?.locale);
        if (orgDefault) {
            return { locale: orgDefault, source: 'org_default' };
        }

        const browser = normalizeLocale((navigator.languages && navigator.languages[0]) || navigator.language || '');
        if (browser) {
            return { locale: browser, source: 'browser' };
        }

        return { locale: FALLBACK_LOCALE, source: 'fallback' };
    }

    function parseNamespaces(raw) {
        const values = String(raw || '')
            .split(',')
            .map((item) => item.trim())
            .filter(Boolean);
        return values.length ? values : DEFAULT_NAMESPACES;
    }

    function localeNamespaceKey(locale, namespace) {
        return locale + ':' + namespace;
    }

    function mergeMessages(locale, namespace, payload) {
        if (!store.messages[locale]) {
            store.messages[locale] = Object.create(null);
        }
        store.messages[locale][namespace] = Object.assign({}, payload || {});
    }

    async function fetchLocaleNamespace(locale, namespace) {
        const key = localeNamespaceKey(locale, namespace);
        if (store.loaded.has(key)) {
            return;
        }

        const response = await fetch('/public/locales/' + locale + '/' + namespace + '.json', {
            headers: { Accept: 'application/json' },
        });
        if (!response.ok) {
            throw new Error('Locale file not found: ' + key);
        }

        const payload = await response.json();
        mergeMessages(locale, namespace, payload);
        store.loaded.add(key);
    }

    async function ensureNamespaces(locale, namespaces) {
        const targets = (namespaces && namespaces.length ? namespaces : DEFAULT_NAMESPACES)
            .map((item) => item.trim())
            .filter(Boolean);
        const uniqueTargets = Array.from(new Set(targets));

        await Promise.all(uniqueTargets.map(async (namespace) => {
            try {
                await fetchLocaleNamespace(locale, namespace);
            } catch (error) {
                if (locale !== FALLBACK_LOCALE) {
                    await fetchLocaleNamespace(FALLBACK_LOCALE, namespace);
                }
            }
        }));
    }

    function interpolate(value, params) {
        if (!params || typeof params !== 'object') {
            return value;
        }
        return String(value).replace(/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/g, function (_, token) {
            if (Object.prototype.hasOwnProperty.call(params, token)) {
                return String(params[token]);
            }
            return '';
        });
    }

    function lookup(locale, key) {
        const namespaces = Array.from(store.namespaces);
        for (let i = 0; i < namespaces.length; i += 1) {
            const namespace = namespaces[i];
            const bucket = store.messages[locale] && store.messages[locale][namespace];
            if (bucket && Object.prototype.hasOwnProperty.call(bucket, key)) {
                return bucket[key];
            }
        }
        return null;
    }

    function translate(key, fallback, params) {
        const primary = lookup(store.locale, key);
        if (primary !== null) {
            return interpolate(primary, params);
        }

        const fallbackValue = lookup(FALLBACK_LOCALE, key);
        if (fallbackValue !== null) {
            return interpolate(fallbackValue, params);
        }

        return interpolate(fallback || key, params);
    }

    function pluralKey(baseKey, count, locale) {
        const rules = new Intl.PluralRules(locale || store.locale);
        const rule = rules.select(Number(count) || 0);
        return baseKey + '.' + rule;
    }

    function tPlural(baseKey, count, fallback, params) {
        const token = pluralKey(baseKey, count, store.locale);
        const payload = Object.assign({}, params || {}, { count: count });
        return translate(token, fallback || baseKey, payload);
    }

    function updateLanguageBadge() {
        document.querySelectorAll('#wbgl-lang-current').forEach((badge) => {
            badge.textContent = store.locale.toUpperCase();
        });
    }

    function applyTranslations() {
        document.documentElement.setAttribute('lang', store.locale);
        if (document.body) {
            document.body.setAttribute('lang', store.locale);
        }

        document.querySelectorAll('[data-i18n]').forEach((el) => {
            const key = el.getAttribute('data-i18n');
            if (!key) {
                return;
            }
            const fallback = el.getAttribute('data-i18n-fallback') || el.textContent || '';
            el.textContent = translate(key, fallback);
        });

        document.querySelectorAll('[data-i18n-placeholder]').forEach((el) => {
            const key = el.getAttribute('data-i18n-placeholder');
            if (!key) {
                return;
            }
            const fallback = el.getAttribute('placeholder') || '';
            el.setAttribute('placeholder', translate(key, fallback));
        });

        document.querySelectorAll('[data-i18n-title]').forEach((el) => {
            const key = el.getAttribute('data-i18n-title');
            if (!key) {
                return;
            }
            const fallback = el.getAttribute('title') || '';
            el.setAttribute('title', translate(key, fallback));
        });

        document.querySelectorAll('[data-i18n-content]').forEach((el) => {
            const key = el.getAttribute('data-i18n-content');
            if (!key) {
                return;
            }
            const fallback = el.getAttribute('content') || '';
            el.setAttribute('content', translate(key, fallback));
        });

        updateLanguageBadge();
    }

    async function persistLanguage(language) {
        try {
            const response = await fetch('/api/user-preferences.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ language: language }),
            });
            return response.ok;
        } catch (error) {
            return false;
        }
    }

    async function setLanguage(locale, options) {
        const config = Object.assign({ persist: true, source: 'ui' }, options || {});
        const normalized = normalizeLocale(locale) || FALLBACK_LOCALE;
        store.locale = normalized;
        store.source = config.source || 'ui';
        localStorage.setItem('wbgl_lang', normalized);

        await ensureNamespaces(normalized, Array.from(store.namespaces));
        await ensureNamespaces(FALLBACK_LOCALE, Array.from(store.namespaces));
        applyTranslations();

        if (window.WBGLDirection && typeof window.WBGLDirection.onLocaleChanged === 'function') {
            window.WBGLDirection.onLocaleChanged(normalized);
        }

        if (config.persist !== false && hasAuthenticatedUser()) {
            await persistLanguage(normalized);
        }

        document.dispatchEvent(new CustomEvent('wbgl:language-changed', {
            detail: { locale: normalized, source: store.source },
        }));
        return normalized;
    }

    function toggleLanguage() {
        const next = store.locale === 'ar' ? 'en' : 'ar';
        return setLanguage(next, { source: 'toggle' });
    }

    function bindLanguageToggles() {
        document.querySelectorAll('[data-wbgl-lang-toggle]').forEach((button) => {
            if (button.dataset.boundLangToggle === '1') {
                return;
            }
            button.dataset.boundLangToggle = '1';
            button.addEventListener('click', function () {
                toggleLanguage();
            });
        });
    }

    async function syncFromServerPreferences() {
        const boot = getBootstrap();
        if (boot?.user?.id) {
            return;
        }
        if (!document.body || document.body.classList.contains('login-page')) {
            return;
        }
        try {
            const response = await fetch('/api/user-preferences.php', {
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) {
                return;
            }
            const payload = await response.json();
            const language = normalizeLocale(payload?.preferences?.language);
            if (language && language !== store.locale) {
                await setLanguage(language, { persist: false, source: 'api' });
            }
        } catch (error) {
            // Non-blocking sync.
        }
    }

    function formatDate(value, options) {
        if (!value) {
            return '';
        }
        const dateValue = value instanceof Date ? value : new Date(value);
        if (Number.isNaN(dateValue.getTime())) {
            return String(value);
        }
        const formatter = new Intl.DateTimeFormat(store.locale, options || {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
        });
        return formatter.format(dateValue);
    }

    function formatNumber(value, options) {
        const numberValue = Number(value);
        if (Number.isNaN(numberValue)) {
            return String(value);
        }
        return new Intl.NumberFormat(store.locale, options || {}).format(numberValue);
    }

    function formatCurrency(value, currency, options) {
        const numberValue = Number(value);
        if (Number.isNaN(numberValue)) {
            return String(value);
        }
        return new Intl.NumberFormat(store.locale, Object.assign({
            style: 'currency',
            currency: currency || 'SAR',
            maximumFractionDigits: 2,
        }, options || {})).format(numberValue);
    }

    async function init() {
        const resolved = resolveInitialLocale();
        store.locale = resolved.locale;
        store.source = resolved.source;

        const bodyNamespaces = parseNamespaces(document.body && document.body.getAttribute('data-i18n-namespaces'));
        bodyNamespaces.forEach((namespace) => store.namespaces.add(namespace));

        await ensureNamespaces(store.locale, Array.from(store.namespaces));
        await ensureNamespaces(FALLBACK_LOCALE, Array.from(store.namespaces));

        applyTranslations();
        bindLanguageToggles();
        syncFromServerPreferences();
    }

    window.WBGLI18n = {
        init: init,
        t: translate,
        tPlural: tPlural,
        setLanguage: setLanguage,
        toggleLanguage: toggleLanguage,
        getLanguage: function () {
            return store.locale;
        },
        getDirection: function () {
            if (window.WBGLDirection && typeof window.WBGLDirection.getDirection === 'function') {
                return window.WBGLDirection.getDirection();
            }
            return store.locale === 'ar' ? 'rtl' : 'ltr';
        },
        loadNamespaces: async function (namespaces) {
            const targets = Array.isArray(namespaces) ? namespaces : parseNamespaces(namespaces);
            targets.forEach((namespace) => store.namespaces.add(namespace));
            await ensureNamespaces(store.locale, targets);
            await ensureNamespaces(FALLBACK_LOCALE, targets);
            applyTranslations();
        },
        applyTranslations: applyTranslations,
        formatDate: formatDate,
        formatNumber: formatNumber,
        formatCurrency: formatCurrency,
        getState: function () {
            return {
                locale: store.locale,
                source: store.source,
                namespaces: Array.from(store.namespaces),
            };
        },
    };

    document.addEventListener('DOMContentLoaded', function () {
        init();
    });
})();
