<!-- Excel Import Modal -->
<div id="excelImportModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
    <div style="background: white; width: 100%; max-width: 500px; border-radius: 12px; box-shadow: 0 25px 50px rgba(0,0,0,0.3); overflow: hidden;">
        <div style="background: #f9fafb; padding: 20px 24px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="font-size: 18px; font-weight: 700; color: #1f2937; display: flex; align-items: center; gap: 8px; margin: 0;">
                📊 استيراد ملف Excel
            </h3>
            <button id="btnCloseExcelModal" style="color: #9ca3af; background: none; border: none; font-size: 32px; line-height: 1; cursor: pointer; padding: 0;" onmouseover="this.style.color='#4b5563'" onmouseout="this.style.color='#9ca3af'">&times;</button>
        </div>
        
        <div style="padding: 24px;">
            <div style="margin-bottom: 16px; background: #dbeafe; color: #1e40af; padding: 12px; border-radius: 8px; font-size: 14px; display: flex; gap: 8px;">
                💡
                <div>
                    اختر ملف Excel (.xlsx أو .xls) يحتوي على بيانات الضمانات. تأكد من وجود أعمدة: المورد، البنك، رقم الضمان.
                </div>
            </div>

            <div style="margin-bottom: 16px;">
                <label class="file-upload-label" style="display: flex; flex-direction: column; align-items: center; gap: 12px; padding: 32px; border: 2px dashed #d1d5db; border-radius: 8px; cursor: pointer; background: #f9fafb;" onmouseover="this.style.background='#f3f4f6'; this.style.borderColor='#3b82f6'" onmouseout="this.style.background='#f9fafb'; this.style.borderColor='#d1d5db'">
                    <input type="file" id="excelFileInput" accept=".xlsx,.xls" style="display: none;">
                    <div style="font-size: 48px;">📄</div>
                    <div style="font-weight: 600; color: #1f2937;">انقر لاختيار ملف</div>
                    <div id="selectedFileName" style="font-size: 12px; color: #6b7280;">لم يتم اختيار ملف</div>
                </label>
            </div>
            
            <!-- Test Data Option -->
            <?php 
            $settings = \App\Support\Settings::getInstance();
            if (!$settings->isProductionMode()):
            ?>
            <div style="padding: 16px; background: #fef3c7; border: 1px solid #fbbf24; border-radius: 8px;">
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 14px; font-weight: 600; color: #92400e;">
                    <input type="checkbox" id="excelIsTestData" style="width: 18px; height: 18px; cursor: pointer;" onchange="document.getElementById('excelTestFields').style.display = this.checked ? 'block' : 'none'">
                    🧪 تمييز كبيانات تجريبية (لأغراض الاختبار فقط)
                </label>
                
                <div id="excelTestFields" style="display: none; margin-top: 12px; padding-top: 12px; border-top: 1px solid #fbbf24;">
                    <div style="margin-bottom: 12px;">
                        <label style="display: block; margin-bottom: 4px; font-size: 12px; font-weight: 600; color: #451a03;">معرّف الدفعة (Batch ID)</label>
                        <input type="text" id="excelTestBatchId" placeholder="مثل: EXCEL-TEST-BATCH-001" style="width: 100%; padding: 8px; border: 1px solid #fbbf24; border-radius: 4px; font-size: 13px;">
                    </div>
                    <div>
                        <label style="display: block; margin-bottom: 4px; font-size: 12px; font-weight: 600; color: #451a03;">ملاحظة (اختياري)</label>
                        <input type="text" id="excelTestNote" placeholder="مثل: اختبار استيراد Excel" style="width: 100%; padding: 8px; border: 1px solid #fbbf24; border-radius: 4px; font-size: 13px;">
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div style="background: #f9fafb; padding: 16px 24px; display: flex; justify-content: flex-end; gap: 12px; border-top: 1px solid #e5e7eb;">
            <button id="btnCancelExcel" style="padding: 8px 16px; color: #4b5563; background: transparent; border: none; border-radius: 8px; font-weight: 500; cursor: pointer;" onmouseover="this.style.background='#f3f4f6'" onmouseout="this.style.background='transparent'">إلغاء</button>
            <button id="btnUploadExcel" style="padding: 12px 24px; background: #059669; color: white; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 8px; box-shadow: 0 4px 6px rgba(5, 150, 105, 0.3);" onmouseover="this.style.background='#047857'" onmouseout="this.style.background='#059669'" disabled>
                📤 رفع وتحليل
            </button>
        </div>
    </div>
</div>
