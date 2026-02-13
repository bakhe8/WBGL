/**
 * Modal Handlers for Input Actions
 * Handles: Manual Entry, Smart Paste, Import Excel
 */

// دالة لفتح modal الإدخال اليدوي
function showManualInput() {
    const modal = document.getElementById('manualEntryModal');
    if (modal) {
        modal.style.display = 'flex';
        document.getElementById('manualSupplier')?.focus();
    }
}

// دالة لفتح modal اللصق الذكي
function showPasteModal() {
    const modal = document.getElementById('smartPasteModal');
    if (modal) {
        modal.style.display = 'flex';
        document.getElementById('smartPasteInput')?.focus();
    }
}

// دالة لفتح صفحة الاستيراد
function showImportModal() {
    // Trigger hidden file input
    const fileInput = document.getElementById('hiddenFileInput');
    if (fileInput) {
        fileInput.click();
    } else {
        // Error: File input not found
        console.error('File input element #hiddenFileInput not found');
        if (typeof showToast === 'function') {
            showToast('عفواً، خاصية الاستيراد غير متاحة حالياً', 'error');
        }
    }
}

// دالة لإغلاق جميع الـ modals
function closeAllModals() {
    const modals = ['manualEntryModal', 'smartPasteModal'];
    modals.forEach(id => {
        const modal = document.getElementById(id);
        if (modal) modal.style.display = 'none';
    });
}

