
// Toast Notification Helper (Unified)
window.Toast = {
    _recent: new Map(),
    _defaultDedupeMs: 2200,
    show(message, type = 'info', duration = 3500) {
        const normalizedMessage = String(message || '').trim();
        if (normalizedMessage === '') {
            return;
        }

        const dedupeStore = (window.Toast && window.Toast._recent instanceof Map)
            ? window.Toast._recent
            : (window.Toast._recent = new Map());

        const key = `${String(type || 'info')}::${normalizedMessage}`;
        const now = Date.now();
        const lastSeen = Number(dedupeStore.get(key) || 0);
        if (now - lastSeen < this._defaultDedupeMs) {
            return;
        }
        dedupeStore.set(key, now);

        // Keep dedupe map bounded.
        if (dedupeStore.size > 200) {
            const cutoff = now - 60000;
            for (const [recentKey, recentTs] of dedupeStore.entries()) {
                if (Number(recentTs) < cutoff) {
                    dedupeStore.delete(recentKey);
                }
            }
        }

        let container = document.getElementById('toast-container');
        if (!container) {
            const isRtl = (document.documentElement.getAttribute('dir') || 'rtl') === 'rtl';
            container = document.createElement('div');
            container.id = 'toast-container';
            container.className = `toast-container ${isRtl ? '' : 'toast-container--ltr'}`.trim();
            container.setAttribute('dir', isRtl ? 'rtl' : 'ltr');
            document.body.appendChild(container);
        }

        const computedContainerStyle = window.getComputedStyle(container);
        const containerStyled = computedContainerStyle.position === 'fixed';
        if (!containerStyled) {
            container.style.position = 'fixed';
            container.style.top = '20px';
            container.style.right = '20px';
            container.style.left = 'auto';
            container.style.zIndex = '12000';
            container.style.display = 'flex';
            container.style.flexDirection = 'column';
            container.style.gap = '8px';
            container.style.maxWidth = '420px';
        }

        const toast = document.createElement('div');
        toast.setAttribute('role', 'status');
        toast.setAttribute('aria-live', 'polite');
        toast.className = `toast toast--inline toast-${type || 'info'}`;

        const computedToastStyle = window.getComputedStyle(toast);
        const toastStyled = computedToastStyle.position !== 'static'
            || computedToastStyle.boxShadow !== 'none'
            || computedToastStyle.borderInlineStartWidth !== '0px';
        if (!toastStyled) {
            toast.style.background = '#ffffff';
            toast.style.color = '#1f2937';
            toast.style.borderRadius = '8px';
            toast.style.padding = '12px 14px';
            toast.style.boxShadow = '0 4px 12px rgba(0,0,0,0.12)';
            toast.style.borderInlineStart = '4px solid #0891b2';
            toast.style.transform = 'translateY(-8px)';
            toast.style.opacity = '0';
            toast.style.transition = 'transform 0.2s ease, opacity 0.2s ease';
        }

        const iconByType = {
            success: '✓',
            error: '✕',
            warning: '⚠',
            info: 'ℹ'
        };
        const accentByType = {
            success: '#16a34a',
            error: '#dc2626',
            warning: '#d97706',
            info: '#0891b2'
        };

        if (!toastStyled) {
            toast.style.borderInlineStartColor = accentByType[type] || accentByType.info;
        }

        toast.textContent = `${iconByType[type] || iconByType.info} ${normalizedMessage}`;

        while (container.children.length >= 4) {
            container.removeChild(container.firstChild);
        }

        container.appendChild(toast);

        requestAnimationFrame(() => {
            toast.classList.add('show');
        });

        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 200);
        }, duration);
    }
};
window.showToast = function showToastBridge(message, type = 'info', duration = 3500) {
    if (!window.Toast || typeof window.Toast.show !== 'function') {
        return;
    }
    return window.Toast.show(message, type, duration);
};

// Debug logger (disabled by default)
window.BGL_DEBUG = window.BGL_DEBUG ?? false;
window.BglLogger = window.BglLogger || {
    debug: (...args) => { if (window.BGL_DEBUG) console.log(...args); },
    info: (...args) => { if (window.BGL_DEBUG) console.info(...args); },
    warn: (...args) => { if (window.BGL_DEBUG) console.warn(...args); },
    error: (...args) => { console.error(...args); }
};

function wbglT(key, fallback, params) {
    if (window.WBGLI18n && typeof window.WBGLI18n.t === 'function') {
        return window.WBGLI18n.t(key, fallback || key, params || {});
    }
    return fallback || key;
}

async function wbglDialogPrompt(options) {
    if (window.WBGLDialog && typeof window.WBGLDialog.prompt === 'function') {
        return window.WBGLDialog.prompt(options || {});
    }
    if (typeof window.showToast === 'function') {
        window.showToast(wbglT('common.dialog.unavailable', 'تعذر فتح نافذة الإدخال. أعد تحميل الصفحة.'), 'error');
    } else {
        console.error('WBGLDialog.prompt is not available');
    }
    return null;
}

