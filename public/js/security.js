(function () {
    'use strict';

    if (window.__wbglSecurityBootstrapped) {
        return;
    }
    window.__wbglSecurityBootstrapped = true;

    function readCookie(name) {
        const escaped = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const match = document.cookie.match(new RegExp('(?:^|; )' + escaped + '=([^;]*)'));
        return match ? decodeURIComponent(match[1]) : '';
    }

    function getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta && meta.content) {
            return meta.content;
        }

        if (window.WBGL_CSRF_TOKEN && typeof window.WBGL_CSRF_TOKEN === 'string') {
            return window.WBGL_CSRF_TOKEN;
        }

        return readCookie('wbgl_csrf_token');
    }

    function isMutating(method) {
        const normalized = String(method || 'GET').toUpperCase();
        return normalized === 'POST' || normalized === 'PUT' || normalized === 'PATCH' || normalized === 'DELETE';
    }

    function isSameOrigin(url) {
        if (!url) {
            return true;
        }
        try {
            const target = new URL(String(url), window.location.href);
            return target.origin === window.location.origin;
        } catch (e) {
            return true;
        }
    }

    function patchFetch() {
        if (typeof window.fetch !== 'function') {
            return;
        }
        if (window.__wbglFetchSecured) {
            return;
        }
        window.__wbglFetchSecured = true;

        const nativeFetch = window.fetch.bind(window);
        window.fetch = function (input, init) {
            const config = init ? { ...init } : {};
            const inputMethod = (input && typeof input === 'object' && 'method' in input) ? input.method : undefined;
            const method = String(config.method || inputMethod || 'GET').toUpperCase();
            const inputUrl = typeof input === 'string'
                ? input
                : (input && typeof input === 'object' && 'url' in input ? input.url : '');

            if (isMutating(method) && isSameOrigin(inputUrl)) {
                const inheritedHeaders = input instanceof Request ? input.headers : undefined;
                const headers = new Headers(config.headers || inheritedHeaders || {});
                const token = getCsrfToken();
                if (token && !headers.has('X-CSRF-Token')) {
                    headers.set('X-CSRF-Token', token);
                }
                config.headers = headers;
                if (!config.credentials) {
                    config.credentials = 'same-origin';
                }
            }

            return nativeFetch(input, config).then((response) => {
                const inputUrl = typeof input === 'string'
                    ? input
                    : (input && typeof input === 'object' && 'url' in input ? input.url : '');
                if (isSameOrigin(inputUrl) && (response.status === 401 || response.status === 403)) {
                    document.dispatchEvent(new CustomEvent('wbgl:http-auth-error', {
                        detail: {
                            status: response.status,
                            url: inputUrl || window.location.pathname,
                        },
                    }));
                }
                return response;
            });
        };
    }

    window.WBGLSecurity = window.WBGLSecurity || {
        getCsrfToken,
        appendCsrf(formData) {
            if (!(formData instanceof FormData)) {
                return formData;
            }
            if (!formData.has('_csrf')) {
                const token = getCsrfToken();
                if (token) {
                    formData.append('_csrf', token);
                }
            }
            return formData;
        }
    };

    patchFetch();
})();
