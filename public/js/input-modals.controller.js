/**
 * Modal Handlers for Input Actions
 * Handles: Manual Entry, Smart Paste, Import Excel
 */

const WBGL_HIDDEN_CLASS = 'wbgl-hidden';

function setModalOpen(modal, open) {
    if (!modal) {
        return;
    }

    modal.classList.toggle('is-open', Boolean(open));
    modal.setAttribute('aria-hidden', open ? 'false' : 'true');
}

function setHidden(element, hidden) {
    if (!element) {
        return;
    }
    element.classList.toggle(WBGL_HIDDEN_CLASS, Boolean(hidden));
}

function isModalOpen(modal) {
    return Boolean(modal) && modal.classList.contains('is-open');
}

function t(key, params) {
    if (window.WBGLI18n && typeof window.WBGLI18n.t === 'function') {
        return window.WBGLI18n.t(key, key, params || undefined);
    }

    let output = String(key || '');
    if (params && typeof params === 'object') {
        Object.keys(params).forEach((token) => {
            output = output.replace(new RegExp(`{{\\s*${token}\\s*}}`, 'g'), String(params[token]));
        });
    }
    return output;
}

function wbglNormalizeLocaleDigits(input) {
    const value = String(input || '');
    const arabicIndic = '٠١٢٣٤٥٦٧٨٩';
    const easternArabicIndic = '۰۱۲۳۴۵۶۷۸۹';

    let normalized = value;
    for (let i = 0; i < 10; i += 1) {
        normalized = normalized.replace(new RegExp(arabicIndic[i], 'g'), String(i));
        normalized = normalized.replace(new RegExp(easternArabicIndic[i], 'g'), String(i));
    }

    return normalized
        .replace(/\u066B/g, '.') // Arabic decimal separator
        .replace(/\u066C/g, ',') // Arabic thousands separator
        .replace(/\s+/g, '');
}

function wbglParseManualAmount(rawInput) {
    const normalized = wbglNormalizeLocaleDigits(rawInput).replace(/[^\d.,]/g, '');
    if (normalized === '') {
        return {
            rawInput: String(rawInput || ''),
            cleanedInput: '',
            typingDisplay: '',
            fixedDisplay: '',
            numericValue: null,
            valid: false
        };
    }

    const lastDot = normalized.lastIndexOf('.');
    const lastComma = normalized.lastIndexOf(',');
    let decimalIndex = -1;
    let treatAsDecimal = false;

    if (lastDot !== -1 && lastComma !== -1) {
        decimalIndex = Math.max(lastDot, lastComma);
        treatAsDecimal = true;
    } else if (lastDot !== -1) {
        decimalIndex = lastDot;
        treatAsDecimal = true;
    } else if (lastComma !== -1) {
        const commaCount = (normalized.match(/,/g) || []).length;
        const rightDigits = normalized.length - lastComma - 1;
        if (commaCount === 1 && rightDigits > 0 && rightDigits <= 2) {
            decimalIndex = lastComma;
            treatAsDecimal = true;
        }
    }

    let integerPart = '';
    let fractionPart = '';
    let hasTrailingDecimal = false;

    if (treatAsDecimal && decimalIndex >= 0) {
        integerPart = normalized.slice(0, decimalIndex).replace(/[.,]/g, '');
        const rightRaw = normalized.slice(decimalIndex + 1);
        hasTrailingDecimal = rightRaw.length === 0;
        fractionPart = rightRaw.replace(/[.,]/g, '').slice(0, 2);
    } else {
        integerPart = normalized.replace(/[.,]/g, '');
    }

    integerPart = integerPart.replace(/^0+(?=\d)/, '');
    if (integerPart === '') {
        integerPart = '0';
    }

    const groupedInteger = integerPart.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const typingDisplay = treatAsDecimal
        ? `${groupedInteger}.${hasTrailingDecimal ? '' : fractionPart}`
        : groupedInteger;

    const numericString = `${integerPart}${fractionPart !== '' ? `.${fractionPart}` : ''}`;
    const numericValue = Number(numericString);
    const valid = Number.isFinite(numericValue) && numericValue > 0;
    const fixedDisplay = Number.isFinite(numericValue)
        ? numericValue.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
        : '';

    return {
        rawInput: String(rawInput || ''),
        cleanedInput: normalized,
        typingDisplay,
        fixedDisplay,
        numericValue,
        valid
    };
}

