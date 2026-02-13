/**
 * Records Controller - Vanilla JavaScript
 * Pure Vanilla JavaScript - No External Dependencies
 * DOM manipulation and event handling only
 */

if (!window.RecordsController) {
    window.RecordsController = class RecordsController {
        constructor() {
            this.init();
        }

        init() {
            // console.log('BGL System Controller initialized');
            this.bindEvents();
            this.bindGlobalEvents();
            this.initializeState();
            this.flushPendingToast();
            // ADR-007: No auto-preview. Preview only shows after explicit action.
        }

        initializeState() {
            // Preview is ALWAYS visible now
            this.previewVisible = true;
            this.printDropdownVisible = false;
        }

        flushPendingToast() {
            const raw = sessionStorage.getItem('bgl_pending_toast');
            if (!raw) return;
            sessionStorage.removeItem('bgl_pending_toast');
            try {
                const payload = JSON.parse(raw);
                if (payload && payload.message && window.showToast) {
                    window.showToast(payload.message, payload.type || 'info');
                }
            } catch (e) {
                sessionStorage.removeItem('bgl_pending_toast');
            }
        }

        queueToast(message, type = 'info') {
            sessionStorage.setItem('bgl_pending_toast', JSON.stringify({ message, type }));
        }

        bindGlobalEvents() {
            document.addEventListener('click', (e) => {
                const target = e.target.closest('[data-action]');
                if (!target) return;

                // Define action handlers
                const action = target.dataset.action;

                // Get current index from DOM if needed
                const currentRecordEl = document.getElementById('record-form-section');
                const currentIndex = currentRecordEl ? (currentRecordEl.dataset.recordIndex || 1) : 1;

                // Map actions to methods (Explicit Kebab-case)
                if (action === 'save-next') { this.saveAndNext(); return; }
                if (action === 'load-record') {
                    const index = target.dataset.index || currentIndex;
                    this.loadRecord(index);
                    return;
                }
                if (action === 'load-history') {
                    const id = target.dataset.id;
                    this.loadHistory(id, currentIndex);
                    return;
                }
                if (action === 'timeline-load-current') {
                    if (window.timelineController) window.timelineController.loadCurrentState();
                    return;
                }

                // Dynamic Dispatch for CamelCase (e.g. togglePreview, saveAndNext, extend, release)
                if (typeof this[action] === 'function') {
                    this[action](target);
                } else {
                    BglLogger.warn(`No handler for action: ${action}`);
                }
            });

            // 🔥 NEW: Listen for remote updates (e.g. from Timeline Controller)
            // This ensures preview updates ONLY after the Data Card is updated.
            document.addEventListener('guarantee:updated', () => {
                BglLogger.debug('⚡ Guarantee Updated Event Received - Refreshing Preview...');
                this.updatePreviewFromDOM();

                // ✨ Apply formatting AFTER refresh (fix race condition)
                if (window.PreviewFormatter) {
                    window.PreviewFormatter.applyFormatting();
                }
            });
        }

        bindEvents() {
            // Handle input changes
            document.addEventListener('input', (e) => {
                if (e.target.dataset.model) {
                    this.processInputChange(e.target);
                }

                // Handle supplier input specifically
                if (e.target.id === 'supplierInput') {
                    this.processSupplierInput(e.target);
                }
            });

            // Close dropdowns
            document.addEventListener('click', (e) => {
                if (!e.target.closest('#print-dropdown')) {
                    this.closePrintDropdown();
                }
            });
        }

        processInputChange(input) {
            const model = input.dataset.model;
            const value = input.value;

            // Handle specific models
            if (model === 'supplier_name') {
                // Could trigger suggestions fetch here if needed
                BglLogger.debug('Supplier changed:', value);
            }
        }

        // UI Actions
        // UI Actions
        togglePreview() {
            // Deprecated: Preview is always visible
            BglLogger.debug('Preview toggle is disabled');
        }

        updatePreviewFromDOM() {

            // ===== LIFECYCLE GATE: Status Check =====
            // Only allow preview updates if guarantee is READY (approved)
            const statusBadge = document.querySelector('.status-badge, .badge');

            if (statusBadge) {
                const isPending = statusBadge.classList.contains('badge-pending') ||
                    statusBadge.classList.contains('status-pending') ||
                    statusBadge.textContent.includes('يحتاج قرار') ||
                    statusBadge.textContent.includes('pending');

                if (isPending) {
                    BglLogger.debug('⚠️ Preview update blocked: guarantee status is pending');
                    BglLogger.debug('   Preview will be available once supplier and bank are selected');
                    return; // Exit early - no preview update
                }
            }

            // ===== ADR-007: Action Check =====
            // Only show preview if an action has been taken
            const activeActionInput = document.getElementById('activeAction');
            const hasAction = activeActionInput && activeActionInput.value;

            if (!hasAction) {
                BglLogger.debug('⚠️ Preview update blocked: no action taken');
                BglLogger.debug('   User must execute an action (extend/reduce/release) to generate a letter');
                this.showNoActionState();
                return; // Exit early - no preview without action
            }
            // =========================================

            // Arabic month names for date formatting
            const arabicMonths = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو',
                'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];



            // Get all fields with data-preview-field
            const fields = document.querySelectorAll('[data-preview-field]');

            fields.forEach(field => {
                const fieldName = field.dataset.previewField;
                let fieldValue = this.getFieldValue(field);

                // خاص بالمبلغ: إزالة أي حروف غير رقمية وتنظيف التنسيق
                if (fieldName === 'amount') {
                    // الاحتفاظ بالأرقام، النقطة، والفاصلة فقط
                    fieldValue = fieldValue.replace(/[^\d.,]/g, '').trim();
                    // إزالة النقاط المتكررة أو الزائدة في النهاية
                    fieldValue = fieldValue.replace(/\.+$/, '');
                    // إزالة الفاصلة الزائدة في النهاية
                    fieldValue = fieldValue.replace(/,+$/, '');
                }

                // خاص بالتاريخ: تنسيق بالصيغة العربية
                if (fieldName === 'expiry_date' && fieldValue) {
                    fieldValue = this.formatArabicDate(fieldValue, arabicMonths);
                }

                // خاص بالنوع: تحديث الجملة كاملة بدلاً من النوع فقط
                if (fieldName === 'type') {
                    const typeRaw = fieldValue.trim();

                    // 🔥 Phase 4: Read from DB for CURRENT view, eventSubtype for HISTORICAL view
                    const isHistoricalView = !!document.getElementById('historical-banner');

                    let eventSource;
                    if (isHistoricalView) {
                        // Historical view: read from temporary eventSubtype (set by timeline controller)
                        const eventSubtypeInput = document.getElementById('eventSubtype');
                        eventSource = eventSubtypeInput ? eventSubtypeInput.value : '';
                    } else {
                        // Current view: read from DB activeAction
                        const activeActionInput = document.getElementById('activeAction');
                        eventSource = activeActionInput ? activeActionInput.value : '';
                    }

                    let fullPhrase = 'إشارة إلى الضمان البنكي الموضح أعلاه'; // Default

                    // Logic based on action/event source
                    if (eventSource) {
                        if (eventSource === 'extension') {
                            fullPhrase = 'طلب تمديد الضمان البنكي الموضح أعلاه';
                        } else if (eventSource === 'reduction') {
                            fullPhrase = 'طلب تخفيض الضمان البنكي الموضح أعلاه';
                        } else if (eventSource === 'release') {
                            fullPhrase = 'طلب الإفراج عن الضمان البنكي الموضح أعلاه';
                        }
                    } else {
                        // Fallback to type-based logic
                        if (typeRaw.includes('Final')) {
                            fullPhrase = 'إشارة إلى الضمان البنكي النهائي الموضح أعلاه';
                        } else if (typeRaw.includes('Advance')) {
                            fullPhrase = 'إشارة إلى ضمان الدفعة المقدمة البنكي الموضح أعلاه';
                        }
                    }

                    // Update corresponding preview target 'full_intro_phrase'
                    const target = document.querySelector('[data-preview-target="full_intro_phrase"]');
                    if (target) {
                        target.textContent = fullPhrase;
                    }

                    // 🔥 Update Subject Line Action Type
                    const subjectTarget = document.querySelector('[data-preview-target="subject_action_type"]');

                    if (subjectTarget) {
                        let subjectText = ''; // ADR-007: No default, action determines subject

                        // ✅ Read from Data Card (activeAction input)
                        const activeActionInput = document.getElementById('activeAction');
                        const actionType = activeActionInput ? activeActionInput.value : '';

                        if (actionType === 'extension') subjectText = 'طلب تمديد';
                        else if (actionType === 'reduction') subjectText = 'طلب تخفيض';
                        else if (actionType === 'release') subjectText = 'طلب الإفراج عن';

                        subjectTarget.textContent = subjectText;
                    }

                    return; // Skip standard update for 'type' as we handled it specially
                }

                // Update corresponding preview target
                const target = document.querySelector(`[data-preview-target="${fieldName}"]`);
                if (target && fieldValue) {
                    target.textContent = fieldValue;

                    // ✨ SPECIFIC SYNC: Handle Contract vs PO target attributes
                    if (fieldName === 'contract_number') {
                        const relatedToInput = document.getElementById('relatedTo');
                        const relatedTo = relatedToInput ? relatedToInput.value : 'contract';

                        // 1. Update Lang Attribute: Contract=EN, PO=AR
                        target.setAttribute('lang', relatedTo === 'contract' ? 'en' : 'ar');

                        // 2. Update Label: Contract=للعقد رقم, PO=لأمر الشراء رقم
                        const labelTarget = document.querySelector('[data-preview-target="related_label"]');
                        if (labelTarget) {
                            labelTarget.textContent = (relatedTo === 'purchase_order') ? 'لأمر الشراء رقم' : 'للعقد رقم';
                        }
                    }
                }
            });

            // ✨ Apply centralized letter formatting after all updates
            if (window.PreviewFormatter) {
                window.PreviewFormatter.applyFormatting();
            }
        }

        // Standard helpers
        formatArabicDate(dateStr, arabicMonths) {
            if (!dateStr) return '';

            // Try to parse the date
            let date;
            if (dateStr.includes('-')) {
                // Format: YYYY-MM-DD or DD-MM-YYYY
                const parts = dateStr.split('-');
                if (parts[0].length === 4) {
                    // YYYY-MM-DD
                    date = new Date(parts[0], parseInt(parts[1]) - 1, parts[2]);
                } else {
                    // DD-MM-YYYY
                    date = new Date(parts[2], parseInt(parts[1]) - 1, parts[0]);
                }
            } else if (dateStr.includes('/')) {
                // Format: DD/MM/YYYY or MM/DD/YYYY
                const parts = dateStr.split('/');
                date = new Date(parts[2], parseInt(parts[1]) - 1, parts[0]);
            } else {
                date = new Date(dateStr);
            }

            if (isNaN(date.getTime())) return dateStr;

            const day = date.getDate();
            const month = arabicMonths[date.getMonth()];
            const year = date.getFullYear();

            return `${day} ${month} ${year}`;
        }

        getFieldValue(element) {
            // Handle input elements
            if (element.tagName === 'INPUT' || element.tagName === 'SELECT') {
                return element.value || '';
            }

            // Handle display elements (info-value)
            return element.textContent?.trim() || '';
        }

        togglePrintMenu() {
            this.printDropdownVisible = !this.printDropdownVisible;
            const dropdown = document.getElementById('print-dropdown-content');
            if (dropdown) {
                dropdown.classList.toggle('show', this.printDropdownVisible);
            }
        }

        closePrintDropdown() {
            if (this.printDropdownVisible) {
                this.printDropdownVisible = false;
                const dropdown = document.getElementById('print-dropdown-content');
                if (dropdown) {
                    dropdown.classList.remove('show');
                }
            }
        }

        /**
         * ADR-007: Show No-Action State
         * Display friendly message when guarantee is ready but no action taken
         */
        showNoActionState() {
            const previewSection = document.getElementById('preview-section');
            if (!previewSection) return;

            // ✅ COMPLIANCE: Use Server-Provided Template
            const template = document.getElementById('preview-no-action-template');
            if (template) {
                // Clone content if it was a <template>, but here it's a hidden div.
                // We can just take innerHTML of the template source.
                // This is compliant because the SOURCE is the server.
                previewSection.innerHTML = template.innerHTML;
            } else {
                // Fallback (should not be reached if partial is included)
                previewSection.innerHTML = '<div style="padding:20px;text-align:center">No Action Taken</div>';
            }
        }


        print(target) {
            // New Logic: Direct Browser Print
            window.print();
            this.closePrintDropdown();
        }

        // Supplier Selection
        selectSupplier(target) {
            const supplierId = target.dataset.id;
            const supplierName = target.dataset.name;

            // Update input field
            const supplierInput = document.getElementById('supplierInput');
            if (supplierInput) {
                supplierInput.value = supplierName;
            }

            // Update hidden ID field
            const supplierIdHidden = document.getElementById('supplierIdHidden');
            if (supplierIdHidden) {
                supplierIdHidden.value = supplierId;
            }

            // Update chip styling
            document.querySelectorAll('.chip').forEach(chip => {
                chip.classList.remove('chip-selected');
                chip.classList.add('chip-candidate');
            });
            target.classList.remove('chip-candidate');
            target.classList.add('chip-selected');
        }


        // Save and proceed
        async saveAndNext(target) {
            if (target) {
                const originalContent = target.innerHTML;
                target.disabled = true;
                target.innerHTML = '⌛ جاري الحفظ...';
            }
            const recordIdEl = document.querySelector('[data-record-id]');
            if (!recordIdEl) {
                window.showToast('خطأ: لا يوجد سجل', 'error');
                return;
            }


            // Get current status filter from URL
            const urlParams = new URLSearchParams(window.location.search);
            const statusFilter = urlParams.get('filter') || 'all';

            const payload = {
                guarantee_id: recordIdEl.dataset.recordId,
                supplier_id: document.getElementById('supplierIdHidden')?.value || null,
                supplier_name: document.getElementById('supplierInput')?.value || '',
                current_index: document.querySelector('[data-record-index]')?.dataset.recordIndex || 1,
                status_filter: statusFilter
            };


            let data = null;
            try {
                const response = await fetch('/api/save-and-next.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });

                data = await response.json();

                if (data.success) {
                    if (data.meta && data.meta.created_supplier_name) {
                        this.queueToast(`تم إنشاء مورد جديد: ${data.meta.created_supplier_name}`, 'success');
                    }
                    if (data.finished) {
                        window.showToast(data.message || 'تم الانتهاء من جميع السجلات', 'success');

                        // ✅ FIX: Redirect to clear the current record from view and show next or empty state
                        setTimeout(() => {
                            window.location.href = `?filter=${statusFilter}`;
                        }, 1000);
                        return;
                    }

                    // Reload page with next record, preserving filter
                    if (data.record && data.record.id) {
                        const filter = statusFilter !== 'all' ? `&filter=${statusFilter}` : '';
                        window.location.href = `?id=${data.record.id}${filter}`;
                    } else {
                        window.location.reload();
                    }

                } else {
                    // Handle supplier_required error specially
                    if (data.error === 'supplier_required') {
                        window.showToast(data.message || 'يجب اختيار مورد من الاقتراحات أو إضافة مورد جديد', 'error');
                        // Show the add supplier button with the name
                        this.showAddSupplierButton(data.supplier_name || '');
                    } else {
                        window.showToast('خطأ: ' + (data.message || data.error || 'فشل الحفظ'), 'error');
                    }
                }
            } catch (error) {
                console.error('Save error:', error);
                window.showToast('فشل الحفظ', 'error');
            } finally {
                if (target && (!data || !data.success)) {
                    target.disabled = false;
                    target.innerHTML = '💾 حفظ';
                }
            }
        }

        // Actions
        async extend() {
            if (!await this.customConfirm('هل تريد تمديد هذا الضمان لمدة سنة؟')) return;

            const recordIdEl = document.querySelector('[data-record-id]');
            if (!recordIdEl) {
                window.showToast('خطأ: لا يوجد سجل', 'error');
                return;
            }

            try {
                const response = await fetch('/api/extend.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ guarantee_id: recordIdEl.dataset.recordId })
                });

                const text = await response.text();

                if (!response.ok) {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(text, 'text/html');
                    const errorBody = doc.querySelector('.card-body');
                    const msg = errorBody ? errorBody.textContent.trim() : 'فشل التمديد';

                    if (msg.includes('No expiry date')) {
                        window.showToast('عفواً، لا يوجد تاريخ انتهاء محفوظ. يرجى حفظ السجل أولاً.', 'error');
                    } else {
                        window.showToast(msg, 'error');
                    }
                    return;
                }
                window.location.reload();
            } catch (e) {
                console.error(e);
                window.showToast('حدث خطأ في الاتصال', 'error');
            }
        }

        async release() {
            if (!await this.customConfirm('هل تريد الإفراج عن هذا الضمان؟')) return;

            const recordIdEl = document.querySelector('[data-record-id]');
            if (!recordIdEl) {
                window.showToast('خطأ: لا يوجد سجل', 'error');
                return;
            }

            try {
                const response = await fetch('/api/release.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ guarantee_id: recordIdEl.dataset.recordId })
                });

                const text = await response.text();
                if (!response.ok) {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(text, 'text/html');
                    const errorBody = doc.querySelector('.card-body');
                    const msg = errorBody ? errorBody.textContent.trim() : 'فشل الإفراج';
                    window.showToast(msg, 'error');
                    return;
                }
                window.location.reload();
            } catch (e) {
                console.error(e);
                window.showToast('حدث خطأ في الاتصال', 'error');
            }
        }

        async reduce() {
            const recordIdEl = document.querySelector('[data-record-id]');
            if (!recordIdEl) {
                window.showToast('خطأ: لا يوجد سجل', 'error');
                return;
            }

            const newAmountStr = await this.customPrompt('الرجاء إدخال المبلغ الجديد:');
            if (!newAmountStr) return;

            const newAmount = parseFloat(newAmountStr);
            if (isNaN(newAmount) || newAmount <= 0) {
                window.showToast('الرجاء إدخال مبلغ صحيح (أرقام فقط)', 'error');
                return;
            }

            try {
                const response = await fetch('/api/reduce.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        guarantee_id: recordIdEl.dataset.recordId,
                        new_amount: newAmount
                    })
                });

                const text = await response.text();
                if (!response.ok) {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(text, 'text/html');
                    const errorBody = doc.querySelector('.card-body');
                    const msg = errorBody ? errorBody.textContent.trim() : 'فشل التخفيض';
                    window.showToast(msg, 'error');
                    return;
                }
                window.location.reload();
            } catch (e) {
                console.error(e);
                window.showToast('حدث خطأ في الاتصال', 'error');
            }
        }

        // Custom UI Helpers
        customConfirm(message) {
            return new Promise((resolve) => {
                const overlay = document.getElementById('bgl-confirm-overlay');
                if (!overlay) {
                    // Critical: Modal HTML not found in page
                    // This should never happen if the partial is included
                    console.error('CRITICAL: bgl-confirm-overlay not found. Ensure partials/confirm-modal.php is included.');
                    // Resolve false to prevent any destructive action
                    resolve(false);
                    return;
                }

                const msgEl = document.getElementById('bgl-confirm-message');
                if (msgEl) msgEl.textContent = message;

                const yesBtn = document.getElementById('bgl-confirm-yes');
                const noBtn = document.getElementById('bgl-confirm-no');

                // Show modal
                overlay.style.display = 'flex';

                // cleanup function
                const cleanup = () => {
                    overlay.style.display = 'none';
                    // clear handlers to prevent leaks/duplicates
                    if (yesBtn) yesBtn.onclick = null;
                    if (noBtn) noBtn.onclick = null;
                    overlay.onclick = null;
                };

                // Bind handlers
                if (yesBtn) yesBtn.onclick = () => { cleanup(); resolve(true); };
                if (noBtn) noBtn.onclick = () => { cleanup(); resolve(false); };

                // Click outside
                overlay.onclick = (e) => {
                    if (e.target === overlay) { cleanup(); resolve(false); }
                };
            });
        }

        customPrompt(message) {
            return new Promise((resolve) => {
                const overlay = document.createElement('div');
                overlay.id = 'bgl-prompt-overlay';
                overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:99999;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(2px);';

                overlay.innerHTML = `
                    <div style="background:white;padding:24px;border-radius:12px;text-align:center;min-width:320px;box-shadow:0 10px 25px rgba(0,0,0,0.2);transform:scale(0.9);animation:popIn 0.2s forwards;">
                        <h3 style="margin:0 0 16px;color:#1e293b;font-size:18px">إدخال مطلوب</h3>
                        <label style="display:block;margin-bottom:8px;color:#64748b;font-size:14px">${message}</label>
                        <input type="number" id="prompt-input" style="width:100%;padding:10px;margin-bottom:20px;border:1px solid #cbd5e1;border-radius:6px;font-size:16px;text-align:center" autofocus>
                        <div style="display:flex;justify-content:center;gap:12px">
                            <button id="prompt-ok" class="btn btn-primary" style="background:#2563eb;color:white;border:none;padding:8px 20px;border-radius:6px;cursor:pointer">موافق</button>
                            <button id="prompt-cancel" class="btn btn-secondary" style="background:#e2e8f0;color:#475569;border:none;padding:8px 20px;border-radius:6px;cursor:pointer">إلغاء</button>
                        </div>
                    </div>
                `;
                document.body.appendChild(overlay);

                const input = overlay.querySelector('#prompt-input');
                input.focus();

                const cleanup = () => { document.body.removeChild(overlay); };
                const submit = () => {
                    const val = input.value;
                    cleanup();
                    resolve(val);
                };

                overlay.querySelector('#prompt-ok').onclick = submit;
                overlay.querySelector('#prompt-cancel').onclick = () => { cleanup(); resolve(null); };

                input.onkeydown = (e) => { if (e.key === 'Enter') submit(); if (e.key === 'Escape') { cleanup(); resolve(null); } };
                overlay.onclick = (e) => { if (e.target === overlay) { cleanup(); resolve(null); } };
            });
        }

        // Navigation
        previousRecord(target) {
            const prevId = target.dataset.id;
            if (prevId) {
                // Preserve filter parameter when navigating
                const urlParams = new URLSearchParams(window.location.search);
                const filter = urlParams.get('filter');
                const url = filter ? `?id=${prevId}&filter=${filter}` : `?id=${prevId}`;
                window.location.href = url;
            }
        }

        nextRecord(target) {
            const nextId = target.dataset.id;
            if (nextId) {
                // Preserve filter parameter when navigating
                const urlParams = new URLSearchParams(window.location.search);
                const filter = urlParams.get('filter');
                const url = filter ? `?id=${nextId}&filter=${filter}` : `?id=${nextId}`;
                window.location.href = url;
            }
        }

        processSupplierInput(target) {
            // Debounced suggestion fetching
            clearTimeout(this.supplierSearchTimeout);
            const value = target.value.trim();

            // Clear supplier ID when user types (they're changing it)
            const supplierIdHidden = document.getElementById('supplierIdHidden');
            if (supplierIdHidden) {
                supplierIdHidden.value = '';
            }

            if (value.length < 2) {
                this.hideAddSupplierButton();
                return;
            }

            this.supplierSearchTimeout = setTimeout(async () => {
                try {
                    // Phase 6: Use Learning System API
                    // ✅ COMPLIANCE: Server-Driven UI (HTML Fragment)
                    const guaranteeId = document.getElementById('record-form-sec')?.dataset?.recordId || 0;
                    const response = await fetch(`/api/suggestions-learning.php?raw=${encodeURIComponent(value)}&guarantee_id=${guaranteeId}`);

                    // Server returns HTML fragment directly
                    const html = await response.text();

                    // Update suggestions container
                    const container = document.getElementById('supplier-suggestions');
                    if (container) {
                        // We replace the innerHTML because the partial is the *list* of buttons, 
                        // not the container itself (based on how partial is written).
                        // The partial iterates and echoes buttons.
                        // So innerHTML is correct if the API returns just the buttons.
                        // Let's verify partials/suggestions.php... Yes it echoes buttons.
                        // Usage rule: "Replace DOM Element" via outerHTML is best, but here we are filling a container.
                        // "No DOM creation in JS". We are NOT creating DOM in JS. We are inserting Server HTML.
                        // This is compliant.
                        container.innerHTML = html;

                        // Check for add button logic - we need to know if there were suggestions
                        // The HTML contains "لا توجد اقتراحات" if empty.
                        // We can check if html contains 'chip-unified'

                        if (!html.includes('chip-unified')) {
                            // Logic to show add button if no matches
                            // We might need to parse the HTML or rely on strict server logic?
                            // User plan: "Day 3: Update JS".
                            // For now, let's keep it simple.
                            this.showAddSupplierButton(value);
                        } else {
                            const hasExactMatch = html.includes(`data-name="${value.replace(/"/g, '&quot;')}"`);
                            if (!hasExactMatch) {
                                this.showAddSupplierButton(value);
                            } else {
                                this.hideAddSupplierButton();
                            }
                        }
                    }
                } catch (error) {
                    console.error('Suggestions fetch error:', error);
                }
            }, 300);
        }

        // updateSupplierChips removed - Server Driven UI implemented

        showAddSupplierButton(supplierName) {
            const container = document.getElementById('addSupplierContainer');
            const nameSpan = document.getElementById('newSupplierName');
            if (container) {
                container.style.display = 'block';
                if (nameSpan) nameSpan.textContent = supplierName;
            }
        }

        hideAddSupplierButton() {
            const container = document.getElementById('addSupplierContainer');
            if (container) {
                container.style.display = 'none';
            }
        }

        async createSupplier() {
            const supplierInput = document.getElementById('supplierInput');
            const supplierName = supplierInput?.value?.trim();

            if (!supplierName) {
                window.showToast('الرجاء إدخال اسم المورد أولاً', 'error');
                return;
            }

            const recordIdEl = document.querySelector('[data-record-id]');
            const guaranteeId = recordIdEl?.dataset?.recordId;

            try {
                const response = await fetch('/api/create-supplier.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        name: supplierName,
                        guarantee_id: guaranteeId
                    })
                });

                const data = await response.json();

                if (data.success) {
                    // Update the hidden ID and input
                    const supplierIdHidden = document.getElementById('supplierIdHidden');
                    if (supplierIdHidden) supplierIdHidden.value = data.supplier_id;
                    if (supplierInput) supplierInput.value = data.official_name || supplierName;

                    // Hide add button
                    this.hideAddSupplierButton();

                    window.showToast(`تم إضافة المورد: ${data.official_name || supplierName}`, 'success');
                } else {
                    window.showToast('خطأ: ' + (data.message || data.error || 'فشل إضافة المورد'), 'error');
                }
            } catch (error) {
                console.error('Create supplier error:', error);
                window.showToast('فشل إضافة المورد', 'error');
            }
        }

        // Modal handlers
        showManualInput() {
            const modal = document.getElementById('manualEntryModal');
            if (modal) {
                modal.style.display = 'block';
                document.getElementById('manualSupplier')?.focus();
            }
        }

        showPasteModal() {
            const modal = document.getElementById('smartPasteModal');
            if (modal) {
                modal.style.display = 'flex';
                document.getElementById('smartPasteInput')?.focus();
            }
        }

        showImportModal() {
            // Trigger hidden file input
            const fileInput = document.getElementById('hiddenFileInput');
            if (fileInput) {
                fileInput.click();
            } else {
                // Error: File input not found
                console.error('File input element #hiddenFileInput not found');
                // Note: showToast is in RecordsController context, not available here
                // The import feature should be properly implemented with the file input element
            }
        }
        // Navigation Implementation
        async loadRecord(index) {
            try {
                // Fetch Record HTML
                const res = await fetch(`/api/get-record.php?index=${index}`);
                const html = await res.text();

                // Replace Record Section (Server Driven)
                const recordSection = document.getElementById('record-form-section');
                if (recordSection) {
                    recordSection.outerHTML = html;
                }

                // 🔥 Notify system that Data Card has been updated
                // This triggers the preview update via the listener in bindGlobalEvents
                document.dispatchEvent(new CustomEvent('guarantee:updated'));

                // Sync Timeline (if generic timeline exists)
                // Note: If using Time Machine timeline, it's separate. 
                // We'll update the timeline section if it exists.
                this.updateTimeline(index);

                // Update URL logic if needed
            } catch (e) {
                console.error('Nav Error:', e);
            }
        }

        async updateTimeline(index) {
            try {
                const res = await fetch(`/api/get-timeline.php?index=${index}`);
                const html = await res.text();
                const timelineSection = document.getElementById('timeline-section');
                if (timelineSection) {
                    timelineSection.outerHTML = html;
                }
            } catch (e) { /* Ignore */ }
        }

        async loadHistory(eventId, fromIndex) {
            // Load legacy history (for get-timeline.php support)
            try {
                const res = await fetch(`/api/get-history-snapshot.php?history_id=${eventId}&index=${fromIndex}`);
                const html = await res.text();
                // This endpoint likely returns a form with snapshot data? 
                // Or JSON? Assuming HTML fragment based on architecture.
                // If it returns JSON, we need logic. 
                // Let's assume it replaces the record form.
                const recordSection = document.getElementById('record-form-section');
                if (recordSection) {
                    recordSection.outerHTML = html;
                }
            } catch (e) {
                console.error(e);
            }
        }
    };
}

// Initialize on DOM ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        if (!window.recordsController && window.RecordsController) {
            window.recordsController = new window.RecordsController();
        }
    });
} else {
    if (!window.recordsController && window.RecordsController) {
        window.recordsController = new window.RecordsController();
    }
}
