
// Toast Notification Helper (Unified)
window.Toast = {
    show(message, type = 'info', duration = 3500) {
        let container = document.getElementById('toast-container');
        if (!container) {
            const isRtl = (document.documentElement.getAttribute('dir') || 'rtl') === 'rtl';
            container = document.createElement('div');
            container.id = 'toast-container';
            container.style.cssText = [
                'position: fixed',
                'top: 20px',
                isRtl ? 'right: 20px' : 'left: 20px',
                'z-index: 9999',
                'display: flex',
                'flex-direction: column',
                'gap: 10px',
                `direction: ${isRtl ? 'rtl' : 'ltr'}`
            ].join(';');
            document.body.appendChild(container);
        }

        const colors = {
            success: '#10b981',
            error: '#ef4444',
            warning: '#f59e0b',
            info: '#3b82f6'
        };
        const icons = {
            success: 'OK',
            error: 'ERR',
            warning: 'WARN',
            info: 'INFO'
        };

        const toast = document.createElement('div');
        const borderColor = colors[type] || colors.info;

        toast.setAttribute('role', 'status');
        toast.setAttribute('aria-live', 'polite');
        toast.style.cssText = [
            'background: #ffffff',
            'color: #111827',
            'padding: 12px 16px',
            'border-radius: 10px',
            'box-shadow: 0 10px 20px rgba(0,0,0,0.12)',
            `border-inline-start: 4px solid ${borderColor}`,
            'min-width: 240px',
            'max-width: 420px',
            'font-family: inherit',
            'font-size: 14px',
            'display: flex',
            'align-items: center',
            'gap: 8px',
            'opacity: 0',
            'transform: translateY(-8px)',
            'transition: opacity 0.2s ease, transform 0.2s ease'
        ].join(';');

        const icon = icons[type] || icons.info;
        toast.textContent = `${icon}: ${message}`;

        while (container.children.length >= 4) {
            container.removeChild(container.firstChild);
        }

        container.appendChild(toast);

        requestAnimationFrame(() => {
            toast.style.opacity = '1';
            toast.style.transform = 'translateY(0)';
        });

        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(-8px)';
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

    const t = (key, fallback) => window.WBGLI18n ? window.WBGLI18n.t(key, fallback) : fallback;
    modal = document.createElement('div');
    modal.id = 'wbgl-shortcuts-modal';
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.style.cssText = [
        'display:none',
        'position:fixed',
        'inset:0',
        'background:rgba(0,0,0,0.45)',
        'z-index:11000',
        'align-items:center',
        'justify-content:center',
        'padding:16px'
    ].join(';');

    modal.innerHTML = `
        <div style="background:#fff;max-width:520px;width:100%;border-radius:14px;padding:20px;box-shadow:0 25px 50px rgba(0,0,0,0.25);">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                <h3 style="margin:0;font-size:18px;">${t('shortcuts.title', 'اختصارات النظام')}</h3>
                <button type="button" id="wbgl-shortcuts-close" style="border:none;background:#f1f5f9;border-radius:8px;padding:6px 10px;cursor:pointer;">${t('shortcuts.close', 'إغلاق')}</button>
            </div>
            <div style="display:grid;grid-template-columns:120px 1fr;gap:8px 12px;font-size:14px;">
                <code>G</code><span>${t('shortcuts.open_main', 'الانتقال إلى الرئيسية')}</span>
                <code>B</code><span>${t('shortcuts.open_batches', 'فتح صفحة الدفعات')}</span>
                <code>S</code><span>${t('shortcuts.open_settings', 'فتح الإعدادات')}</span>
                <code>T</code><span>${t('shortcuts.open_stats', 'فتح الإحصائيات')}</span>
                <code>/</code><span>${t('shortcuts.focus_search', 'تركيز البحث')}</span>
                <code>?</code><span>${t('shortcuts.toggle_help', 'فتح/إغلاق نافذة الاختصارات')}</span>
            </div>
        </div>
    `;

    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            modal.style.display = 'none';
        }
    });

    document.body.appendChild(modal);

    const closeBtn = document.getElementById('wbgl-shortcuts-close');
    if (closeBtn) {
        closeBtn.addEventListener('click', () => {
            modal.style.display = 'none';
        });
    }

    return modal;
}

function wbglToggleShortcutsModal() {
    const modal = wbglEnsureShortcutModal();
    modal.style.display = modal.style.display === 'flex' ? 'none' : 'flex';
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
                                showToast('Import successful!', 'success');
                                setTimeout(() => window.location.reload(), 1000); // Wait for toast
                            } else {
                                showToast('Import failed: ' + (json.message || txt), 'error');
                            }
                        } catch (e) {
                            window.location.reload();
                        }
                    } else {
                        showToast('Upload failed: ' + res.status, 'error');
                    }
                } catch (e) {
                    showToast('Network error during upload', 'error');
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
            showToast('معرف الضمان غير موجود', 'error');
            return;
        }

        btn.disabled = true;
        btn.innerHTML = '... جاري التنفيذ';

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
                showToast('خطأ: ' + (data.error || data.message || 'فشل تنفيذ الإجراء'), 'error');
                btn.disabled = false;
                btn.innerHTML = '⚡ تنفيذ الإجراء';
            }
        } catch (err) {
            console.error('Workflow error:', err);
            showToast('حدث خطأ في الشبكة', 'error');
            btn.disabled = false;
            btn.innerHTML = '⚡ تنفيذ الإجراء';
        }
    });

});