// دالة لمعالجة الإدخال اليدوي
async function submitManualEntry() {
    const supplier = document.getElementById('manualSupplier')?.value;
    const bank = document.getElementById('manualBank')?.value;
    const guarantee = document.getElementById('manualGuarantee')?.value;
    const contract = document.getElementById('manualContract')?.value;
    const amount = document.getElementById('manualAmount')?.value;

    if (!supplier || !bank || !guarantee || !contract || !amount) {
        showToast('يرجى ملء جميع الحقول المطلوبة', 'error');
        return;
    }

    const payload = {
        supplier,
        bank,
        guarantee_number: guarantee,
        contract_number: contract,
        amount: parseFloat(amount),
        expiry_date: document.getElementById('manualExpiry')?.value,
        type: document.getElementById('manualType')?.value,
        issue_date: document.getElementById('manualIssue')?.value,
        comment: document.getElementById('manualComment')?.value,
        related_to: document.querySelector('input[name="relatedTo"]:checked')?.value || 'contract', // 🔥 NEW
        // ✅ NEW: Test Data Isolation (Phase 1)
        is_test_data: document.getElementById('manualIsTestData')?.checked ? 1 : 0,
        test_batch_id: document.getElementById('manualTestBatchId')?.value,
        test_note: document.getElementById('manualTestNote')?.value
    };

    try {
        const response = await fetch('/api/create-guarantee.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        const data = await response.json();
        if (data.success) {
            showToast('تم إضافة الضمان بنجاح', 'success');
            setTimeout(() => window.location.reload(), 1000);
        } else {
            showToast('خطأ: ' + (data.error || 'فشل الحفظ'), 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast('حدث خطأ في الاتصال', 'error');
    }
}

// دالة لمعالجة البيانات الملصوقة
async function parsePasteData() {
    const text = document.getElementById('smartPasteInput')?.value;

    if (!text || !text.trim()) {
        showToast('يرجى لصق النص أولاً', 'error');
        return;
    }

    // Show loading state
    const btnProcess = document.getElementById('btnProcessPaste');
    const originalText = btnProcess.innerHTML;
    btnProcess.innerHTML = '⏳ جاري التحليل...';
    btnProcess.disabled = true;

    // Hide previous results
    document.getElementById('extractionPreview').style.display = 'none';
    document.getElementById('smartPasteError').style.display = 'none';

    try {
        // Call the parse API (now with built-in confidence support)
        const response = await fetch('/api/parse-paste.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                text: text,
                is_test_data: isTestData,
                test_batch_id: testBatchId,
                test_note: testNote
            })
        });

        const data = await response.json();

        // Reset button
        btnProcess.innerHTML = originalText;
        btnProcess.disabled = false;

        if (data.success) {
            // Check if multi-guarantee import
            if (data.multi && data.results) {
                // Multi-guarantee success!
                const previewDiv = document.getElementById('extractionPreview');
                const fieldsDiv = document.getElementById('extractionFields');

                let multiHTML = `
                    <div style="grid-column: 1 / -1; padding: 10px 14px; background: #dbeafe; border: 1px solid #60a5fa; border-radius: 6px; margin-bottom: 10px;">
                        <div style="color: #1e40af; font-size: 14px; font-weight: 700;">
                            🎯 تم استيراد ${data.count} ضمان بنجاح
                        </div>
                    </div>
                `;

                data.results.forEach((result, index) => {
                    if (result.failed) {
                        multiHTML += `
                            <div style="grid-column: 1 / -1; padding: 8px 12px; background: #fee2e2; border: 1px solid #fca5a5; border-radius: 6px;">
                                <div style="color: #991b1b; font-size: 12px;">❌ ${result.guarantee_number}: ${result.error}</div>
                            </div>
                        `;
                    } else {
                        multiHTML += `
                            <div style="grid-column: 1 / -1; padding: 8px 12px; background: white; border: 1px solid #d1fae5; border-radius: 6px;">
                                <div style="color: #10b981; font-size: 12px; font-weight: 600;">✅ ${result.guarantee_number}</div>
                                <div style="color: #6b7280; font-size: 11px; margin-top: 2px;">${result.supplier || '—'} | ${result.amount ? result.amount.toLocaleString() + ' ر.س' : '—'}</div>
                            </div>
                        `;
                    }
                });

                fieldsDiv.innerHTML = multiHTML;
                previewDiv.style.display = 'block';

                showToast(data.message, 'success');
                setTimeout(() => window.location.reload(), 2000);
                return;
            }

            // Single guarantee (existing logic)
            const previewDiv = document.getElementById('extractionPreview');
            const fieldsDiv = document.getElementById('extractionFields');

            const fieldLabels = {
                'guarantee_number': 'رقم الضمان',
                'supplier': 'المورد',
                'bank': 'البنك',
                'amount': 'المبلغ',
                'expiry_date': 'تاريخ الانتهاء',
                'contract_number': 'رقم العقد',
                'issue_date': 'تاريخ الإصدار',
                'type': 'النوع'
            };

            let fieldsHTML = '';
            const hasConfidence = data.confidence && Object.keys(data.confidence).length > 0;

            // Show overall confidence if available
            if (hasConfidence && data.overall_confidence) {
                const overallDiv = document.getElementById('overallConfidence');
                const level = data.overall_confidence >= 90 ? '✅ عالية' :
                    data.overall_confidence >= 70 ? '⚠️ متوسطة' : '❌ منخفضة';
                const color = data.overall_confidence >= 90 ? '#15803d' :
                    data.overall_confidence >= 70 ? '#d97706' : '#dc2626';
                overallDiv.innerHTML = `<span style="color: ${color}">الثقة الإجمالية: ${level} (${data.overall_confidence}%)</span>`;
            }

            for (const [key, label] of Object.entries(fieldLabels)) {
                const value = data.extracted[key];
                if (value) {
                    // Get confidence data for this field
                    const conf = hasConfidence ? data.confidence[key] : null;
                    const confScore = conf ? conf.confidence : null;
                    const confReason = conf ? conf.reason : '';

                    // Determine confidence badge
                    let confBadge = '';
                    let bgColor = 'white';
                    let borderColor = '#d1fae5';

                    if (conf) {
                        if (confScore >= 90) {
                            confBadge = `<span style="color: #15803d; font-size: 11px;">✓ ${confScore}%</span>`;
                            bgColor = '#f0fdf4';
                            borderColor = '#86efac';
                        } else if (confScore >= 70) {
                            confBadge = `<span style="color: #d97706; font-size: 11px;">⚠ ${confScore}%</span>`;
                            bgColor = '#fef3c7';
                            borderColor = '#fbbf24';
                        } else {
                            confBadge = `<span style="color: #dc2626; font-size: 11px;">⚠ ${confScore}% - ${confReason}</span>`;
                            bgColor = '#fee2e2';
                            borderColor = '#fca5a5';
                        }
                    }

                    fieldsHTML += `
                        <div style="padding: 8px 12px; background: ${bgColor}; border-radius: 6px; border: 1px solid ${borderColor};">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                <div style="color: #6b7280; font-size: 11px; font-weight: 600;">${label}</div>
                                ${confBadge}
                            </div>
                            <div style="color: #1f2937; font-weight: 600; font-size: 14px;">${value}</div>
                            ${conf && conf.reason ? `<div style="color: #6b7280; font-size: 10px; margin-top: 2px;">${conf.reason}</div>` : ''}
                        </div>
                    `;
                }
            }

            fieldsDiv.innerHTML = fieldsHTML;
            previewDiv.style.display = 'block';

            // Success!
            showToast(data.message || 'تم استخراج البيانات بنجاح!', 'success');
            setTimeout(() => window.location.href = '?id=' + data.id, 1500);

        } else {
            // Show detailed error
            const errorDiv = document.getElementById('smartPasteError');
            const errorMsg = document.getElementById('errorMessage');
            const missingList = document.getElementById('missingFieldsList');

            errorMsg.textContent = data.error || 'فشل في تحليل النص';

            // Show what was extracted and what is missing
            if (data.field_status) {
                let statusHTML = '<div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #fca5a5;"><strong>حالة الحقول:</strong><div style="margin-top: 8px; display: grid; grid-template-columns: repeat(2, 1fr); gap: 6px;">';

                const fieldLabels = {
                    'guarantee_number': 'رقم الضمان',
                    'supplier': 'المورد',
                    'bank': 'البنك',
                    'amount': 'المبلغ',
                    'expiry_date': 'تاريخ الانتهاء',
                    'contract_number': 'رقم العقد'
                };

                for (const [key, label] of Object.entries(fieldLabels)) {
                    const status = data.field_status[key] || '❌';
                    const value = data.extracted?.[key] || '—';
                    const bgColor = status === '✅' ? '#f0fdf4' : '#fef2f2';
                    const borderColor = status === '✅' ? '#86efac' : '#fca5a5';

                    statusHTML += `
                        <div style="padding: 6px 8px; background: ${bgColor}; border: 1px solid ${borderColor}; border-radius: 4px; font-size: 12px;">
                            ${status} ${label}: ${value}
                        </div>
                    `;
                }

                statusHTML += '</div></div>';
                missingList.innerHTML = statusHTML;
            }

            errorDiv.style.display = 'block';
            showToast('فشل الاستخراج - يرجى مراجعة التفاصيل', 'error');
        }
    } catch (error) {
        btnProcess.innerHTML = originalText;
        btnProcess.disabled = false;
        console.error('Error:', error);
        showToast('حدث خطأ في الاتصال', 'error');
    }
}

