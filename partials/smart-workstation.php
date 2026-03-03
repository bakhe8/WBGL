<!-- Smart Workstation Overlay (Split-Screen) -->
<div id="smartWorkstation" class="ws-overlay ws-hidden">

    <!-- Header -->
    <header class="ws-header">
        <div class="ws-header-title-group">
            <div class="ws-header-icon">🖥️</div>
            <h2 class="ws-header-title" data-i18n="index.workstation.title">محطة العمل الذكية: إكمال بيانات الضمانات المتعددة</h2>
        </div>

        <div class="ws-header-actions">
            <div id="workstationStatus" class="ws-status-pill">
                <span data-i18n="index.workstation.guarantee_label">ضمان</span> <span id="currentEntryIndex">1</span> <span data-i18n="index.workstation.of_label">من</span> <span id="totalEntriesCount">1</span>
            </div>
            <button id="btnCloseWorkstation" class="ws-close-btn" data-i18n="index.workstation.close">❌ إغلاق</button>
        </div>
    </header>

    <!-- Main Split View -->
    <div class="ws-main">

        <!-- Left Pillar: PDF Viewer -->
        <div class="ws-left-pane">
            <div class="ws-left-pane-header">
                <span data-i18n="index.workstation.source_document">📄 المستند المصدر (PDF)</span>
                <span id="pdfFileName" data-i18n="index.workstation.loading">جاري التحميل...</span>
            </div>
            <iframe id="workstationPdfViewer" src="about:blank" class="ws-pdf-viewer"></iframe>
        </div>

        <!-- Right Pillar: Form Entry -->
        <div class="ws-right-pane">
            <div class="ws-right-pane-header">
                <span class="ws-right-pane-title" data-i18n="index.workstation.details_title">📝 تفاصيل الضمان</span>
                <button class="btn btn-sm btn-outline-secondary ws-reset-btn" id="btnWorkstationReset" data-i18n="index.workstation.reset_fields">🔄 تصفير الحقول</button>
            </div>

            <div class="ws-form-scroll">
                <form id="workstationForm" class="ws-form-grid">

                    <!-- Individual Guarantee Details -->
                    <div class="ws-info-card">
                        <div class="ws-info-title">
                            <span data-i18n="index.workstation.single_guarantee_data">🆔 بيانات الضمان الفردي</span>
                        </div>

                        <div class="ws-fields-stack">
                            <!-- Identity -->
                            <div>
                                <label class="ws-label ws-label--primary" data-i18n="index.workstation.guarantee_number_required">رقم الضمان *</label>
                                <input type="text" id="wsGuarantee" class="field-input" placeholder="" data-i18n-placeholder="index.workstation.placeholders.guarantee_number_pdf" required>
                            </div>

                            <!-- Entities (Unique per guarantee per user feedback) -->
                            <div class="ws-two-col-grid">
                                <div>
                                    <label class="ws-label ws-label--primary" data-i18n="index.workstation.labels.supplier">المورد</label>
                                    <input type="text" id="wsSupplier" class="field-input" placeholder="" data-i18n-placeholder="index.workstation.placeholders.supplier_name">
                                </div>
                                <div>
                                    <label class="ws-label ws-label--primary" data-i18n="index.workstation.labels.bank">البنك</label>
                                    <input type="text" id="wsBank" class="field-input" placeholder="" data-i18n-placeholder="index.workstation.placeholders.bank_name">
                                </div>
                            </div>

                            <!-- Financials & Contract -->
                            <div class="ws-two-col-grid">
                                <div>
                                    <label class="ws-label ws-label--primary" data-i18n="index.workstation.labels.amount_required">المبلغ *</label>
                                    <input type="text" id="wsAmount" class="field-input" placeholder="0.00" required>
                                </div>
                                <div>
                                    <label class="ws-label ws-label--primary" data-i18n="index.workstation.labels.contract_number">رقم العقد</label>
                                    <input type="text" id="wsContract" class="field-input" placeholder="" data-i18n-placeholder="index.workstation.placeholders.contract_number">
                                </div>
                            </div>

                            <!-- Dates & Type -->
                            <div class="ws-two-col-grid">
                                <div>
                                    <label class="ws-label ws-label--primary" data-i18n="index.workstation.labels.expiry_date">تاريخ الانتهاء</label>
                                    <input type="date" id="wsExpiry" class="field-input">
                                </div>
                                <div>
                                    <label class="ws-label ws-label--primary" data-i18n="index.workstation.labels.type">النوع</label>
                                    <select id="wsType" class="field-input ws-type-select">
                                        <option value="" data-i18n="index.workstation.options.select_type">اختر النوع</option>
                                        <option value="FINAL" data-i18n="index.workstation.options.final">نهائي</option>
                                        <option value="ADVANCED" data-i18n="index.workstation.options.advanced">دفعة مقدمة</option>
                                        <option value="INITIAL" data-i18n="index.workstation.options.initial">ابتدائي</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Additional Comments -->
                    <div>
                        <label class="ws-label ws-label--default" data-i18n="index.workstation.labels.guarantee_notes">ملاحظات الضمان</label>
                        <textarea id="wsComment" rows="3" class="field-input" placeholder="" data-i18n-placeholder="index.workstation.placeholders.additional_details"></textarea>
                    </div>
                </form>
            </div>

            <!-- Footer: Navigation & Final Actions -->
            <div class="ws-footer">

                <!-- Nav -->
                <div class="ws-nav-grid">
                    <button id="btnWsPrev" class="btn btn-secondary btn-sm" data-i18n="index.workstation.actions.previous" disabled>⬅️ السابق</button>
                    <button id="btnWsNext" class="btn btn-primary btn-sm ws-next-btn" data-i18n="index.workstation.actions.add_next">➕ إضافة التالي</button>
                    <button id="btnWsNextHidden" class="ws-hidden"></button>
                </div>

                <!-- Finalize -->
                <button id="btnWsFinish" class="btn btn-success ws-finish-btn" data-i18n="index.workstation.actions.save_all_finish">✅ حفظ الكل والإنهاء</button>
                <div class="ws-footer-note" data-i18n="index.workstation.footer_note">الضغط على "حفظ الكل" سيقوم بإنشاء السجلات في قاعدة البيانات دفعة واحدة</div>

            </div>
        </div>
    </div>