function wbglCanHandleGlobalShortcut(event) {
    if (event.ctrlKey || event.metaKey || event.altKey) {
        return false;
    }
    const target = event.target;
    if (!target) {
        return true;
    }
    const tag = (target.tagName || '').toUpperCase();
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') {
        return false;
    }
    if (target.isContentEditable) {
        return false;
    }
    return true;
}

function wbglFocusSearchInput() {
    const input = document.querySelector('.search-input, input[name="search"], input[type="search"]');
    if (input && typeof input.focus === 'function') {
        input.focus();
        if (typeof input.select === 'function') {
            input.select();
        }
    }
}

function wbglNavigateTo(path) {
    if (!path) return;
    window.location.href = path;
}

function wbglCanAccessBatchSurfaces() {
    return window.WBGL_BOOTSTRAP?.policy?.batch?.can_access_surfaces === true;
}

function wbglEnsureShortcutModal() {
    let modal = document.getElementById('wbgl-shortcuts-modal');
    if (modal) {
        return modal;
    }

    modal = document.createElement('div');
    modal.id = 'wbgl-shortcuts-modal';
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.className = 'wbgl-shortcuts-overlay';
    modal.hidden = true;

    const batchShortcutRow = wbglCanAccessBatchSurfaces()
        ? `<code>B</code><span>${wbglT('shortcuts.open_batches', '')}</span>`
        : '';

    modal.innerHTML = `
        <div class="wbgl-shortcuts-dialog">
            <div class="wbgl-shortcuts-dialog__header">
                <h3 class="wbgl-shortcuts-dialog__title">${wbglT('shortcuts.title', '')}</h3>
                <button type="button" id="wbgl-shortcuts-close" class="wbgl-shortcuts-dialog__close">${wbglT('shortcuts.close', '')}</button>
            </div>
            <div class="wbgl-shortcuts-grid">
                <code>G</code><span>${wbglT('shortcuts.open_main', '')}</span>
                ${batchShortcutRow}
                <code>S</code><span>${wbglT('shortcuts.open_settings', '')}</span>
                <code>T</code><span>${wbglT('shortcuts.open_stats', '')}</span>
                <code>/</code><span>${wbglT('shortcuts.focus_search', '')}</span>
                <code>?</code><span>${wbglT('shortcuts.toggle_help', '')}</span>
            </div>
        </div>
    `;

    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            modal.hidden = true;
        }
    });

    document.body.appendChild(modal);

    const closeBtn = document.getElementById('wbgl-shortcuts-close');
    if (closeBtn) {
        closeBtn.addEventListener('click', () => {
            modal.hidden = true;
        });
    }

    return modal;
}

function wbglToggleShortcutsModal() {
    const modal = wbglEnsureShortcutModal();
    modal.hidden = !modal.hidden;
}

