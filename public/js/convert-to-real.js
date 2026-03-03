/**
 * Convert test guarantee to real guarantee
 */
function t(key, fallback, params) {
    if (window.WBGLI18n && typeof window.WBGLI18n.t === 'function') {
        return window.WBGLI18n.t(key, fallback, params);
    }
    return fallback || key;
}

async function convertToReal(guaranteeId) {
    const confirmMessage = t('convert_to_real.confirm.message', 'convert_to_real.confirm.message');
    if (!confirm(confirmMessage)) {
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
            alert(t('convert_to_real.success', 'convert_to_real.success'));
            window.location.reload();
        } else {
            const errorMessage = result.error || t('messages.error.unknown', 'messages.error.unknown');
            alert(t('convert_to_real.failure_prefix', 'convert_to_real.failure_prefix') + errorMessage);
        }
    } catch (error) {
        console.error('CONVERT_TO_REAL_ERROR', error);
        alert(t('convert_to_real.network_error', 'convert_to_real.network_error'));
    }
}
