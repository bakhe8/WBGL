<!-- Excel Import Modal -->
<div id="excelImportModal" class="wbgl-input-modal-overlay" aria-hidden="true">
    <div class="wbgl-input-modal wbgl-input-modal--compact" role="dialog" aria-modal="true" aria-labelledby="excelImportModalTitle">
        <div class="wbgl-input-modal__header">
            <h3 id="excelImportModalTitle" class="wbgl-input-modal__title" data-i18n="modals.excel.title">📊 استيراد ملف Excel</h3>
            <button id="btnCloseExcelModal" type="button" class="wbgl-input-modal__close" aria-label="" data-i18n-aria-label="modals.common.close">&times;</button>
        </div>

        <div class="wbgl-input-modal__body">
            <div class="wbgl-modal-note wbgl-modal-note--info wbgl-modal-note--compact">
                <span>💡</span>
                <div data-i18n="modals.excel.hint">اختر ملف Excel (.xlsx أو .xls) يحتوي على بيانات الضمانات. تأكد من وجود أعمدة: المورد، البنك، رقم الضمان.</div>
            </div>

            <div>
                <label class="wbgl-file-dropzone">
                    <input type="file" id="excelFileInput" class="wbgl-hidden-input" accept=".xlsx,.xls">
                    <div class="wbgl-file-dropzone-icon">📄</div>
                    <div class="wbgl-file-dropzone-title" data-i18n="modals.excel.select_file">انقر لاختيار ملف</div>
                    <div id="selectedFileName" class="wbgl-file-dropzone-name" data-i18n="modals.excel.no_file_selected">لم يتم اختيار ملف</div>
                </label>
            </div>

            <?php
            $settings = \App\Support\Settings::getInstance();
            if (!$settings->isProductionMode()):
            ?>
            <div class="wbgl-modal-note wbgl-modal-note--warning">
                <label class="wbgl-radio-option wbgl-fw-600">
                    <input type="checkbox" id="excelIsTestData" class="wbgl-input-control">
                    <span data-i18n="modals.excel.test_data.mark_as_test">🧪 تمييز كبيانات تجريبية (لأغراض الاختبار فقط)</span>
                </label>

                <div id="excelTestFields" class="wbgl-note-fields wbgl-hidden">
                    <div class="wbgl-input-field wbgl-mb-12">
                        <label for="excelTestBatchId" class="wbgl-input-label" data-i18n="modals.excel.test_data.batch_id">معرّف الدفعة (Batch ID)</label>
                        <input type="text" id="excelTestBatchId" class="wbgl-input-control" placeholder="" data-i18n-placeholder="modals.excel.test_data.batch_id_placeholder">
                    </div>
                    <div class="wbgl-input-field">
                        <label for="excelTestNote" class="wbgl-input-label" data-i18n="modals.excel.test_data.note_label">ملاحظة (اختياري)</label>
                        <input type="text" id="excelTestNote" class="wbgl-input-control" placeholder="" data-i18n-placeholder="modals.excel.test_data.note_placeholder">
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="wbgl-input-modal__footer">
            <button id="btnCancelExcel" type="button" class="wbgl-btn wbgl-btn--ghost" data-i18n="modals.common.cancel">إلغاء</button>
            <button id="btnUploadExcel" type="button" class="wbgl-btn wbgl-btn--success" disabled data-i18n="modals.excel.actions.upload_analyze">📤 رفع وتحليل</button>
        </div>
    </div>
</div>
