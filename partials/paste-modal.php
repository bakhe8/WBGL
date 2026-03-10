<!-- Smart Paste Modal -->
<div id="smartPasteModal" class="wbgl-input-modal-overlay" aria-hidden="true">
    <div class="wbgl-input-modal wbgl-input-modal--form" role="dialog" aria-modal="true" aria-labelledby="smartPasteModalTitle">
        <div class="wbgl-input-modal__header">
            <h3 id="smartPasteModalTitle" class="wbgl-input-modal__title" data-i18n="modals.paste.title">📋 لصق بيانات ذكي (Smart Paste)</h3>
            <button id="btnClosePasteModal" type="button" class="wbgl-input-modal__close" aria-label="" data-i18n-aria-label="modals.common.close">&times;</button>
        </div>

        <div class="wbgl-input-modal__body">
            <div class="wbgl-modal-note wbgl-modal-note--info wbgl-modal-note--compact">
                <span>💡</span>
                <div data-i18n="modals.paste.hint">قم بنسخ نص الإيميل أو الطلب ولصقه هنا. سيقوم النظام باستخراج البيانات تلقائياً.</div>
            </div>

            <textarea
                id="smartPasteInput"
                class="wbgl-input-control wbgl-textarea wbgl-textarea--paste"
                placeholder=""
                data-i18n-placeholder="modals.paste.placeholder"></textarea>

            <div id="extractionPreview" class="wbgl-input-field--mt16 wbgl-hidden">
                <div class="wbgl-modal-note wbgl-modal-note--success wbgl-no-margin-top wbgl-note-inline-muted">
                    <div class="wbgl-flex-between wbgl-mb-12">
                        <div class="wbgl-text-14-700" data-i18n="modals.paste.results.title">✅ نتائج الاستخراج</div>
                        <div id="overallConfidence" class="wbgl-text-12-600"></div>
                    </div>
                    <div id="extractionFields" class="wbgl-stack-12"></div>
                </div>
            </div>

            <?php
            $settings = \App\Support\Settings::getInstance();
            if (!$settings->isProductionMode() && \App\Support\TestDataVisibility::canCurrentUserAccessTestData()):
            ?>
            <div class="wbgl-modal-note wbgl-modal-note--warning">
                <label class="wbgl-radio-option wbgl-fw-600">
                    <input type="checkbox" id="pasteIsTestData" class="wbgl-input-control">
                    <span data-i18n="modals.paste.test_data.mark_as_test">🧪 تمييز كضمان تجريبي (لأغراض الاختبار فقط)</span>
                </label>

                <div id="pasteTestFields" class="wbgl-note-fields wbgl-hidden">
                    <div class="wbgl-note-grid">
                        <div>
                            <label for="pasteTestBatchId" class="wbgl-input-label" data-i18n="modals.paste.test_data.batch_id">معرّف الدفعة (Batch ID)</label>
                            <input type="text" id="pasteTestBatchId" class="wbgl-input-control" placeholder="" data-i18n-placeholder="modals.paste.test_data.batch_id_placeholder">
                        </div>
                        <div>
                            <label for="pasteTestNote" class="wbgl-input-label" data-i18n="modals.paste.test_data.note_label">ملاحظة (اختياري)</label>
                            <input type="text" id="pasteTestNote" class="wbgl-input-control" placeholder="" data-i18n-placeholder="modals.paste.test_data.note_placeholder">
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div id="smartPasteError" class="wbgl-modal-note wbgl-modal-note--danger wbgl-hidden">
                <div class="wbgl-note-title" data-i18n="modals.paste.error.title">❌ فشل الاستخراج</div>
                <div id="errorMessage"></div>
                <div id="missingFieldsList" class="wbgl-mt-8 wbgl-note-text"></div>
            </div>
        </div>

        <div class="wbgl-input-modal__footer">
            <button id="btnCancelPaste" type="button" class="wbgl-btn wbgl-btn--ghost" data-i18n="modals.common.cancel">إلغاء</button>
            <button id="btnProcessPaste" type="button" class="wbgl-btn wbgl-btn--primary" data-i18n="modals.paste.actions.process_and_add">✨ تحليل وإضافة</button>
        </div>
    </div>
</div>