</div>

<style>
#smartWorkstation.ws-overlay {
    position: fixed;
    inset: 0;
    background: var(--bg-card);
    z-index: 10000;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

#smartWorkstation.ws-hidden {
    display: none;
}

#smartWorkstation .ws-header {
    height: 60px;
    background: var(--text-primary);
    color: var(--btn-primary-text);
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 24px;
    flex-shrink: 0;
}

#smartWorkstation .ws-header-title-group,
#smartWorkstation .ws-header-actions {
    display: flex;
    align-items: center;
    gap: 12px;
}

#smartWorkstation .ws-header-title-group {
    gap: 16px;
}

#smartWorkstation .ws-header-icon {
    font-size: 20px;
}

#smartWorkstation .ws-header-title {
    margin: 0;
    font-size: 18px;
    font-weight: 700;
}

#smartWorkstation .ws-status-pill {
    font-size: 14px;
    background: var(--theme-overlay-medium);
    padding: 4px 12px;
    border-radius: 20px;
}

#smartWorkstation .ws-close-btn {
    background: var(--theme-overlay-medium);
    border: none;
    color: var(--btn-primary-text);
    padding: 8px 16px;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
}

#smartWorkstation .ws-main {
    display: flex;
    flex: 1;
    overflow: hidden;
}

#smartWorkstation .ws-left-pane {
    flex: 1;
    background: var(--text-secondary);
    display: flex;
    flex-direction: column;
    border-left: 1px solid var(--text-muted);
}

#smartWorkstation .ws-left-pane-header {
    background: var(--text-muted);
    padding: 8px 16px;
    color: var(--bg-secondary);
    font-size: 13px;
    font-weight: 600;
    display: flex;
    justify-content: space-between;
}

#smartWorkstation .ws-pdf-viewer {
    width: 100%;
    flex: 1;
    border: none;
}

#smartWorkstation .ws-right-pane {
    width: 500px;
    background: var(--bg-secondary);
    display: flex;
    flex-direction: column;
    flex-shrink: 0;
}

#smartWorkstation .ws-right-pane-header {
    background: var(--bg-hover);
    padding: 12px 24px;
    border-bottom: 1px solid var(--border-primary);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

#smartWorkstation .ws-right-pane-title {
    font-weight: 700;
    color: var(--text-secondary);
}

#smartWorkstation .ws-reset-btn {
    font-size: 12px;
}

#smartWorkstation .ws-form-scroll {
    flex: 1;
    padding: 24px;
    overflow-y: auto;
}

#smartWorkstation .ws-form-grid {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

#smartWorkstation .ws-info-card {
    padding: 16px;
    background: var(--theme-info-surface);
    border: 1px solid var(--accent-primary);
    border-radius: 8px;
}

#smartWorkstation .ws-info-title {
    font-size: 12px;
    color: var(--theme-info-text);
    font-weight: 700;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 4px;
}

#smartWorkstation .ws-fields-stack {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

#smartWorkstation .ws-two-col-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}

#smartWorkstation .ws-label {
    display: block;
    font-size: 13px;
    font-weight: 700;
    margin-bottom: 4px;
}

#smartWorkstation .ws-label--primary {
    color: var(--theme-info-text);
}

#smartWorkstation .ws-label--default {
    color: var(--text-secondary);
}

#smartWorkstation .ws-type-select {
    background: var(--bg-card);
}

#smartWorkstation .ws-footer {
    padding: 16px 24px;
    background: var(--bg-card);
    border-top: 1px solid var(--border-primary);
    display: flex;
    flex-direction: column;
    gap: 12px;
}

#smartWorkstation .ws-nav-grid {
    display: grid;
    grid-template-columns: 1fr 1.5fr 1fr;
    gap: 8px;
    align-items: center;
}

#smartWorkstation .ws-next-btn {
    background: var(--accent-primary);
    border-color: var(--accent-primary);
}

#smartWorkstation .ws-finish-btn {
    width: 100%;
    padding: 12px;
    font-weight: 800;
    background: var(--accent-success-hover);
    border-color: var(--accent-success-hover);
    font-size: 16px;
}

#smartWorkstation .ws-footer-note {
    font-size: 11px;
    color: var(--text-muted);
    text-align: center;
}

#smartWorkstation .field-input {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid var(--border-neutral);
    border-radius: 6px;
    font-size: 14px;
    outline: none;
    transition: all 0.2s;
}

#smartWorkstation .field-input:focus {
    border-color: var(--accent-primary);
    box-shadow: 0 0 0 3px var(--theme-focus-ring-soft);
}
</style>
