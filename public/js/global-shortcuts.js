(function () {
    function canHandle(event) {
        if (event.ctrlKey || event.metaKey || event.altKey) return false;
        const target = event.target;
        if (!target) return true;
        const tag = (target.tagName || '').toUpperCase();
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return false;
        if (target.isContentEditable) return false;
        return true;
    }

    function focusSearch() {
        const input = document.querySelector('.search-input, input[name="search"], input[type="search"]');
        if (!input || typeof input.focus !== 'function') return;
        input.focus();
        if (typeof input.select === 'function') {
            input.select();
        }
    }

    function navigate(path) {
        if (!path) return;
        window.location.href = path;
    }

    function t(key, fallback) {
        if (window.WBGLI18n && typeof window.WBGLI18n.t === 'function') {
            return window.WBGLI18n.t(key, fallback);
        }
        return fallback;
    }

    function ensureModal() {
        let modal = document.getElementById('wbgl-shortcuts-modal');
        if (modal) return modal;

        modal = document.createElement('div');
        modal.id = 'wbgl-shortcuts-modal';
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.style.cssText = 'display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:11000;align-items:center;justify-content:center;padding:16px;';

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

    function toggleModal() {
        const modal = ensureModal();
        modal.style.display = modal.style.display === 'flex' ? 'none' : 'flex';
    }

    function bind() {
        if (window.__wbglShortcutsBound) return;
        window.__wbglShortcutsBound = true;

        document.addEventListener('keydown', (event) => {
            if (!canHandle(event)) return;
            const key = (event.key || '').toLowerCase();

            if (key === '?') {
                event.preventDefault();
                toggleModal();
                return;
            }
            if (key === '/') {
                event.preventDefault();
                focusSearch();
                return;
            }
            if (key === 'g') return navigate('/index.php');
            if (key === 'b') return navigate('/views/batches.php');
            if (key === 's') return navigate('/views/settings.php');
            if (key === 't') return navigate('/views/statistics.php');
        });
    }

    document.addEventListener('DOMContentLoaded', bind);
})();

