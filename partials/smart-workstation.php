<!-- Smart Workstation Overlay (Split-Screen) -->
<div id="smartWorkstation" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: white; z-index: 10000; overflow: hidden; flex-direction: column;">
    
    <!-- Header -->
    <header style="height: 60px; background: #1f2937; color: white; display: flex; align-items: center; justify-content: space-between; padding: 0 24px; flex-shrink: 0;">
        <div style="display: flex; align-items: center; gap: 16px;">
            <div style="font-size: 20px;">🖥️</div>
            <h2 style="margin: 0; font-size: 18px; font-weight: 700;">محطة العمل الذكية: إكمال بيانات الضمانات المتعددة</h2>
        </div>
        
        <div style="display: flex; align-items: center; gap: 12px;">
            <div id="workstationStatus" style="font-size: 14px; background: rgba(255,255,255,0.1); padding: 4px 12px; border-radius: 20px;">
                ضمان <span id="currentEntryIndex">1</span> من <span id="totalEntriesCount">1</span>
            </div>
            <button id="btnCloseWorkstation" style="background: rgba(255, 255, 255, 0.1); border: none; color: white; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-weight: 600;">❌ إغلاق</button>
        </div>
    </header>

    <!-- Main Split View -->
    <div style="display: flex; flex: 1; overflow: hidden;">
        
        <!-- Left Pillar: PDF Viewer -->
        <div style="flex: 1; background: #374151; display: flex; flex-direction: column; border-left: 1px solid #4b5563;">
            <div style="background: #4b5563; padding: 8px 16px; color: #e5e7eb; font-size: 13px; font-weight: 600; display: flex; justify-content: space-between;">
                <span>📄 المستند المصدر (PDF)</span>
                <span id="pdfFileName">جاري التحميل...</span>
            </div>
            <iframe id="workstationPdfViewer" src="about:blank" style="width: 100%; flex: 1; border: none;"></iframe>
        </div>

        <!-- Right Pillar: Form Entry -->
        <div style="width: 500px; background: #f9fafb; display: flex; flex-direction: column; flex-shrink: 0;">
            <div style="background: #f3f4f6; padding: 12px 24px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
                <span style="font-weight: 700; color: #374151;">📝 تفاصيل الضمان</span>
                <button class="btn btn-sm btn-outline-secondary" id="btnWorkstationReset" style="font-size: 12px;">🔄 تصفير الحقول</button>
            </div>

            <div style="flex: 1; padding: 24px; overflow-y: auto;">
                <form id="workstationForm">
                    <div style="display: flex; flex-direction: column; gap: 20px;">
                        
                        <!-- Individual Guarantee Details -->
                        <div style="padding: 16px; background: #eff6ff; border: 1px solid #3b82f6; border-radius: 8px;">
                            <div style="font-size: 12px; color: #1e40af; font-weight: 700; margin-bottom: 12px; display: flex; align-items: center; gap: 4px;">
                                <span>🆔 بيانات الضمان الفردي</span>
                            </div>
                            
                            <div style="display: flex; flex-direction: column; gap: 16px;">
                                <!-- Identity -->
                                <div>
                                    <label style="display: block; font-size: 13px; font-weight: 700; color: #1e40af; margin-bottom: 4px;">رقم الضمان *</label>
                                    <input type="text" id="wsGuarantee" class="field-input" placeholder="رقم الضمان كما يظهر في الـ PDF" required>
                                </div>

                                <!-- Entities (Unique per guarantee per user feedback) -->
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                                    <div>
                                        <label style="display: block; font-size: 13px; font-weight: 700; color: #1e40af; margin-bottom: 4px;">المورد</label>
                                        <input type="text" id="wsSupplier" class="field-input" placeholder="اسم المورد">
                                    </div>
                                    <div>
                                        <label style="display: block; font-size: 13px; font-weight: 700; color: #1e40af; margin-bottom: 4px;">البنك</label>
                                        <input type="text" id="wsBank" class="field-input" placeholder="اسم البنك">
                                    </div>
                                </div>

                                <!-- Financials & Contract -->
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                                    <div>
                                        <label style="display: block; font-size: 13px; font-weight: 700; color: #1e40af; margin-bottom: 4px;">المبلغ *</label>
                                        <input type="text" id="wsAmount" class="field-input" placeholder="0.00" required>
                                    </div>
                                    <div>
                                        <label style="display: block; font-size: 13px; font-weight: 700; color: #1e40af; margin-bottom: 4px;">رقم العقد</label>
                                        <input type="text" id="wsContract" class="field-input" placeholder="رقم العقد">
                                    </div>
                                </div>

                                <!-- Dates & Type -->
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                                    <div>
                                        <label style="display: block; font-size: 13px; font-weight: 700; color: #1e40af; margin-bottom: 4px;">تاريخ الانتهاء</label>
                                        <input type="date" id="wsExpiry" class="field-input">
                                    </div>
                                    <div>
                                        <label style="display: block; font-size: 13px; font-weight: 700; color: #1e40af; margin-bottom: 4px;">النوع</label>
                                        <select id="wsType" class="field-input" style="background: white;">
                                            <option value="">اختر النوع</option>
                                            <option value="FINAL">نهائي</option>
                                            <option value="ADVANCED">دفعة مقدمة</option>
                                            <option value="INITIAL">ابتدائي</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Additional Comments -->
                        <div>
                            <label style="display: block; font-size: 13px; font-weight: 700; color: #374151; margin-bottom: 4px;">ملاحظات الضمان</label>
                            <textarea id="wsComment" rows="3" class="field-input" placeholder="أي تفاصيل إضافية لهذا الضمان..."></textarea>
                        </div>
            </div>

            <!-- Footer: Navigation & Final Actions -->
            <div style="padding: 16px 24px; background: white; border-top: 1px solid #e5e7eb; display: flex; flex-direction: column; gap: 12px;">
                
                <!-- Nav -->
                <div style="display: grid; grid-template-columns: 1fr 1.5fr 1fr; gap: 8px; align-items: center;">
                    <button id="btnWsPrev" class="btn btn-secondary btn-sm" disabled>⬅️ السابق</button>
                    <button id="btnWsNext" class="btn btn-primary btn-sm" style="background: #3b82f6; border-color: #3b82f6;">➕ إضافة التالي</button>
                    <button id="btnWsNextHidden" style="display:none"></button> <!-- For form submission -->
                </div>

                <!-- Finalize -->
                <button id="btnWsFinish" class="btn btn-success" style="width: 100%; padding: 12px; font-weight: 800; background: #059669; border-color: #059669; font-size: 16px;">
                    ✅ حفظ الكل والإنهاء
                </button>
                <div style="font-size: 11px; color: #6b7280; text-align: center;">الضغط على "حفظ الكل" سيقوم بإنشاء السجلات في قاعدة البيانات دفعة واحدة</div>

            </div>
        </div>
    </div>
</div>

<style>
/* Adjust workstation for full screen */
#smartWorkstation .field-input {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 14px;
    outline: none;
    transition: all 0.2s;
}
#smartWorkstation .field-input:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}
</style>
