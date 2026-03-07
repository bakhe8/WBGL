(function () {
    'use strict';

    if (window.WBGLDialog) {
        return;
    }

    const queue = [];
    let active = null;
    let host = null;

    function t(key, fallback, params) {
        if (window.WBGLI18n && typeof window.WBGLI18n.t === 'function') {
            return window.WBGLI18n.t(key, fallback || key, params || undefined);
        }
        let out = String(fallback || key || '');
        if (params && typeof params === 'object') {
            Object.keys(params).forEach((token) => {
                out = out.replace(new RegExp(`{{\\s*${token}\\s*}}`, 'g'), String(params[token]));
            });
        }
        return out;
    }

    function getDir() {
        const dir = String(document.documentElement.getAttribute('dir') || '').toLowerCase();
        return dir === 'rtl' ? 'rtl' : 'ltr';
    }

    function normalizeTone(tone, type) {
        const allowed = new Set(['primary', 'info', 'success', 'warning', 'danger']);
        const normalized = String(tone || '').toLowerCase();
        if (allowed.has(normalized)) {
            return normalized;
        }
        if (type === 'alert') {
            return 'info';
        }
        return 'primary';
    }

    function defaultKindByTone(tone, type) {
        if (tone === 'danger') {
            return t('common.dialog.kind.error', 'خطأ');
        }
        if (tone === 'warning') {
            return t('common.dialog.kind.warning', 'تحذير');
        }
        if (tone === 'success') {
            return t('common.dialog.kind.success', 'نجاح');
        }
        if (tone === 'info') {
            return t('common.dialog.kind.info', 'معلومة');
        }
        if (type === 'confirm') {
            return t('common.dialog.kind.confirm', 'تأكيد');
        }
        if (type === 'prompt') {
            return t('common.dialog.kind.input', 'إدخال');
        }
        return t('common.dialog.kind.notice', 'تنبيه');
    }

    function defaultIconByTone(tone, type) {
        if (tone === 'danger') return '⛔';
        if (tone === 'warning') return '⚠';
        if (tone === 'success') return '✓';
        if (tone === 'info') return 'ℹ';
        if (type === 'confirm') return '⚡';
        if (type === 'prompt') return '✎';
        return '•';
    }

    function ensureHost() {
        if (host && document.body.contains(host)) {
            return host;
        }

        host = document.createElement('div');
        host.id = 'wbgl-dialog-host';
        host.className = 'wbgl-dialog-host';
        host.setAttribute('aria-live', 'polite');
        document.body.appendChild(host);

        let style = document.getElementById('wbgl-dialog-style');
        if (!style) {
            style = document.createElement('style');
            style.id = 'wbgl-dialog-style';
            style.textContent = `
            .wbgl-dialog-host {
                position: fixed;
                inset: 0;
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 5000;
                pointer-events: none;
            }
            .wbgl-dialog-backdrop {
                position: absolute;
                inset: 0;
                background: rgba(8, 12, 20, 0.55);
                backdrop-filter: blur(2px);
                pointer-events: auto;
                opacity: 0;
                transition: opacity 180ms ease;
            }
            .wbgl-dialog-panel {
                position: relative;
                width: min(520px, calc(100vw - 28px));
                border-radius: 16px;
                border: 1px solid rgba(148, 163, 184, 0.2);
                background: var(--bg-card, #101827);
                color: var(--text-primary, #f8fafc);
                box-shadow: 0 20px 50px rgba(2, 6, 23, 0.45);
                transform: translateY(12px) scale(0.985);
                opacity: 0;
                pointer-events: auto;
                transition: transform 180ms ease, opacity 180ms ease;
                overflow: hidden;
            }
            .wbgl-dialog-panel::before {
                content: "";
                position: absolute;
                inset-block-start: 0;
                inset-inline-start: 0;
                inset-inline-end: 0;
                height: 3px;
                background: linear-gradient(90deg, #3b82f6, #60a5fa);
                opacity: 0.9;
            }
            .wbgl-dialog-host.is-open .wbgl-dialog-backdrop {
                opacity: 1;
            }
            .wbgl-dialog-host.is-open .wbgl-dialog-panel {
                transform: translateY(0) scale(1);
                opacity: 1;
            }
            .wbgl-dialog-panel[data-tone="info"] {
                border-color: rgba(56, 189, 248, 0.42);
            }
            .wbgl-dialog-panel[data-tone="success"] {
                border-color: rgba(16, 185, 129, 0.42);
            }
            .wbgl-dialog-panel[data-tone="warning"] {
                border-color: rgba(245, 158, 11, 0.42);
            }
            .wbgl-dialog-panel[data-tone="danger"] {
                border-color: rgba(239, 68, 68, 0.42);
            }
            .wbgl-dialog-panel[data-tone="info"]::before {
                background: linear-gradient(90deg, #0ea5e9, #38bdf8);
            }
            .wbgl-dialog-panel[data-tone="success"]::before {
                background: linear-gradient(90deg, #059669, #34d399);
            }
            .wbgl-dialog-panel[data-tone="warning"]::before {
                background: linear-gradient(90deg, #d97706, #f59e0b);
            }
            .wbgl-dialog-panel[data-tone="danger"]::before {
                background: linear-gradient(90deg, #dc2626, #f87171);
            }
            .wbgl-dialog-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
                padding: 16px 18px 10px;
                border-bottom: 1px solid rgba(148, 163, 184, 0.14);
            }
            .wbgl-dialog-title-wrap {
                display: inline-flex;
                align-items: center;
                gap: 10px;
                min-width: 0;
            }
            .wbgl-dialog-icon {
                width: 24px;
                height: 24px;
                border-radius: 8px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                font-size: 14px;
                font-weight: 800;
                background: var(--accent-primary-light, rgba(59, 130, 246, 0.16));
                color: var(--accent-primary-hover, #1d4ed8);
                border: 1px solid color-mix(in srgb, var(--accent-primary, #3b82f6) 38%, transparent);
                flex-shrink: 0;
            }
            .wbgl-dialog-panel[data-tone="info"] .wbgl-dialog-icon {
                background: var(--accent-primary-light, rgba(59, 130, 246, 0.16));
                color: var(--accent-primary-hover, #1d4ed8);
                border-color: color-mix(in srgb, var(--accent-primary, #3b82f6) 42%, transparent);
            }
            .wbgl-dialog-panel[data-tone="success"] .wbgl-dialog-icon {
                background: var(--accent-success-light, rgba(16, 185, 129, 0.16));
                color: var(--accent-success-hover, #047857);
                border-color: color-mix(in srgb, var(--accent-success, #10b981) 42%, transparent);
            }
            .wbgl-dialog-panel[data-tone="warning"] .wbgl-dialog-icon {
                background: var(--accent-warning-light, rgba(245, 158, 11, 0.18));
                color: var(--accent-warning-hover, #b45309);
                border-color: color-mix(in srgb, var(--accent-warning, #f59e0b) 42%, transparent);
            }
            .wbgl-dialog-panel[data-tone="danger"] .wbgl-dialog-icon {
                background: var(--accent-danger-light, rgba(239, 68, 68, 0.18));
                color: var(--accent-danger-hover, #b91c1c);
                border-color: color-mix(in srgb, var(--accent-danger, #ef4444) 42%, transparent);
            }
            .wbgl-dialog-title {
                margin: 0;
                font-size: 16px;
                font-weight: 800;
                line-height: 1.3;
            }
            .wbgl-dialog-kind {
                font-size: 12px;
                opacity: 0.95;
                font-weight: 600;
                border-radius: 999px;
                padding: 2px 8px;
                background: var(--accent-primary-light, rgba(59, 130, 246, 0.16));
                color: var(--accent-primary-hover, #1d4ed8);
                white-space: nowrap;
                border: 1px solid color-mix(in srgb, var(--accent-primary, #3b82f6) 38%, transparent);
            }
            .wbgl-dialog-panel[data-tone="info"] .wbgl-dialog-kind {
                background: var(--accent-primary-light, rgba(59, 130, 246, 0.16));
                color: var(--accent-primary-hover, #1d4ed8);
                border-color: color-mix(in srgb, var(--accent-primary, #3b82f6) 42%, transparent);
            }
            .wbgl-dialog-panel[data-tone="success"] .wbgl-dialog-kind {
                background: var(--accent-success-light, rgba(16, 185, 129, 0.16));
                color: var(--accent-success-hover, #047857);
                border-color: color-mix(in srgb, var(--accent-success, #10b981) 42%, transparent);
            }
            .wbgl-dialog-panel[data-tone="warning"] .wbgl-dialog-kind {
                background: var(--accent-warning-light, rgba(245, 158, 11, 0.18));
                color: var(--accent-warning-hover, #b45309);
                border-color: color-mix(in srgb, var(--accent-warning, #f59e0b) 42%, transparent);
            }
            .wbgl-dialog-panel[data-tone="danger"] .wbgl-dialog-kind {
                background: var(--accent-danger-light, rgba(239, 68, 68, 0.18));
                color: var(--accent-danger-hover, #b91c1c);
                border-color: color-mix(in srgb, var(--accent-danger, #ef4444) 42%, transparent);
            }
            .wbgl-dialog-body {
                padding: 14px 18px 8px;
            }
            .wbgl-dialog-message {
                margin: 0;
                font-size: 14px;
                line-height: 1.7;
                white-space: pre-wrap;
            }
            .wbgl-dialog-input-wrap {
                margin-top: 12px;
            }
            .wbgl-dialog-input {
                width: 100%;
                min-height: 44px;
                border-radius: 10px;
                border: 1px solid var(--border-primary, #d1d5db);
                background: var(--bg-secondary, #f3f4f6);
                color: var(--text-primary, #111827);
                font-size: 14px;
                padding: 10px 12px;
                outline: none;
                transition: border-color 140ms ease, box-shadow 140ms ease, background 140ms ease;
            }
            .wbgl-dialog-input::placeholder {
                color: var(--text-muted, #6b7280);
            }
            .wbgl-dialog-input:focus {
                border-color: var(--border-focus, var(--accent-primary, #3b82f6));
                background: var(--bg-card, #ffffff);
                box-shadow: 0 0 0 3px var(--theme-focus-ring-medium, rgba(59, 130, 246, 0.2));
            }
            .wbgl-dialog-validation {
                margin-top: 8px;
                font-size: 12px;
                color: var(--accent-danger, #ef4444);
                min-height: 16px;
            }
            .wbgl-dialog-actions {
                padding: 12px 18px 16px;
                display: flex;
                align-items: center;
                justify-content: flex-end;
                gap: 10px;
            }
            .wbgl-dialog-btn {
                border: 1px solid var(--border-primary, #d1d5db);
                background: var(--bg-secondary, #f3f4f6);
                color: var(--text-primary, #111827);
                border-radius: 10px;
                min-height: 38px;
                padding: 0 14px;
                font-size: 13px;
                font-weight: 700;
                cursor: pointer;
                transition: transform 120ms ease, background 120ms ease, border-color 120ms ease, color 120ms ease;
            }
            .wbgl-dialog-btn:hover {
                transform: translateY(-1px);
                border-color: var(--border-focus, var(--accent-primary, #3b82f6));
                background: var(--bg-hover, #e5e7eb);
            }
            .wbgl-dialog-btn:active {
                transform: translateY(0);
            }
            .wbgl-dialog-btn.primary {
                background: linear-gradient(135deg, var(--accent-primary-hover, #2563eb), var(--accent-primary, #3b82f6));
                border-color: var(--accent-primary, #3b82f6);
                color: #fff;
            }
            .wbgl-dialog-btn.primary:hover {
                background: linear-gradient(135deg, var(--accent-primary, #3b82f6), var(--accent-primary-hover, #2563eb));
            }
            .wbgl-dialog-btn.primary[data-tone="info"] {
                background: linear-gradient(135deg, #0284c7, var(--accent-primary, #0ea5e9));
                border-color: var(--accent-primary, #0ea5e9);
            }
            .wbgl-dialog-btn.primary[data-tone="success"] {
                background: linear-gradient(135deg, var(--accent-success-hover, #059669), var(--accent-success, #10b981));
                border-color: var(--accent-success, #10b981);
            }
            .wbgl-dialog-btn.primary[data-tone="warning"] {
                background: linear-gradient(135deg, var(--accent-warning-hover, #d97706), var(--accent-warning, #f59e0b));
                border-color: var(--accent-warning, #f59e0b);
            }
            .wbgl-dialog-btn.primary[data-tone="danger"] {
                background: linear-gradient(135deg, var(--accent-danger-hover, #dc2626), var(--accent-danger, #ef4444));
                border-color: var(--accent-danger, #ef4444);
            }
            .wbgl-dialog-panel[dir="rtl"] .wbgl-dialog-actions {
                flex-direction: row-reverse;
            }
            .wbgl-dialog-panel[dir="rtl"] .wbgl-dialog-body,
            .wbgl-dialog-panel[dir="rtl"] .wbgl-dialog-header {
                text-align: right;
            }
            .wbgl-dialog-panel[dir="ltr"] .wbgl-dialog-body,
            .wbgl-dialog-panel[dir="ltr"] .wbgl-dialog-header {
                text-align: left;
            }
        `;
            document.head.appendChild(style);
        }
        return host;
    }

    function createRequest(type, options) {
        return new Promise((resolve) => {
            queue.push({
                type,
                options: options || {},
                resolve,
            });
            pump();
        });
    }

    function closeActive(result) {
        if (!active) {
            return;
        }
        const { resolve } = active;
        active = null;
        if (host) {
            host.classList.remove('is-open');
            window.setTimeout(() => {
                if (!active && host) {
                    host.innerHTML = '';
                }
                pump();
            }, 170);
        } else {
            pump();
        }
        resolve(result);
    }

    function buildDialog(request) {
        const type = request.type;
        const opts = request.options || {};
        const dir = getDir();
        const tone = normalizeTone(opts.tone, type);

        const title = String(opts.title || (
            type === 'confirm' ? t('common.dialog.confirm_title', 'تأكيد الإجراء')
                : type === 'prompt' ? t('common.dialog.input_title', 'إدخال مطلوب')
                    : t('common.dialog.notice_title', 'تنبيه')
        ));
        const message = String(opts.message || '');
        const kind = String(opts.kind || defaultKindByTone(tone, type));
        const icon = String(opts.icon || defaultIconByTone(tone, type));

        const panel = document.createElement('section');
        panel.className = 'wbgl-dialog-panel';
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-modal', 'true');
        panel.setAttribute('dir', dir);
        panel.setAttribute('data-tone', tone);

        const header = document.createElement('header');
        header.className = 'wbgl-dialog-header';
        const titleWrap = document.createElement('div');
        titleWrap.className = 'wbgl-dialog-title-wrap';
        const iconEl = document.createElement('span');
        iconEl.className = 'wbgl-dialog-icon';
        iconEl.textContent = icon;
        const titleEl = document.createElement('h3');
        titleEl.className = 'wbgl-dialog-title';
        titleEl.textContent = title;
        titleWrap.appendChild(iconEl);
        titleWrap.appendChild(titleEl);
        const kindEl = document.createElement('span');
        kindEl.className = 'wbgl-dialog-kind';
        kindEl.textContent = kind;
        header.appendChild(titleWrap);
        header.appendChild(kindEl);

        const body = document.createElement('div');
        body.className = 'wbgl-dialog-body';
        const messageEl = document.createElement('p');
        messageEl.className = 'wbgl-dialog-message';
        messageEl.textContent = message;
        body.appendChild(messageEl);

        let inputEl = null;
        let validationEl = null;
        if (type === 'prompt') {
            const wrap = document.createElement('div');
            wrap.className = 'wbgl-dialog-input-wrap';
            inputEl = document.createElement(opts.multiline ? 'textarea' : 'input');
            inputEl.className = 'wbgl-dialog-input';
            if (!opts.multiline) {
                inputEl.type = 'text';
            }
            inputEl.placeholder = String(opts.placeholder || '');
            inputEl.value = String(opts.defaultValue || '');
            if (opts.maxLength && Number(opts.maxLength) > 0) {
                inputEl.maxLength = Number(opts.maxLength);
            }
            wrap.appendChild(inputEl);
            validationEl = document.createElement('div');
            validationEl.className = 'wbgl-dialog-validation';
            wrap.appendChild(validationEl);
            body.appendChild(wrap);
        }

        const actions = document.createElement('footer');
        actions.className = 'wbgl-dialog-actions';

        const cancelText = String(opts.cancelText || t('common.dialog.cancel', 'إلغاء'));
        const confirmText = String(opts.confirmText || (
            type === 'alert' ? t('common.dialog.ok', 'موافق') : t('common.dialog.confirm', 'تأكيد')
        ));

        if (type !== 'alert') {
            const cancelBtn = document.createElement('button');
            cancelBtn.type = 'button';
            cancelBtn.className = 'wbgl-dialog-btn';
            cancelBtn.textContent = cancelText;
            cancelBtn.addEventListener('click', () => closeActive(type === 'prompt' ? null : false));
            actions.appendChild(cancelBtn);
        }

        const confirmBtn = document.createElement('button');
        confirmBtn.type = 'button';
        confirmBtn.className = 'wbgl-dialog-btn primary';
        confirmBtn.setAttribute('data-tone', tone);
        confirmBtn.textContent = confirmText;
        confirmBtn.addEventListener('click', () => {
            if (type !== 'prompt') {
                closeActive(type === 'alert' ? true : true);
                return;
            }
            const value = String(inputEl ? inputEl.value : '').trim();
            if (opts.required && value === '') {
                if (validationEl) {
                    validationEl.textContent = String(opts.requiredMessage || t('common.dialog.required', 'هذا الحقل مطلوب.'));
                }
                if (inputEl) {
                    inputEl.focus();
                }
                return;
            }
            if (typeof opts.validate === 'function') {
                const validationMessage = opts.validate(value);
                if (validationMessage) {
                    if (validationEl) {
                        validationEl.textContent = String(validationMessage);
                    }
                    if (inputEl) {
                        inputEl.focus();
                    }
                    return;
                }
            }
            closeActive(value);
        });
        actions.appendChild(confirmBtn);

        if (type === 'prompt' && inputEl) {
            inputEl.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' && !opts.multiline) {
                    event.preventDefault();
                    confirmBtn.click();
                }
                if (event.key === 'Escape') {
                    event.preventDefault();
                    closeActive(null);
                }
            });
            inputEl.addEventListener('input', () => {
                if (validationEl) {
                    validationEl.textContent = '';
                }
            });
        } else {
            panel.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && type !== 'alert') {
                    event.preventDefault();
                    closeActive(type === 'prompt' ? null : false);
                }
            });
        }

        panel.appendChild(header);
        panel.appendChild(body);
        panel.appendChild(actions);

        return { panel, inputEl, confirmBtn };
    }

    function pump() {
        if (active || queue.length === 0) {
            return;
        }
        active = queue.shift();
        const mount = ensureHost();
        mount.innerHTML = '';

        const backdrop = document.createElement('div');
        backdrop.className = 'wbgl-dialog-backdrop';
        backdrop.addEventListener('click', () => {
            if (!active || active.type === 'alert') {
                return;
            }
            closeActive(active.type === 'prompt' ? null : false);
        });

        const { panel, inputEl, confirmBtn } = buildDialog(active);
        mount.appendChild(backdrop);
        mount.appendChild(panel);

        window.requestAnimationFrame(() => {
            mount.classList.add('is-open');
            if (inputEl) {
                inputEl.focus();
                inputEl.select?.();
            } else if (confirmBtn) {
                confirmBtn.focus();
            }
        });
    }

    window.WBGLDialog = {
        alert(message, options) {
            return createRequest('alert', { ...(options || {}), message: String(message || '') }).then(() => undefined);
        },
        confirm(message, options) {
            return createRequest('confirm', { ...(options || {}), message: String(message || '') }).then(Boolean);
        },
        prompt(options) {
            const opts = options && typeof options === 'object'
                ? options
                : { message: String(options || '') };
            return createRequest('prompt', opts).then((value) => (value == null ? null : String(value)));
        },
    };
})();