// دالة لفتح modal استيراد Excel
function showImportModal() {
    const modal = document.getElementById('excelImportModal');
    if (modal) {
        modal.style.display = 'flex';
    }
}

// Placeholder for uploadExcelFile function (to be defined elsewhere or added)
async function uploadExcelFile() {
    const fileInput = document.getElementById('excelFileInput');
    const file = fileInput.files[0];

    if (!file) {
        showToast('يرجى اختيار ملف Excel أولاً', 'error');
        return;
    }

    // Show loading indicator
    const loadingMsg = document.createElement('div');
    loadingMsg.id = 'uploadProgress';
    loadingMsg.style.cssText = 'position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 24px 48px; border-radius: 12px; box-shadow: 0 25px 50px rgba(0,0,0,0.3); z-index: 10000; text-align: center;';
    loadingMsg.innerHTML = '<div style="font-size: 18px; font-weight: 700; color: #1f2937;">جاري تحميل الملف...</div><div style="margin-top: 12px; font-size: 14px; color: #6b7280;">' + file.name + '</div>';
    loadingMsg.innerHTML = '<div style="font-size: 18px; font-weight: 700; color: #1f2937;">جاري تحميل الملف...</div><div style="margin-top: 12px; font-size: 14px; color: #6b7280;">' + file.name + '</div>';
    document.body.appendChild(loadingMsg);

    // Create FormData
    const formData = new FormData();
    formData.append('file', file);

    // ✅ NEW: Add test data parameters (Phase 1)
    const isTestData = document.getElementById('excelIsTestData')?.checked;
    if (isTestData) {
        formData.append('is_test_data', '1');
        formData.append('test_batch_id', document.getElementById('excelTestBatchId')?.value || '');
        formData.append('test_note', document.getElementById('excelTestNote')?.value || '');
    }

    // Close modal before upload
    closeAllModals();

    try {
        const response = await fetch('/api/import.php', { // Assuming the same import endpoint
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        // Remove loading
        loadingMsg.remove();

        if (data.success) {
            const importedCount = data.data?.imported || data.imported || 0;
            showToast(`تم الاستيراد بنجاح!\n${importedCount} سجل تم إضافته.`, 'success');
            setTimeout(() => window.location.reload(), 1500);
        } else {
            showToast('خطأ: ' + (data.error || 'فشل الاستيراد'), 'error');
        }
    } catch (error) {
        loadingMsg.remove();
        console.error('Error:', error);
        showToast('حدث خطأ في الاتصال', 'error');
    }


    // Reset form
    fileInput.value = '';
    document.getElementById('selectedFileName').textContent = 'لم يتم اختيار ملف';
    document.getElementById('excelIsTestData').checked = false;
    document.getElementById('excelTestFields').style.display = 'none';
}

// إعداد الـ event listeners عند تحميل الصفحة
document.addEventListener('DOMContentLoaded', function () {
    // Manual Entry Modal handlers
    const btnCloseManual = document.getElementById('btnCloseManualEntry');
    const btnCancelManual = document.getElementById('btnCancelManualEntry');
    const btnSaveManual = document.getElementById('btnSaveManualEntry');

    if (btnCloseManual) {
        btnCloseManual.addEventListener('click', closeAllModals);
    }

    if (btnCancelManual) {
        btnCancelManual.addEventListener('click', closeAllModals);
    }

    if (btnSaveManual) {
        btnSaveManual.addEventListener('click', submitManualEntry);
    }

    // Paste Modal handlers
    const btnClosePaste = document.getElementById('btnClosePasteModal');
    const btnCancelPaste = document.getElementById('btnCancelPaste');
    const btnProcessPaste = document.getElementById('btnProcessPaste');

    if (btnClosePaste) {
        btnClosePaste.addEventListener('click', closeAllModals);
    }

    if (btnCancelPaste) {
        btnCancelPaste.addEventListener('click', closeAllModals);
    }

    if (btnProcessPaste) {
        btnProcessPaste.addEventListener('click', parsePasteData);
    }

    // ✅ NEW: Excel Import Modal handlers
    const btnCloseExcel = document.getElementById('btnCloseExcelModal');
    const btnCancelExcel = document.getElementById('btnCancelExcel');
    const btnUploadExcel = document.getElementById('btnUploadExcel');
    const excelFileInput = document.getElementById('excelFileInput');

    if (btnCloseExcel) {
        btnCloseExcel.addEventListener('click', closeAllModals);
    }

    if (btnCancelExcel) {
        btnCancelExcel.addEventListener('click', closeAllModals);
    }

    if (excelFileInput) {
        excelFileInput.addEventListener('change', function () {
            const fileName = this.files[0]?.name || 'لم يتم اختيار ملف';
            document.getElementById('selectedFileName').textContent = fileName;
            document.getElementById('btnUploadExcel').disabled = !this.files[0];
        });
    }

    if (btnUploadExcel) {
        btnUploadExcel.addEventListener('click', uploadExcelFile);
    }

    // Close modals on ESC key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeAllModals();
        }
    });

    // Add modal functions to records controller
    if (window.recordsController) {
        window.recordsController.showManualInput = showManualInput;
        window.recordsController.showPasteModal = showPasteModal;
        window.recordsController.showImportModal = showImportModal;
    }

    // Handle file selection
    const fileInput = document.getElementById('hiddenFileInput');
    if (fileInput) {
        fileInput.addEventListener('change', async function (e) {
            const file = e.target.files[0];
            if (!file) return;

            // Show loading indicator
            const loadingMsg = document.createElement('div');
            loadingMsg.id = 'uploadProgress';
            loadingMsg.style.cssText = 'position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 24px 48px; border-radius: 12px; box-shadow: 0 25px 50px rgba(0,0,0,0.3); z-index: 10000; text-align: center;';
            loadingMsg.innerHTML = '<div style="font-size: 18px; font-weight: 700; color: #1f2937;">جاري تحميل الملف...</div><div style="margin-top: 12px; font-size: 14px; color: #6b7280;">' + file.name + '</div>';
            document.body.appendChild(loadingMsg);

            // Create FormData
            const formData = new FormData();
            formData.append('file', file);

            try {
                const response = await fetch('/api/import.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                // Remove loading
                loadingMsg.remove();

                if (data.success) {
                    const importedCount = data.data?.imported || data.imported || 0;
                    showToast(`تم الاستيراد بنجاح!\n${importedCount} سجل تم إضافته.`, 'success');
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    showToast('خطأ: ' + (data.error || 'فشل الاستيراد'), 'error');
                }
            } catch (error) {
                loadingMsg.remove();
                console.error('Error:', error);
                showToast('حدث خطأ في الاتصال', 'error');
            }

            // Reset input for next time
            e.target.value = '';
        });
    }
});