function wbglRenderManualAmountPreview(parsedAmount) {
    const previewEl = document.getElementById('manualAmountPreview');
    if (!previewEl) {
        return;
    }

    const parsed = parsedAmount || wbglParseManualAmount('');
    if (!parsed.cleanedInput) {
        previewEl.textContent = '';
        previewEl.classList.remove('wbgl-helper-text--amount-error');
        return;
    }

    if (!parsed.valid) {
        previewEl.textContent = t('modals.manual_entry.helper.amount_invalid');
        previewEl.classList.add('wbgl-helper-text--amount-error');
        return;
    }

    previewEl.textContent = t('modals.manual_entry.helper.amount_normalized', {
        value: parsed.fixedDisplay,
        currency: t('modals.currency.sar')
    });
    previewEl.classList.remove('wbgl-helper-text--amount-error');
}

function initializeManualAmountFormatter() {
    const amountInput = document.getElementById('manualAmount');
    if (!amountInput || amountInput.dataset.wbglAmountInit === '1') {
        return;
    }

    amountInput.dataset.wbglAmountInit = '1';
    amountInput.dir = 'ltr';
    amountInput.inputMode = 'decimal';

    amountInput.addEventListener('input', () => {
        const parsed = wbglParseManualAmount(amountInput.value);
        amountInput.value = parsed.typingDisplay;
        wbglRenderManualAmountPreview(parsed);
    });

    amountInput.addEventListener('blur', () => {
        const parsed = wbglParseManualAmount(amountInput.value);
        if (!parsed.cleanedInput) {
            amountInput.value = '';
        } else if (parsed.valid) {
            amountInput.value = parsed.fixedDisplay;
        }
        wbglRenderManualAmountPreview(parsed);
    });
}

// دالة لفتح modal الإدخال اليدوي
function showManualInput() {
    const modal = document.getElementById('manualEntryModal');
    if (modal) {
        initializeManualAmountFormatter();
        setModalOpen(modal, true);
        document.getElementById('manualSupplier')?.focus();
    }
}

// دالة لفتح modal اللصق الذكي
function showPasteModal() {
    const modal = document.getElementById('smartPasteModal');
    if (modal) {
        setModalOpen(modal, true);
        document.getElementById('smartPasteInput')?.focus();
    }
}

// دالة لإغلاق جميع الـ modals
function closeAllModals() {
    const modals = ['manualEntryModal', 'smartPasteModal', 'excelImportModal'];
    modals.forEach(id => {
        const modal = document.getElementById(id);
        if (modal) {
            setModalOpen(modal, false);
        }
    });
}

function showSmartPasteErrorInline(message, detailsHtml = '') {
    const errorDiv = document.getElementById('smartPasteError');
    const errorMsg = document.getElementById('errorMessage');
    const missingList = document.getElementById('missingFieldsList');

    if (errorMsg) {
        errorMsg.textContent = message || t('modals.modal.txt_f2ca37e9');
    }

    if (missingList) {
        missingList.innerHTML = detailsHtml;
    }

    setHidden(errorDiv, false);
}

