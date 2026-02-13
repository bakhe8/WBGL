
// Toast Notification Helper (Unified)
window.Toast = {
    show(message, type = 'info', duration = 3500) {
        let container = document.getElementById('toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toast-container';
            container.style.cssText = [
                'position: fixed',
                'top: 20px',
                'right: 20px',
                'z-index: 9999',
                'display: flex',
                'flex-direction: column',
                'gap: 10px',
                'direction: rtl'
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
            `border-right: 4px solid ${borderColor}`,
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

document.addEventListener('DOMContentLoaded', () => {
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

});
