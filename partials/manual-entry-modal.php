<!-- Manual Entry Modal -->
<div id="manualEntryModal" class="wbgl-input-modal-overlay" aria-hidden="true">
    <div class="wbgl-input-modal wbgl-input-modal--form" role="dialog" aria-modal="true" aria-labelledby="manualEntryModalTitle">
        <div class="wbgl-input-modal__header">
            <h3 id="manualEntryModalTitle" class="wbgl-input-modal__title" data-i18n="modals.manual_entry.title">✍️ إدخال سجل يدوي</h3>
            <button id="btnCloseManualEntry" type="button" class="wbgl-input-modal__close" aria-label="" data-i18n-aria-label="modals.common.close">&times;</button>
        </div>

        <form id="manualEntryForm" class="wbgl-input-modal__body">
            <div class="wbgl-input-grid">
                <div class="wbgl-input-field">
                    <label for="manualSupplier" class="wbgl-input-label"><span data-i18n="modals.manual_entry.labels.supplier">المورد</span> <span class="wbgl-required-star">*</span></label>
                    <input type="text" id="manualSupplier" class="wbgl-input-control" placeholder="" data-i18n-placeholder="modals.manual_entry.placeholders.supplier_name" required>
                </div>

                <div class="wbgl-input-field">
                    <label for="manualBank" class="wbgl-input-label"><span data-i18n="modals.manual_entry.labels.bank">البنك</span> <span class="wbgl-required-star">*</span></label>
                    <input type="text" id="manualBank" class="wbgl-input-control" placeholder="" data-i18n-placeholder="modals.manual_entry.placeholders.bank_name" required>
                </div>

                <div class="wbgl-input-field">
                    <label for="manualGuarantee" class="wbgl-input-label"><span data-i18n="modals.manual_entry.labels.guarantee_number">رقم الضمان</span> <span class="wbgl-required-star">*</span></label>
                    <input type="text" id="manualGuarantee" class="wbgl-input-control" placeholder="" data-i18n-placeholder="modals.manual_entry.placeholders.guarantee_number" required>
                </div>

                <div class="wbgl-input-field">
                    <label for="manualContract" class="wbgl-input-label"><span data-i18n="modals.manual_entry.labels.contract_number">رقم العقد</span> <span class="wbgl-required-star">*</span></label>
                    <input type="text" id="manualContract" class="wbgl-input-control" placeholder="" data-i18n-placeholder="modals.manual_entry.placeholders.contract_or_po" required>
                </div>

                <div class="wbgl-input-field">
                    <label class="wbgl-input-label"><span data-i18n="modals.manual_entry.labels.document_type">نوع المستند</span> <span class="wbgl-required-star">*</span></label>
                    <div class="wbgl-radio-group">
                        <label class="wbgl-radio-option">
                            <input type="radio" name="relatedTo" value="contract" class="wbgl-input-control" checked>
                            <span data-i18n="modals.manual_entry.options.contract">📄 عقد</span>
                        </label>
                        <label class="wbgl-radio-option">
                            <input type="radio" name="relatedTo" value="purchase_order" class="wbgl-input-control">
                            <span data-i18n="modals.manual_entry.options.purchase_order">🛒 أمر شراء</span>
                        </label>
                    </div>
                    <small class="wbgl-helper-text" data-i18n="modals.manual_entry.helper.related_document">حدد نوع المستند المرتبط بهذا الضمان البنكي</small>
                </div>

                <div class="wbgl-input-field">
                    <label for="manualAmount" class="wbgl-input-label"><span data-i18n="modals.manual_entry.labels.amount">المبلغ</span> <span class="wbgl-required-star">*</span></label>
                    <input type="text" id="manualAmount" class="wbgl-input-control" placeholder="50000.00" required>
                </div>

                <div class="wbgl-input-field">
                    <label for="manualExpiry" class="wbgl-input-label" data-i18n="modals.manual_entry.labels.expiry_date">تاريخ الانتهاء</label>
                    <input type="date" id="manualExpiry" class="wbgl-input-control">
                </div>

                <div class="wbgl-input-field">
                    <label for="manualType" class="wbgl-input-label" data-i18n="modals.manual_entry.labels.guarantee_type">نوع الضمان</label>
                    <select id="manualType" class="wbgl-input-control">
                        <option value="" data-i18n="modals.manual_entry.options.select_type">اختر النوع</option>
                        <option value="FINAL" data-i18n="modals.manual_entry.options.final">نهائي (FINAL)</option>
                        <option value="ADVANCED" data-i18n="modals.manual_entry.options.advanced">دفعة مقدمة (ADVANCED)</option>
                    </select>
                </div>

                <div class="wbgl-input-field">
                    <label for="manualIssue" class="wbgl-input-label" data-i18n="modals.manual_entry.labels.issue_date">تاريخ الإصدار</label>
                    <input type="date" id="manualIssue" class="wbgl-input-control">
                </div>
            </div>

            <div class="wbgl-input-field wbgl-input-field--mt16">
                <label for="manualComment" class="wbgl-input-label" data-i18n="modals.manual_entry.labels.notes">ملاحظات</label>
                <textarea id="manualComment" rows="2" class="wbgl-input-control wbgl-textarea" placeholder="" data-i18n-placeholder="modals.manual_entry.placeholders.notes"></textarea>
            </div>

            <?php
            $settings = \App\Support\Settings::getInstance();
            if (!$settings->isProductionMode()):
            ?>
            <div class="wbgl-modal-note wbgl-modal-note--warning">
                <label class="wbgl-radio-option wbgl-radio-option--top">
                    <input type="checkbox" id="manualIsTestData" name="is_test_data" value="1" class="wbgl-input-control">
                    <span>
                        <span class="wbgl-note-title" data-i18n="modals.manual_entry.test_data.mark_as_test">🧪 تمييز كضمان تجريبي</span>
                        <span class="wbgl-note-text wbgl-text-block" data-i18n="modals.manual_entry.test_data.note">البيانات المُميزة كتجريبية لن تؤثر على الإحصائيات ونظام التعلم</span>
                    </span>
                </label>
                <div id="testBatchIdContainer" class="wbgl-note-fields wbgl-hidden">
                    <input type="text" id="manualTestBatchId" name="test_batch_id" class="wbgl-input-control" placeholder="" data-i18n-placeholder="modals.manual_entry.test_data.batch_id_placeholder">
                    <textarea id="manualTestNote" name="test_note" rows="2" class="wbgl-input-control wbgl-textarea wbgl-mt-8" placeholder="" data-i18n-placeholder="modals.manual_entry.test_data.note_placeholder"></textarea>
                </div>
            </div>
            <?php endif; ?>

            <div class="wbgl-required-hint">
                <span class="wbgl-required-star">*</span>
                <span data-i18n="modals.manual_entry.required_fields">الحقول المطلوبة</span>
            </div>

            <div id="manualEntryError" class="wbgl-modal-error wbgl-required-star"></div>
            <input type="hidden" id="manualConfidenceMetadata" value="">
        </form>

        <div class="wbgl-input-modal__footer">
            <button id="btnCancelManualEntry" type="button" class="wbgl-btn wbgl-btn--ghost" data-i18n="modals.common.cancel">إلغاء</button>
            <button id="btnSaveManualEntry" type="button" class="wbgl-btn wbgl-btn--success" data-i18n="modals.manual_entry.save_and_add">✓ حفظ وإضافة</button>
        </div>
    </div>
