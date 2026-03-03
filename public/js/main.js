
// Toast Notification Helper (Unified)
window.Toast = {
    show(message, type = 'info', duration = 3500) {
        let container = document.getElementById('toast-container');
        if (!container) {
            const isRtl = (document.documentElement.getAttribute('dir') || 'rtl') === 'rtl';
            container = document.createElement('div');
            container.id = 'toast-container';
            container.className = `toast-container ${isRtl ? '' : 'toast-container--ltr'}`.trim();
            container.setAttribute('dir', isRtl ? 'rtl' : 'ltr');
            document.body.appendChild(container);
        }

        const icons = {
            success: 'OK',
            error: 'ERR',
            warning: 'WARN',
            info: 'INFO'
        };

        const toast = document.createElement('div');
        toast.setAttribute('role', 'status');
        toast.setAttribute('aria-live', 'polite');
        toast.className = `toast toast--inline toast-${type || 'info'}`;

        const icon = icons[type] || icons.info;
        toast.textContent = `${icon}: ${message}`;

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
window.showToast = window.Toast.show;

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

    modal.innerHTML = `
        <div class="wbgl-shortcuts-dialog">
            <div class="wbgl-shortcuts-dialog__header">
                <h3 class="wbgl-shortcuts-dialog__title">${wbglT('shortcuts.title', '')}</h3>
                <button type="button" id="wbgl-shortcuts-close" class="wbgl-shortcuts-dialog__close">${wbglT('shortcuts.close', '')}</button>
            </div>
            <div class="wbgl-shortcuts-grid">
                <code>G</code><span>${wbglT('shortcuts.open_main', '')}</span>
                <code>B</code><span>${wbglT('shortcuts.open_batches', '')}</span>
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
            wbglNavigateTo('/views/batches.php');
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

    // --- Phase 3: Workflow Action Handler ---
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-action="workflow-advance"]');
        if (!btn) return;

        const guaranteeId = document.querySelector('[data-record-id]')?.dataset.recordId;
        if (!guaranteeId) {
            showToast(wbglT('messages.records.error.no_record', ''), 'error');
            return;
        }

        btn.disabled = true;
        btn.textContent = wbglT('index.workflow.execute_next_step', '');

        try {
            const response = await fetch('/api/workflow-advance.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ guarantee_id: guaranteeId })
            });

            const data = await response.json();

            if (data.success) {
                showToast(data.message, 'success');

                // Refresh the current state to reflect changes (badge, button, timeline)
                if (window.timelineController) {
                    await window.timelineController.loadCurrentState();

                    // Also refresh the timeline area to show the new event
                    // Assuming there is a global way to refresh the sidebar
                    // If not, a simple reload is a safe fallback
                    setTimeout(() => location.reload(), 800);
                } else {
                    location.reload();
                }
                } else {
                showToast(wbglT('messages.records.error.prefix', '') + (data.error || data.message || wbglT('messages.error.unknown', '')), 'error');
                btn.disabled = false;
                btn.textContent = wbglT('index.workflow.execute_next_step', '');
            }
        } catch (err) {
            console.error('WORKFLOW_ERROR', err);
            showToast(wbglT('messages.records.error.network', ''), 'error');
            btn.disabled = false;
            btn.textContent = wbglT('index.workflow.execute_next_step', '');
        }
    });

});
