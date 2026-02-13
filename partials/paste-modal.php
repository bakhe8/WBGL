<!-- Smart Paste Modal -->
<div id="smartPasteModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
    <div style="background: white; width: 100%; max-width: 700px; border-radius: 12px; box-shadow: 0 25px 50px rgba(0,0,0,0.3); overflow: hidden; max-height: 90vh; display: flex; flex-direction: column;">
        <div style="background: #f9fafb; padding: 20px 24px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="font-size: 18px; font-weight: 700; color: #1f2937; display: flex; align-items: center; gap: 8px; margin: 0;">
                📋 لصق بيانات ذكي (Smart Paste)
            </h3>
            <button id="btnClosePasteModal" style="color: #9ca3af; background: none; border: none; font-size: 32px; line-height: 1; cursor: pointer; padding: 0;" onmouseover="this.style.color='#4b5563'" onmouseout="this.style.color='#9ca3af'">&times;</button>
        </div>
        
        <div style="padding: 24px; overflow-y: auto; flex: 1;">
            <div style="margin-bottom: 16px; background: #dbeafe; color: #1e40af; padding: 12px; border-radius: 8px; font-size: 14px; display: flex; gap: 8px;">
                💡
                <div>
                    قم بنسخ نص الإيميل أو الطلب ولصقه هنا. سيقوم النظام باستخراج البيانات تلقائياً.
                </div>
            </div>

            <textarea id="smartPasteInput" style="width: 100%; height: 192px; padding: 16px; border: 2px dashed #d1d5db; border-radius: 8px; background: #f9fafb; font-family: monospace; font-size: 14px; line-height: 1.5; resize: vertical;" placeholder="مثال: يرجى إصدار ضمان بنكي بمبلغ 50,000 ريال لصالح شركة المراعي..." onfocus="this.style.background='white'; this.style.borderColor='#3b82f6'" onblur="this.style.background='#f9fafb'; this.style.borderColor='#d1d5db'"></textarea>
            
            <!-- Extraction Results Preview (Enhanced with Confidence) -->
            <div id="extractionPreview" style="margin-top: 16px; display: none;">
                <div style="background: #f0fdf4; border: 1px solid #86efac; border-radius: 8px; padding: 16px; margin-bottom: 12px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                        <div style="font-size: 14px; font-weight: 700; color: #15803d;">✅ نتائج الاستخراج</div>
                        <div id="overallConfidence" style="font-size: 12px; font-weight: 600;"></div>
                    </div>
                    <div id="extractionFields" style="display: flex; flex-direction: column; gap: 12px;"></div>
                </div>
            </div>


            <!-- Test Data Option -->
            <?php 
            $settings = \App\Support\Settings::getInstance();
            if (!$settings->isProductionMode()):
            ?>
            <div style="margin-top: 16px; padding: 16px; background: #fef3c7; border: 1px solid #fbbf24; border-radius: 8px;">
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 14px; font-weight: 600; color: #92400e;">
                    <input type="checkbox" id="pasteIsTestData" style="width: 18px; height: 18px; cursor: pointer;" onchange="document.getElementById('pasteTestFields').style.display = this.checked ? 'block' : 'none'">
                    🧪 تمييز كضمان تجريبي (لأغراض الاختبار فقط)
                </label>
                
                <div id="pasteTestFields" style="display: none; margin-top: 12px; padding-top: 12px; border-top: 1px solid #fbbf24;">
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px;">
                        <div>
                            <label style="display: block; margin-bottom: 4px; font-size: 12px; font-weight: 600; color: #451a03;">معرّف الدفعة (Batch ID)</label>
                            <input type="text" id="pasteTestBatchId" placeholder="مثل: TEST-BATCH-001" style="width: 100%; padding: 8px; border: 1px solid #fbbf24; border-radius: 4px; font-size: 13px;">
                        </div>
                        <div>
                            <label style="display: block; margin-bottom: 4px; font-size: 12px; font-weight: 600; color: #451a03;">ملاحظة (اختياري)</label>
                            <input type="text" id="pasteTestNote" placeholder="مثل: اختبار Smart Paste" style="width: 100%; padding: 8px; border: 1px solid #fbbf24; border-radius: 4px; font-size: 13px;">
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div id="smartPasteError" style="margin-top: 12px; padding: 12px; background: #fee2e2; border: 1px solid #fca5a5; border-radius: 8px; color: #dc2626; font-size: 14px; display: none;">
                <div style="font-weight: 700; margin-bottom: 8px;">❌ فشل الاستخراج</div>
                <div id="errorMessage"></div>
                <div id="missingFieldsList" style="margin-top: 8px; font-size: 13px;"></div>
            </div>
        </div>

        <div style="background: #f9fafb; padding: 16px 24px; display: flex; justify-content: flex-end; gap: 12px; border-top: 1px solid #e5e7eb;">
            <button id="btnCancelPaste" style="padding: 8px 16px; color: #4b5563; background: transparent; border: none; border-radius: 8px; font-weight: 500; cursor: pointer;" onmouseover="this.style.background='#f3f4f6'" onmouseout="this.style.background='transparent'">إلغاء</button>
            <button id="btnProcessPaste" style="padding: 12px 24px; background: #2563eb; color: white; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 8px; box-shadow: 0 4px 6px rgba(37, 99, 235, 0.3);" onmouseover="this.style.background='#1d4ed8'" onmouseout="this.style.background='#2563eb'">
                ✨ تحليل وإضافة
            </button>
        </div>
    </div>
</div>
