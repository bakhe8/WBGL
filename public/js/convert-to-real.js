/**
 * Convert test guarantee to real guarantee
 */
async function convertToReal(guaranteeId) {
    if (!confirm('هل تريد تحويل هذا الضمان من تجريبي إلى حقيقي؟\n\nملاحظة: سيبدأ التأثير على الإحصائيات ونظام التعلم.')) {
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
            alert('✅ تم التحويل بنجاح!');
            window.location.reload();
        } else {
            alert('❌ فشل التحويل: ' + (result.error || 'خطأ غير معروف'));
        }
    } catch (error) {
        console.error('Error converting to real:', error);
        alert('❌ حدث خطأ في الاتصال');
    }
}