// دالة لمعالجة الإدخال اليدوي
async function submitManualEntry() {
    const supplier = document.getElementById('manualSupplier')?.value;
    const bank = document.getElementById('manualBank')?.value;
    const guarantee = document.getElementById('manualGuarantee')?.value;
    const contract = document.getElementById('manualContract')?.value;
    const amountInput = document.getElementById('manualAmount');
    const parsedAmount = wbglParseManualAmount(amountInput?.value || '');

    if (!parsedAmount.valid) {
        showToast(t('modals.manual_entry.helper.amount_invalid'), 'error');
        amountInput?.focus();
        return;
    }

    const relatedTo = Array.from(document.getElementsByName('relatedTo')).find((radio) => radio.checked)?.value || 'contract';
    const payload = {
        supplier,
        bank,
        guarantee_number: guarantee,
        contract_number: contract,
        amount: parsedAmount.numericValue.toFixed(2),
        expiry_date: document.getElementById('manualExpiry')?.value,
        type: document.getElementById('manualType')?.value,
        issue_date: document.getElementById('manualIssue')?.value,
        comment: document.getElementById('manualComment')?.value,
        related_to: relatedTo,
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
            showToast(t('modals.modal.txt_6e2806ef'), 'success');
            setTimeout(() => window.location.reload(), 1000);
        } else {
            showToast(`${t('modals.modal.txt_d3dc939a')} ${data.error || t('modals.modal.txt_51112618')}`, 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast(t('modals.modal.txt_355ec77a'), 'error');
    }
}

// دالة لمعالجة البيانات الملصوقة
async function parsePasteData() {
    const text = document.getElementById('smartPasteInput')?.value;

    if (!text || !text.trim()) {
        showToast(t('modals.modal.txt_7a89e99a'), 'error');
        return;
    }

    // Show loading state
    const btnProcess = document.getElementById('btnProcessPaste');
    const originalText = btnProcess.innerHTML;
    btnProcess.innerHTML = t('modals.modal.txt_43edcd75');
    btnProcess.disabled = true;

    // Hide previous results
    setHidden(document.getElementById('extractionPreview'), true);
    setHidden(document.getElementById('smartPasteError'), true);
    const errorMsgEl = document.getElementById('errorMessage');
    const missingFieldsEl = document.getElementById('missingFieldsList');
    if (errorMsgEl) {
        errorMsgEl.textContent = '';
    }
    if (missingFieldsEl) {
        missingFieldsEl.innerHTML = '';
    }

    try {
        const payload = {
            text: text,
            is_test_data: document.getElementById('pasteIsTestData')?.checked ? 1 : 0,
            test_batch_id: document.getElementById('pasteTestBatchId')?.value,
            test_note: document.getElementById('pasteTestNote')?.value
        };

        const sendParseRequest = (endpoint, clientHint) => fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WBGL-Parse-Client': clientHint
            },
            body: JSON.stringify(payload)
        });

        let response;
        try {
            response = await sendParseRequest('/api/parse-paste-v2.php', 'ui-v2-primary');
        } catch (primaryError) {
            response = await sendParseRequest('/api/parse-paste.php', 'ui-v2-fallback-v1-network');
        }

        const fallbackStatuses = [404, 410, 500, 502, 503, 504];
        if (response && !response.ok && fallbackStatuses.includes(response.status)) {
            response = await sendParseRequest('/api/parse-paste.php', 'ui-v2-fallback-v1-status');
        }

        const responseText = await response.text();
        let data = null;
        try {
            data = responseText ? JSON.parse(responseText) : null;
        } catch (jsonError) {
            const requestId = response.headers.get('x-request-id') || '';
            const details = `
                <div class="wbgl-field-status">
                    <strong>تفاصيل فنية:</strong>
                    <div class="wbgl-field-status-grid">
                        <div class="wbgl-field-status-item wbgl-field-status-item--missing">HTTP: ${response.status}</div>
                        ${requestId ? `<div class="wbgl-field-status-item wbgl-field-status-item--missing">Request ID: ${requestId}</div>` : ''}
                    </div>
                </div>
            `;
            showSmartPasteErrorInline('تعذر قراءة استجابة الخادم. حاول مرة أخرى أو راجع مسؤول النظام.', details);
            showToast('فشل تحليل الاستجابة من الخادم', 'error');
            return;
        }

        if (!data || typeof data !== 'object') {
            showSmartPasteErrorInline('لم تصل استجابة صالحة من الخادم. حاول مرة أخرى.');
            showToast('تعذر إتمام عملية التحليل', 'error');
            return;
        }

        if (data.success) {
            // Check if multi-guarantee import
            if (data.multi && data.results) {
                // Multi-guarantee success!
                const previewDiv = document.getElementById('extractionPreview');
                const fieldsDiv = document.getElementById('extractionFields');

                let multiHTML = `
                    <div class="wbgl-extraction-row wbgl-extraction-row--summary">
                        <div class="wbgl-extraction-summary">
                            🎯 تم استيراد ${data.count} ضمان بنجاح
                        </div>
                    </div>
                `;

                data.results.forEach((result, index) => {
                    if (result.failed) {
                        multiHTML += `
                            <div class="wbgl-extraction-row wbgl-extraction-row--error">
                                <div class="wbgl-extraction-error">❌ ${result.guarantee_number}: ${result.error}</div>
                            </div>
                        `;
                    } else {
                        multiHTML += `
                            <div class="wbgl-extraction-row wbgl-extraction-row--success">
                                <div class="wbgl-extraction-success">✅ ${result.guarantee_number}</div>
                                <div class="wbgl-extraction-meta">${result.supplier || '—'} | ${result.amount ? result.amount.toLocaleString() + ' ' + t('modals.currency.sar') : '—'}</div>
                            </div>
                        `;
                    }
                });

                fieldsDiv.innerHTML = multiHTML;
                setHidden(previewDiv, false);

                showToast(data.message, 'success');
                setTimeout(() => window.location.reload(), 2000);
                return;
            }

            // Single guarantee (existing logic)
            const previewDiv = document.getElementById('extractionPreview');
            const fieldsDiv = document.getElementById('extractionFields');

            const fieldLabels = {
                guarantee_number: t('modals.manual_entry.labels.guarantee_number'),
                supplier: t('modals.manual_entry.labels.supplier'),
                bank: t('modals.manual_entry.labels.bank'),
                amount: t('modals.manual_entry.labels.amount'),
                expiry_date: t('modals.manual_entry.labels.expiry_date'),
                contract_number: t('modals.manual_entry.labels.contract_number'),
                issue_date: t('modals.manual_entry.labels.issue_date'),
                type: t('modals.manual_entry.labels.guarantee_type')
            };

            let fieldsHTML = '';
            const hasConfidence = data.confidence && Object.keys(data.confidence).length > 0;

            // Show overall confidence if available
            if (hasConfidence && data.overall_confidence) {
                const overallDiv = document.getElementById('overallConfidence');
                const level = data.overall_confidence >= 90 ? t('modals.modal.txt_ba3df4e0') :
                    data.overall_confidence >= 70 ? t('modals.modal.txt_7e9ac181') : t('modals.modal.txt_26efbdb2');
                const tone = data.overall_confidence >= 90 ? 'high' :
                    data.overall_confidence >= 70 ? 'medium' : 'low';
                overallDiv.innerHTML = `<span class="wbgl-overall-confidence wbgl-overall-confidence--${tone}">${t('modals.paste.overall_confidence', { level: level, score: data.overall_confidence })}</span>`;
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
                    let confToneClass = 'default';

                    if (conf) {
                        if (confScore >= 90) {
                            confBadge = `<span class="wbgl-confidence-badge wbgl-confidence-badge--high">✓ ${confScore}%</span>`;
                            confToneClass = 'high';
                        } else if (confScore >= 70) {
                            confBadge = `<span class="wbgl-confidence-badge wbgl-confidence-badge--medium">⚠ ${confScore}%</span>`;
                            confToneClass = 'medium';
                        } else {
                            confBadge = `<span class="wbgl-confidence-badge wbgl-confidence-badge--low">⚠ ${confScore}% - ${confReason}</span>`;
                            confToneClass = 'low';
                        }
                    }

                    fieldsHTML += `
                        <div class="wbgl-field-card wbgl-field-card--${confToneClass}">
                            <div class="wbgl-field-card__head">
                                <div class="wbgl-field-card__label">${label}</div>
                                ${confBadge}
                            </div>
                            <div class="wbgl-field-card__value">${value}</div>
                            ${conf && conf.reason ? `<div class="wbgl-field-card__reason">${conf.reason}</div>` : ''}
                        </div>
                    `;
                }
            }

            fieldsDiv.innerHTML = fieldsHTML;
            setHidden(previewDiv, false);

            // Success!
            showToast(data.message || t('modals.modal.txt_3ebe03a6'), 'success');
            setTimeout(() => {
                const targetUrl = new URL(window.location.href);
                targetUrl.searchParams.set('id', String(data.id));
                window.location.href = targetUrl.pathname + targetUrl.search;
            }, 1500);

        } else {
            // Show detailed error
            const errorDiv = document.getElementById('smartPasteError');
            const errorMsg = document.getElementById('errorMessage');
            const missingList = document.getElementById('missingFieldsList');

            errorMsg.textContent = data.error || t('modals.modal.txt_f2ca37e9');

            // Show what was extracted and what is missing
            if (data.field_status) {
                let statusHTML = '<div class="wbgl-field-status"><strong>حالة الحقول:</strong><div class="wbgl-field-status-grid">';

                const fieldLabels = {
                    guarantee_number: t('modals.manual_entry.labels.guarantee_number'),
                    supplier: t('modals.manual_entry.labels.supplier'),
                    bank: t('modals.manual_entry.labels.bank'),
                    amount: t('modals.manual_entry.labels.amount'),
                    expiry_date: t('modals.manual_entry.labels.expiry_date'),
                    contract_number: t('modals.manual_entry.labels.contract_number')
                };

                for (const [key, label] of Object.entries(fieldLabels)) {
                    const status = data.field_status[key] || '❌';
                    const value = data.extracted?.[key] || '—';
                    const stateClass = status === '✅' ? 'ok' : 'missing';

                    statusHTML += `
                        <div class="wbgl-field-status-item wbgl-field-status-item--${stateClass}">
                            ${status} ${label}: ${value}
                        </div>
                    `;
                }

                statusHTML += '</div></div>';
                missingList.innerHTML = statusHTML;
            }

            setHidden(errorDiv, false);
            showToast(t('modals.modal.txt_127b2b67'), 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showSmartPasteErrorInline('تعذر الاتصال بالخادم أثناء التحليل. تحقق من الاتصال ثم أعد المحاولة.');
        showToast(t('modals.modal.txt_355ec77a'), 'error');
    } finally {
        btnProcess.innerHTML = originalText;
        btnProcess.disabled = false;
    }
}

