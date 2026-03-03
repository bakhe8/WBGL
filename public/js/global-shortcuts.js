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
        modal.className = 'wbgl-shortcuts-overlay';
        modal.hidden = true;

        modal.innerHTML = `
            <div class="wbgl-shortcuts-dialog">
                <div class="wbgl-shortcuts-dialog__header">
                    <h3 class="wbgl-shortcuts-dialog__title">${t('shortcuts.title', 'shortcuts.title')}</h3>
                    <button type="button" id="wbgl-shortcuts-close" class="wbgl-shortcuts-dialog__close">${t('shortcuts.close', 'shortcuts.close')}</button>
                </div>
                <div class="wbgl-shortcuts-grid">
                    <code>G</code><span>${t('shortcuts.open_main', 'shortcuts.open_main')}</span>
                    <code>B</code><span>${t('shortcuts.open_batches', 'shortcuts.open_batches')}</span>
                    <code>S</code><span>${t('shortcuts.open_settings', 'shortcuts.open_settings')}</span>
                    <code>T</code><span>${t('shortcuts.open_stats', 'shortcuts.open_stats')}</span>
                    <code>/</code><span>${t('shortcuts.focus_search', 'shortcuts.focus_search')}</span>
                    <code>?</code><span>${t('shortcuts.toggle_help', 'shortcuts.toggle_help')}</span>
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

    function toggleModal() {
        const modal = ensureModal();
        modal.hidden = !modal.hidden;
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
