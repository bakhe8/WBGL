/**
 * Convert test guarantee to real guarantee
 */
function t(key, fallback, params) {
    if (window.WBGLI18n && typeof window.WBGLI18n.t === 'function') {
        return window.WBGLI18n.t(key, fallback, params);
    }
    return fallback || key;
}

async function wbglDialogConfirm(message, options) {
    if (window.WBGLDialog && typeof window.WBGLDialog.confirm === 'function') {
        return window.WBGLDialog.confirm(message, options || {});
    }
    console.error('WBGLDialog.confirm is not available');
    return false;
}

async function wbglDialogAlert(message, options) {
    if (window.WBGLDialog && typeof window.WBGLDialog.alert === 'function') {
        await window.WBGLDialog.alert(message, options || {});
        return;
    }
    if (typeof window.showToast === 'function') {
        window.showToast(String(message || ''), 'error');
        return;
    }
    console.error('WBGLDialog.alert is not available', message);
}

async function convertToReal(guaranteeId) {
    const confirmMessage = t('convert_to_real.confirm.message', 'convert_to_real.confirm.message');
    const confirmed = await wbglDialogConfirm(confirmMessage, {
        title: t('convert_to_real.confirm.title', 'تأكيد تحويل السجل'),
        confirmText: t('convert_to_real.confirm.confirm_button', 'تحويل إلى حقيقي'),
        cancelText: t('common.dialog.cancel', 'إلغاء'),
        tone: 'danger',
    });
    if (!confirmed) {
        return;
    }
    
    try {
        const response = await fetch('/api/convert-to-real.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ guarantee_id: guaranteeId })
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Reload page to show updated state
            await wbglDialogAlert(t('convert_to_real.success', 'convert_to_real.success'), {
                title: t('common.dialog.notice_title', 'تنبيه'),
                confirmText: t('common.dialog.ok', 'موافق'),
                tone: 'success',
            });
            window.location.reload();
        } else {
            const errorMessage = result.error || t('messages.error.unknown', 'messages.error.unknown');
            await wbglDialogAlert(t('convert_to_real.failure_prefix', 'convert_to_real.failure_prefix') + errorMessage, {
                title: t('common.dialog.error_title', 'خطأ'),
                confirmText: t('common.dialog.ok', 'موافق'),
                tone: 'danger',
            });
        }
    } catch (error) {
        console.error('CONVERT_TO_REAL_ERROR', error);
        await wbglDialogAlert(t('convert_to_real.network_error', 'convert_to_real.network_error'), {
            title: t('common.dialog.error_title', 'خطأ'),
            confirmText: t('common.dialog.ok', 'موافق'),
            tone: 'danger',
        });
    }
}