// دالة لفتح modal استيراد Excel
function showImportModal() {
    const modal = document.getElementById('excelImportModal');
    if (modal) {
        setModalOpen(modal, true);
    }
}

// Placeholder for uploadExcelFile function (to be defined elsewhere or added)
async function uploadExcelFile() {
    const fileInput = document.getElementById('excelFileInput');
    const file = fileInput.files[0];

    if (!file) {
        showToast(t('modals.modal.txt_452d4e39'), 'error');
        return;
    }

    // Show loading indicator
    const loadingMsg = document.createElement('div');
    loadingMsg.id = 'uploadProgress';
    loadingMsg.className = 'wbgl-upload-progress';
    loadingMsg.innerHTML = `
        <div class="wbgl-upload-progress__title">${t('modals.upload.in_progress')}</div>
        <div class="wbgl-upload-progress__file">${file.name}</div>
    `;
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
            showToast(t('modals.upload.import_success_count', { count: importedCount }), 'success');
            setTimeout(() => window.location.reload(), 1500);
        } else {
            showToast(`${t('modals.modal.txt_d3dc939a')} ${data.error || t('modals.modal.txt_9958af61')}`, 'error');
        }
    } catch (error) {
        loadingMsg.remove();
        console.error('Error:', error);
        showToast(t('modals.modal.txt_355ec77a'), 'error');
    }


    // Reset form
    fileInput.value = '';
    document.getElementById('selectedFileName').textContent = t('modals.modal.txt_749da6ed');
    document.getElementById('excelIsTestData').checked = false;
    setHidden(document.getElementById('excelTestFields'), true);
}