</div>

<script>
(function () {
    let formModified = false;
    const form = document.getElementById('manualEntryForm');
    const modal = document.getElementById('manualEntryModal');

    function hideManualEntryModal() {
        if (modal) {
            modal.style.display = 'none';
            modal.setAttribute('aria-hidden', 'true');
        }
        if (form) {
            form.reset();
        }
        const container = document.getElementById('testBatchIdContainer');
        if (container) {
            container.style.display = 'none';
        }
        formModified = false;
    }

    if (form) {
        const inputs = Array.from(form.elements || []);
        inputs.forEach((input) => {
            input.addEventListener('change', () => {
                formModified = true;
            });
            input.addEventListener('input', () => {
                formModified = true;
            });
        });
    }

    document.getElementById('manualIsTestData')?.addEventListener('change', function () {
        const container = document.getElementById('testBatchIdContainer');
        if (container) {
            container.style.display = this.checked ? 'block' : 'none';
        }
    });

    window.addEventListener('beforeunload', function (event) {
        if (formModified && modal && modal.style.display !== 'none') {
            event.preventDefault();
            event.returnValue = (window.WBGLI18n && typeof window.WBGLI18n.t === 'function' ? window.WBGLI18n.t('modals.manual_entry.unsaved.leave', 'لديك تعديلات غير محفوظة. هل تريد المغادرة؟') : 'لديك تعديلات غير محفوظة. هل تريد المغادرة؟');
            return event.returnValue;
        }
    });

    const btnSave = document.getElementById('btnSaveManualEntry');
    const btnCancel = document.getElementById('btnCancelManualEntry');
    const btnClose = document.getElementById('btnCloseManualEntry');

    if (btnSave) {
        btnSave.addEventListener('click', function () {
            formModified = false;
        });
    }

    function bindSafeClose(button, promptText) {
        if (!button) {
            return;
        }
        button.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopImmediatePropagation();
            if (formModified) {
                if (confirm(promptText)) {
                    hideManualEntryModal();
                }
                return;
            }
            hideManualEntryModal();
        });
    }

    bindSafeClose(btnCancel, (window.WBGLI18n && typeof window.WBGLI18n.t === 'function' ? window.WBGLI18n.t('modals.manual_entry.unsaved.cancel', 'لديك تعديلات غير محفوظة. هل تريد الإلغاء؟') : 'لديك تعديلات غير محفوظة. هل تريد الإلغاء؟'));
    bindSafeClose(btnClose, (window.WBGLI18n && typeof window.WBGLI18n.t === 'function' ? window.WBGLI18n.t('modals.manual_entry.unsaved.close', 'لديك تعديلات غير محفوظة. هل تريد الإغلاق؟') : 'لديك تعديلات غير محفوظة. هل تريد الإغلاق؟'));
})();
</script>