function wbglBindGlobalShortcuts() {
    if (window.__wbglShortcutsBound) {
        return;
    }
    window.__wbglShortcutsBound = true;

    document.addEventListener('keydown', (event) => {
        if (!wbglCanHandleGlobalShortcut(event)) {
            return;
        }

        const key = (event.key || '').toLowerCase();
        if (key === '?') {
            event.preventDefault();
            wbglToggleShortcutsModal();
            return;
        }

        if (key === '/') {
            event.preventDefault();
            wbglFocusSearchInput();
            return;
        }

        if (key === 'g') {
            wbglNavigateTo('/index.php');
            return;
        }
        if (key === 'b') {
            if (wbglCanAccessBatchSurfaces()) {
                wbglNavigateTo('/views/batches.php');
            }
            return;
        }
        if (key === 's') {
            wbglNavigateTo('/views/settings.php');
            return;
        }
        if (key === 't') {
            wbglNavigateTo('/views/statistics.php');
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    wbglBindGlobalShortcuts();

    const fileInput = document.getElementById('import-file-input');
    if (fileInput) {
        fileInput.addEventListener('change', async (e) => {
            if (e.target.files.length > 0) {
                const formData = new FormData();
                formData.append('file', e.target.files[0]);

                try {
                    const res = await fetch('/api/import.php', { method: 'POST', body: formData });
                    if (res.ok) {
                        const txt = await res.text();
                        try {
                            const json = JSON.parse(txt);
                            if (json.success || json.status === 'success') {
                                showToast(wbglT('common.ui.import_successful', ''), 'success');
                                setTimeout(() => window.location.reload(), 1000); // Wait for toast
                            } else {
                                showToast(wbglT('common.ui.import_failed', '') + ' ' + (json.message || txt), 'error');
                            }
                        } catch (e) {
                            window.location.reload();
                        }
                    } else {
                        showToast(wbglT('common.ui.upload_failed', '') + ' ' + res.status, 'error');
                    }
                } catch (e) {
                    showToast(wbglT('messages.records.error.network', ''), 'error');
                }
            }
        });
    }

    // --- Workflow Action Handlers (advance/reject) ---
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-action="workflow-advance"], [data-action="workflow-reject"]');
        if (!btn) return;
        const action = btn.getAttribute('data-action');

        const guaranteeId = document.querySelector('[data-record-id]')?.dataset.recordId;
        if (!guaranteeId) {
            showToast(wbglT('messages.records.error.no_record', ''), 'error');
            return;
        }

        let requestBody = { guarantee_id: guaranteeId };
        let endpoint = '/api/workflow-advance.php';
        if (action === 'workflow-reject') {
            const reasonInput = await wbglDialogPrompt({
                title: wbglT('index.workflow.reject.title', 'سبب الرفض'),
                message: wbglT('index.workflow.reject.prompt', ''),
                kind: wbglT('index.workflow.reject.label', 'رفض'),
                placeholder: wbglT('index.workflow.reject.placeholder', 'اكتب سبب الرفض'),
                required: true,
                requiredMessage: wbglT('index.workflow.reject.reason_required', ''),
                confirmText: wbglT('index.workflow.reject.confirm_button', 'تأكيد الرفض'),
                cancelText: wbglT('common.dialog.cancel', 'إلغاء'),
                tone: 'warning',
            });
            const reason = (reasonInput || '').trim();
            if (!reason) {
                showToast(wbglT('index.workflow.reject.reason_required', ''), 'warning');
                return;
            }
            endpoint = '/api/workflow-reject.php';
            requestBody = { guarantee_id: guaranteeId, reason };
        }

        btn.disabled = true;
        const originalText = btn.textContent;
        btn.textContent = action === 'workflow-reject'
            ? wbglT('index.workflow.reject.in_progress', '')
            : wbglT('index.workflow.execute_next_step', '');

        let navigated = false;
        const safeReload = (delayMs = 0) => {
            if (navigated) return;
            navigated = true;
            const run = () => location.reload();
            if (delayMs > 0) {
                setTimeout(run, delayMs);
            } else {
                run();
            }
        };

        // Safety net: if request hangs after applying backend action, don't leave button stuck forever.
        const hangFallbackTimer = setTimeout(() => {
            if (btn.disabled && !navigated) {
                showToast(
                    wbglT('index.workflow.refreshing_after_action', 'تم استلام الطلب. جاري تحديث الصفحة...'),
                    'info'
                );
                safeReload(80);
            }
        }, 9000);

        let requestTimeoutId = null;
        try {
            const abortController = new AbortController();
            requestTimeoutId = setTimeout(() => abortController.abort(), 12000);

            const response = await fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(requestBody),
                signal: abortController.signal
            });

            if (requestTimeoutId) {
                clearTimeout(requestTimeoutId);
                requestTimeoutId = null;
            }

            const rawResponse = await response.text();
            let data = null;
            try {
                data = rawResponse ? JSON.parse(rawResponse) : {};
            } catch (parseErr) {
                data = {
                    success: response.ok,
                    message: '',
                    error: rawResponse || ''
                };
            }

            if (data && data.success) {
                showToast(
                    data.message || wbglT('index.workflow.advance_success', 'تم تنفيذ الإجراء بنجاح.'),
                    'success'
                );

                // Skip timeline refresh here: workflow success immediately navigates/reloads.
                // Calling loadCurrentState() in this transition window can hit 403 for roles
                // that just lost access to the current record after stage advancement.
                clearTimeout(hangFallbackTimer);
                safeReload(450);
                return;
            }

            showToast(
                wbglT('messages.records.error.prefix', '') + (data?.error || data?.message || wbglT('messages.error.unknown', '')),
                'error'
            );
            btn.disabled = false;
            btn.textContent = originalText;
        } catch (err) {
            if (requestTimeoutId) {
                clearTimeout(requestTimeoutId);
                requestTimeoutId = null;
            }

            const isAbort = err && (err.name === 'AbortError' || String(err.message || '').toLowerCase().includes('abort'));
            if (isAbort) {
                showToast(
                    wbglT('index.workflow.request_timeout_refresh', 'تأخر الرد. جاري تحديث الصفحة للتحقق من الحالة...'),
                    'warning'
                );
                clearTimeout(hangFallbackTimer);
                safeReload(120);
                return;
            }

            console.error('WORKFLOW_ERROR', err);
            showToast(wbglT('messages.records.error.network', ''), 'error');
            btn.disabled = false;
            btn.textContent = originalText;
        } finally {
            clearTimeout(hangFallbackTimer);
        }
    });

});