// إعداد الـ event listeners عند تحميل الصفحة
document.addEventListener('DOMContentLoaded', function () {
    initializeManualAmountFormatter();

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

    const pasteIsTestData = document.getElementById('pasteIsTestData');
    if (pasteIsTestData) {
        pasteIsTestData.addEventListener('change', function () {
            const fields = document.getElementById('pasteTestFields');
            if (fields) {
                fields.style.display = this.checked ? 'block' : 'none';
            }
        });
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
            const fileName = this.files[0]?.name || t('modals.modal.txt_749da6ed');
            document.getElementById('selectedFileName').textContent = fileName;
            document.getElementById('btnUploadExcel').disabled = !this.files[0];
        });
    }

    if (btnUploadExcel) {
        btnUploadExcel.addEventListener('click', uploadExcelFile);
    }

    const excelIsTestData = document.getElementById('excelIsTestData');
    if (excelIsTestData) {
        excelIsTestData.addEventListener('change', function () {
            const fields = document.getElementById('excelTestFields');
            if (fields) {
                fields.style.display = this.checked ? 'block' : 'none';
            }
        });
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
            loadingMsg.className = 'wbgl-upload-progress';
            loadingMsg.innerHTML = `
                <div class="wbgl-upload-progress__title">${t('modals.upload.in_progress')}</div>
                <div class="wbgl-upload-progress__file">${file.name}</div>
            `;
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
                    showToast(t('modals.upload.import_success_count', { count: importedCount }), 'success');
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    showToast(`${t('modals.modal.txt_d3dc939a')} ${data.error || t('modals.modal.txt_9958af61')}`, 'error');
                }
            } catch (error) {
                loadingMsg.remove();
                console.error('Error:', error);
                showToast(t('modals.modal.txt_355ec77a'), 'error');
            }

            // Reset input for next time
            e.target.value = '';
        });
    }
});
