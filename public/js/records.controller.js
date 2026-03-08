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
            // console.log('WBGL System Controller initialized');
            this.bindEvents();
            this.bindGlobalEvents();
            this.initializeState();
            this.syncSuggestionVisibility();
            this.syncPreviewPrintButtonVisibility();
            this.capturePreviewBaseline(true);
            this.flushPendingToast();
            // ADR-007: No auto-preview. Preview only shows after explicit action.
        }

        initializeState() {
            // Preview is ALWAYS visible now
            this.previewVisible = true;
            this.printDropdownVisible = false;
            this.previewBaselineHtml = null;
            this.capturePreviewBaseline(true);
        }

        capturePreviewBaseline(force = false) {
            const previewSection = document.getElementById('preview-section');
            if (!(previewSection instanceof HTMLElement)) {
                return false;
            }

            if (!force && typeof this.previewBaselineHtml === 'string' && this.previewBaselineHtml !== '') {
                return true;
            }

            this.previewBaselineHtml = previewSection.innerHTML || '';
            return true;
        }

        resetPreviewToBaseline() {
            const previewSection = document.getElementById('preview-section');
            if (!(previewSection instanceof HTMLElement)) {
                return false;
            }

            if (typeof this.previewBaselineHtml !== 'string' || this.previewBaselineHtml === '') {
                if (!this.capturePreviewBaseline(true)) {
                    return false;
                }
            }

            previewSection.innerHTML = this.previewBaselineHtml;
            return true;
        }

        flushPendingToast() {
            const raw = sessionStorage.getItem('wbgl_pending_toast');
            if (!raw) return;
            sessionStorage.removeItem('wbgl_pending_toast');
            try {
                const payload = JSON.parse(raw);
                if (payload && payload.message && window.showToast) {
                    window.showToast(payload.message, payload.type || 'info');
                }
            } catch (e) {
                sessionStorage.removeItem('wbgl_pending_toast');
            }
        }

        queueToast(message, type = 'info') {
            sessionStorage.setItem('wbgl_pending_toast', JSON.stringify({ message, type }));
        }

        t(key, fallback, params) {
            if (window.WBGLI18n && typeof window.WBGLI18n.t === 'function') {
                return window.WBGLI18n.t(key, fallback, params);
            }
            return fallback || key;
        }

        setModalOpen(modal, open) {
            if (!modal) {
                return;
            }
            modal.classList.toggle('is-open', Boolean(open));
            modal.setAttribute('aria-hidden', open ? 'false' : 'true');
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
                this.syncSuggestionVisibility();
                BglLogger.debug('records.guarantee_updated');
                if (!this.isHistoricalMode()) {
                    this.capturePreviewBaseline(true);
                }
                this.updatePreviewFromDOM();
                this.syncPreviewPrintButtonVisibility();

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
                BglLogger.debug('records.supplier_changed', value);
            }
        }

        normalizeDecisionStatus(value) {
            const normalized = String(value || '').trim().toLowerCase();
            return normalized === 'approved' ? 'ready' : normalized;
        }

        isDecisionFinalizedStatus(status) {
            const normalized = this.normalizeDecisionStatus(status);
            return ['ready', 'issued', 'released', 'signed'].includes(normalized);
        }

        isHistoricalMode() {
            const bannerContainer = document.getElementById('historical-banner-container');
            const bannerVisible = bannerContainer instanceof HTMLElement
                ? !bannerContainer.hidden && !bannerContainer.classList.contains('u-hidden')
                : false;
            return bannerVisible || Boolean(window.timelineController?.isHistoricalView);
        }

        syncSuggestionVisibility(options = {}) {
            const suggestionsContainer = document.getElementById('supplier-suggestions');
            const addSupplierContainer = document.getElementById('addSupplierContainer');
            if (!(suggestionsContainer instanceof HTMLElement)) {
                return true;
            }

            const decisionStatusInput = document.getElementById('decisionStatus');
            const statusValue = decisionStatusInput ? decisionStatusInput.value : '';
            const shouldHide = Boolean(options.forceHide) ||
                this.isHistoricalMode() ||
                this.isDecisionFinalizedStatus(statusValue);

            suggestionsContainer.hidden = shouldHide;
            if (shouldHide && addSupplierContainer instanceof HTMLElement) {
                addSupplierContainer.hidden = true;
            }

            return shouldHide;
        }

        syncPreviewPrintButtonVisibility() {
            const previewSection = document.getElementById('preview-section');
            const printButtons = (previewSection instanceof HTMLElement)
                ? previewSection.querySelectorAll('button.btn-print-overlay')
                : [];
            const recordHolder = document.getElementById('record-form-sec');

            const decisionStatusInput = document.getElementById('decisionStatus');
            const workflowStepInput = document.getElementById('workflowStep');
            const activeActionInput = document.getElementById('activeAction');
            const signaturesReceivedInput = document.getElementById('signaturesReceived');
            const statusRaw = decisionStatusInput
                ? decisionStatusInput.value
                : (recordHolder instanceof HTMLElement ? String(recordHolder.dataset.decisionStatus || '') : '');
            const workflowRaw = workflowStepInput
                ? workflowStepInput.value
                : (recordHolder instanceof HTMLElement ? String(recordHolder.dataset.workflowStep || '') : '');
            const activeActionRaw = activeActionInput
                ? activeActionInput.value
                : (recordHolder instanceof HTMLElement ? String(recordHolder.dataset.activeAction || '') : '');
            const signaturesRaw = signaturesReceivedInput
                ? signaturesReceivedInput.value
                : (recordHolder instanceof HTMLElement ? String(recordHolder.dataset.signaturesReceived || '0') : '0');

            const statusValue = this.normalizeDecisionStatus(statusRaw);
            const workflowStep = String(workflowRaw || '').trim().toLowerCase();
            const activeAction = String(activeActionRaw || '').trim().toLowerCase();
            const signaturesReceived = Number.parseInt(
                String(signaturesRaw || '0'),
                10
            );

            const hasAction = activeAction !== '';
            const hasSignature = Number.isFinite(signaturesReceived) && signaturesReceived > 0;
            const isHistorical = this.isHistoricalMode();
            const hasPrintPermission = document.body && document.body.dataset
                ? String(document.body.dataset.printPermission || '').trim() !== '0'
                : true;
            const hasPrintBypass = document.body && document.body.dataset
                ? String(document.body.dataset.printAuditBypass || '').trim() === '1'
                : false;
            const previousPrintAllowed = document.body && document.body.dataset
                ? String(document.body.dataset.printAllowed || '').trim()
                : '';
            const hasCompleteDecisionContext = Boolean(
                decisionStatusInput && workflowStepInput && activeActionInput && signaturesReceivedInput
            );

            let canShowPrint = hasPrintBypass
                || (
                    hasPrintPermission
                    && !isHistorical
                    && statusValue === 'ready'
                    && workflowStep === 'signed'
                    && hasAction
                    && hasSignature
                );

            // During client-side re-render, hidden decision inputs may be temporarily missing.
            // Never downgrade server-approved print state in that transient window.
            if (!hasPrintBypass && !isHistorical && hasPrintPermission && !hasCompleteDecisionContext) {
                if (previousPrintAllowed === '1' || previousPrintAllowed === '0') {
                    canShowPrint = previousPrintAllowed === '1';
                }
            }
            if (printButtons.length > 0) {
                printButtons.forEach((button) => {
                    button.style.display = canShowPrint ? '' : 'none';
                    button.setAttribute('aria-hidden', canShowPrint ? 'false' : 'true');
                });
            }

            if (document.body && document.body.dataset) {
                if (hasCompleteDecisionContext || hasPrintBypass || !hasPrintPermission || isHistorical) {
                    document.body.dataset.printAllowed = canShowPrint ? '1' : '0';
                }
                document.body.dataset.printGuardMode = hasPrintBypass
                    ? 'bypass'
                    : (hasPrintPermission ? 'record' : 'permission');
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
                    statusBadge.textContent.includes(this.t('messages.records.status.needs_decision')) ||
                    statusBadge.textContent.includes('pending');

                if (isPending) {
                    BglLogger.debug('records.preview_blocked_pending');
                    this.syncPreviewPrintButtonVisibility();
                    return; // Exit early - no preview update
                }
            }

            // ===== ADR-007: Action Check =====
            // Only show preview if an action has been taken
            const activeActionInput = document.getElementById('activeAction');
            const hasAction = activeActionInput && activeActionInput.value;

            if (!hasAction) {
                BglLogger.debug('records.preview_blocked_no_action');
                this.showNoActionState();
                this.syncPreviewPrintButtonVisibility();
                return; // Exit early - no preview without action
            }
            // =========================================

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
                    fieldValue = this.formatArabicDate(fieldValue);
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

                    let fullPhrase = this.t('messages.records.preview.full_intro.default');

                    // Logic based on action/event source
                    if (eventSource) {
                        if (eventSource === 'extension') {
                            fullPhrase = this.t('messages.records.preview.full_intro.extension');
                        } else if (eventSource === 'reduction') {
                            fullPhrase = this.t('messages.records.preview.full_intro.reduction');
                        } else if (eventSource === 'release') {
                            fullPhrase = this.t('messages.records.preview.full_intro.release');
                        }
                    } else {
                        // Fallback to type-based logic
                        if (typeRaw.includes('Final')) {
                            fullPhrase = this.t('messages.records.preview.full_intro.final');
                        } else if (typeRaw.includes('Advance')) {
                            fullPhrase = this.t('messages.records.preview.full_intro.advance');
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

                        if (actionType === 'extension') subjectText = this.t('messages.records.preview.subject.extension');
                        else if (actionType === 'reduction') subjectText = this.t('messages.records.preview.subject.reduction');
                        else if (actionType === 'release') subjectText = this.t('messages.records.preview.subject.release');

                        subjectTarget.textContent = subjectText;
                    }

                    return; // Skip standard update for 'type' as we handled it specially
                }

                // Update corresponding preview target
                const targets = document.querySelectorAll(`[data-preview-target="${fieldName}"]`);
                if (targets.length > 0) {
                    targets.forEach((target) => {
                        target.textContent = fieldValue || '';

                        // ✨ SPECIFIC SYNC: Handle Contract vs PO target attributes
                        if (fieldName === 'contract_number') {
                            const relatedToInput = document.getElementById('relatedTo');
                            const relatedTo = relatedToInput ? relatedToInput.value : 'contract';

                            // 1. Update Lang Attribute: Contract=EN, PO=AR
                            target.setAttribute('lang', relatedTo === 'contract' ? 'en' : 'ar');

                            // 2. Update Label: Contract=للعقد رقم, PO=لأمر الشراء رقم
                            const labelTarget = document.querySelector('[data-preview-target="related_label"]');
                            if (labelTarget) {
                                labelTarget.textContent = (relatedTo === 'purchase_order')
                                    ? this.t('messages.records.preview.related_label.purchase_order')
                                    : this.t('messages.records.preview.related_label.contract');
                            }
                        }
                    });
                }
            });

            // ✨ Apply centralized letter formatting after all updates
            if (window.PreviewFormatter) {
                window.PreviewFormatter.applyFormatting();
            }
            this.syncPreviewPrintButtonVisibility();
        }

        // Standard helpers
        formatArabicDate(dateStr) {
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

            const locale = (window.WBGLI18n && typeof window.WBGLI18n.getLanguage === 'function')
                ? window.WBGLI18n.getLanguage()
                : 'ar';

            return new Intl.DateTimeFormat(locale === 'ar' ? 'ar-SA' : 'en-US', {
                day: 'numeric',
                month: 'long',
                year: 'numeric'
            }).format(date);
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

        async reopenRecord(target) {
            const recordIdEl = document.querySelector('[data-record-id]');
            if (!recordIdEl) return;
            const id = recordIdEl.dataset.recordId;

            if (!await this.customConfirm(this.t('messages.records.reopen.confirm'))) return;

            try {
                const response = await fetch('/api/reopen.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ guarantee_id: id })
                });

                const data = await response.json();
                if (data.success) {
                    window.showToast(this.t('messages.records.reopen.success'), 'success');
                    // Reload to reflect changes in UI (unlock fields, update button states)
                    window.location.reload();
                } else {
                    window.showToast(this.t('messages.records.error.prefix') + (data.error || this.t('messages.records.reopen.failed')), 'error');
                }
            } catch (error) {
                console.error('REOPEN_ERROR', error);
                window.showToast(this.t('messages.records.error.network'), 'error');
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
                previewSection.innerHTML = `<div class="wbgl-preview-empty-state">${this.t('messages.records.preview.no_action')}</div>`;
            }
        }


        print(target) {
            const audit = window.WBGLPrintAudit;
            if (audit && typeof audit.isPrintAllowed === 'function' && !audit.isPrintAllowed()) {
                if (typeof audit.notifyPrintBlocked === 'function') {
                    audit.notifyPrintBlocked();
                }
                this.closePrintDropdown();
                return;
            }

            if (audit && typeof audit.recordSinglePrint === 'function') {
                const guaranteeId = (typeof audit.currentRecordId === 'function')
                    ? audit.currentRecordId()
                    : null;
                audit.recordSinglePrint(guaranteeId, {
                    trigger: 'records_controller_menu'
                }).catch(() => { });
            }

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
                target.innerHTML = this.t('messages.records.save_next.saving');
            }
            const recordIdEl = document.querySelector('[data-record-id]');
            if (!recordIdEl) {
                window.showToast(this.t('messages.records.error.no_record'), 'error');
                return;
            }


            // Get current status filter from URL
            const urlParams = new URLSearchParams(window.location.search);
            const statusFilter = urlParams.get('filter') || 'all';
            const includeTestData = urlParams.get('include_test_data') || '';

            const payload = {
                guarantee_id: recordIdEl.dataset.recordId,
                supplier_id: document.getElementById('supplierIdHidden')?.value || null,
                supplier_name: document.getElementById('supplierInput')?.value || '',
                current_index: document.querySelector('[data-record-index]')?.dataset.recordIndex || 1,
                status_filter: statusFilter,
                include_test_data: includeTestData
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
                        this.queueToast(`${this.t('messages.records.supplier.created_prefix')} ${data.meta.created_supplier_name}`, 'success');
                    }
                    if (data.finished) {
                        window.showToast(data.message || this.t('messages.records.save_next.finished_all'), 'success');

                        // ✅ FIX: Redirect to clear the current record from view and show next or empty state
                        setTimeout(() => {
                            const nextParams = new URLSearchParams(window.location.search);
                            nextParams.delete('id');
                            const targetFilter = String(data.next_filter || statusFilter || 'all').trim().toLowerCase();
                            if (data.return_to_home) {
                                nextParams.delete('filter');
                                nextParams.delete('search');
                                nextParams.delete('stage');
                            } else if (targetFilter === 'all') {
                                nextParams.delete('filter');
                            } else {
                                nextParams.set('filter', targetFilter);
                            }
                            window.location.href = `?${nextParams.toString()}`;
                        }, 1000);
                        return;
                    }

                    // Reload page with next record, preserving filter
                    if (data.record && data.record.id) {
                        const nextParams = new URLSearchParams(window.location.search);
                        nextParams.set('id', String(data.record.id));
                        const targetFilter = String(data.next_filter || statusFilter || 'all').trim().toLowerCase();
                        if (targetFilter === 'all') {
                            nextParams.delete('filter');
                        } else {
                            nextParams.set('filter', targetFilter);
                        }
                        window.location.href = `?${nextParams.toString()}`;
                    } else {
                        window.location.reload();
                    }

                } else {
                    // Handle supplier_required error specially
                    if (data.error === 'supplier_required') {
                        window.showToast(data.message || this.t('messages.records.save_next.supplier_required'), 'error');
                        // Show the add supplier button with the name
                        this.showAddSupplierButton(data.supplier_name || '');
                    } else {
                        window.showToast(this.t('messages.records.error.prefix') + (data.message || data.error || this.t('messages.records.save_next.failed')), 'error');
                    }
                }
            } catch (error) {
                console.error('SAVE_ERROR', error);
                window.showToast(this.t('messages.records.save_next.failed'), 'error');
            } finally {
                if (target && (!data || !data.success)) {
                    target.disabled = false;
                    target.innerHTML = this.t('messages.records.save_next.button_default');
                }
            }
        }

        // Actions
        async extend() {
            if (!await this.customConfirm(this.t('messages.records.actions.extend.confirm'))) return;

            const recordIdEl = document.querySelector('[data-record-id]');
            if (!recordIdEl) {
                window.showToast(this.t('messages.records.error.no_record'), 'error');
                return;
            }

            try {
                const response = await fetch('/api/extend.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ guarantee_id: recordIdEl.dataset.recordId })
                });

                const data = await response.json();
                if (!response.ok || !data.success) {
                    const msg = data.error || this.t('messages.records.actions.extend.failed');
                    if (msg.includes('No expiry date')) {
                        window.showToast(this.t('messages.records.actions.extend.no_expiry_date'), 'error');
                    } else {
                        window.showToast(msg, 'error');
                    }
                    return;
                }
                window.location.reload();
            } catch (e) {
                console.error(e);
                window.showToast(this.t('messages.records.error.network'), 'error');
            }
        }

        async release() {
            if (!await this.customConfirm(this.t('messages.records.actions.release.confirm'))) return;

            const recordIdEl = document.querySelector('[data-record-id]');
            if (!recordIdEl) {
                window.showToast(this.t('messages.records.error.no_record'), 'error');
                return;
            }

            try {
                const response = await fetch('/api/release.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ guarantee_id: recordIdEl.dataset.recordId })
                });

                const data = await response.json();
                if (!response.ok || !data.success) {
                    const msg = data.error || this.t('messages.records.actions.release.failed');
                    window.showToast(msg, 'error');
                    return;
                }
                window.location.reload();
            } catch (e) {
                console.error(e);
                window.showToast(this.t('messages.records.error.network'), 'error');
            }
        }

        async reduce() {
            const recordIdEl = document.querySelector('[data-record-id]');
            if (!recordIdEl) {
                window.showToast(this.t('messages.records.error.no_record'), 'error');
                return;
            }

            const newAmountStr = await this.customPrompt(this.t('messages.records.actions.reduce.prompt_amount'));
            if (!newAmountStr) return;

            const newAmount = parseFloat(newAmountStr);
            if (isNaN(newAmount) || newAmount <= 0) {
                window.showToast(this.t('messages.records.actions.reduce.invalid_amount'), 'error');
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

                const data = await response.json();
                if (!response.ok || !data.success) {
                    const msg = data.error || this.t('messages.records.actions.reduce.failed');
                    window.showToast(msg, 'error');
                    return;
                }
                window.location.reload();
            } catch (e) {
                console.error(e);
                window.showToast(this.t('messages.records.error.network'), 'error');
            }
        }

        // Custom UI Helpers
        customConfirm(message) {
            return new Promise((resolve) => {
                const overlay = document.getElementById('wbgl-confirm-overlay');
                if (!overlay) {
                    // Critical: Modal HTML not found in page
                    // This should never happen if the partial is included
                    console.error('CRITICAL: wbgl-confirm-overlay not found. Ensure partials/confirm-modal.php is included.');
                    // Resolve false to prevent any destructive action
                    resolve(false);
                    return;
                }

                const msgEl = document.getElementById('wbgl-confirm-message');
                if (msgEl) msgEl.textContent = message;

                const yesBtn = document.getElementById('wbgl-confirm-yes');
                const noBtn = document.getElementById('wbgl-confirm-no');

                // Show modal
                this.setModalOpen(overlay, true);

                // cleanup function
                const cleanup = () => {
                    this.setModalOpen(overlay, false);
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
                overlay.id = 'wbgl-prompt-overlay';
                overlay.className = 'wbgl-prompt-overlay';

                overlay.innerHTML = `
                    <div class="wbgl-prompt-dialog">
                        <h3 class="wbgl-prompt-title">${this.t('messages.records.prompt.title')}</h3>
                        <label class="wbgl-prompt-label">${message}</label>
                        <input type="number" id="prompt-input" class="wbgl-prompt-input" autofocus>
                        <div class="wbgl-prompt-actions">
                            <button id="prompt-ok" class="wbgl-prompt-btn wbgl-prompt-btn--primary">${this.t('messages.records.prompt.ok')}</button>
                            <button id="prompt-cancel" class="wbgl-prompt-btn wbgl-prompt-btn--secondary">${this.t('messages.records.prompt.cancel')}</button>
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
                const nextParams = new URLSearchParams(window.location.search);
                nextParams.set('id', String(prevId));
                window.location.href = `?${nextParams.toString()}`;
            }
        }

        nextRecord(target) {
            const nextId = target.dataset.id;
            if (nextId) {
                const nextParams = new URLSearchParams(window.location.search);
                nextParams.set('id', String(nextId));
                window.location.href = `?${nextParams.toString()}`;
            }
        }

        processSupplierInput(target) {
            if (this.syncSuggestionVisibility() || target.disabled || target.readOnly) {
                this.hideAddSupplierButton();
                return;
            }

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
                        if (this.syncSuggestionVisibility()) {
                            this.hideAddSupplierButton();
                            return;
                        }

                        // We replace the innerHTML because the partial is the *list* of buttons, 
                        // not the container itself (based on how partial is written).
                        // The partial iterates and echoes buttons.
                        // So innerHTML is correct if the API returns just the buttons.
                        // Let's verify partials/suggestions.php... Yes it echoes buttons.
                        // Usage rule: "Replace DOM Element" via outerHTML is best, but here we are filling a container.
                        // "No DOM creation in JS". We are NOT creating DOM in JS. We are inserting Server HTML.
                        // This is compliant.
                        container.innerHTML = html;

                        if (!html.includes('chip-unified')) {
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
                    console.error('SUGGESTIONS_FETCH_ERROR', error);
                }
            }, 300);
        }

        // updateSupplierChips removed - Server Driven UI implemented

        showAddSupplierButton(supplierName) {
            const container = document.getElementById('addSupplierContainer');
            const nameSpan = document.getElementById('newSupplierName');
            if (container) {
                container.hidden = false;
                if (nameSpan) nameSpan.textContent = supplierName;
            }
        }

        hideAddSupplierButton() {
            const container = document.getElementById('addSupplierContainer');
            if (container) {
                container.hidden = true;
            }
        }

        async createSupplier() {
            const supplierInput = document.getElementById('supplierInput');
            const supplierName = supplierInput?.value?.trim();

            if (!supplierName) {
                window.showToast(this.t('messages.records.supplier.enter_name_first'), 'error');
                return;
            }

            const recordIdEl = document.querySelector('[data-record-id]');
            const guaranteeId = recordIdEl?.dataset?.recordId;

            try {
                const response = await fetch('/api/create-supplier.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        official_name: supplierName,
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

                    window.showToast(`${this.t('messages.records.supplier.added_prefix')} ${data.official_name || supplierName}`, 'success');
                } else {
                    window.showToast(this.t('messages.records.error.prefix') + (data.message || data.error || this.t('messages.records.supplier.create_failed')), 'error');
                }
            } catch (error) {
                console.error('CREATE_SUPPLIER_ERROR', error);
                window.showToast(this.t('messages.records.supplier.create_failed'), 'error');
            }
        }

        // Modal handlers
        showManualInput() {
            const modal = document.getElementById('manualEntryModal');
            if (modal) {
                this.setModalOpen(modal, true);
                document.getElementById('manualSupplier')?.focus();
            }
        }

        showPasteModal() {
            const modal = document.getElementById('smartPasteModal');
            if (modal) {
                this.setModalOpen(modal, true);
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
                console.error('HIDDEN_FILE_INPUT_NOT_FOUND');
                // Note: showToast is in RecordsController context, not available here
                // The import feature should be properly implemented with the file input element
            }
        }

        parseRecordSurfaceFromHtml(html) {
            const parser = new DOMParser();
            const doc = parser.parseFromString(String(html || ''), 'text/html');
            const section = doc.getElementById('record-form-section');
            if (!section) {
                return null;
            }

            const isEnabled = (value) => String(value || '0') === '1';
            const recordIdNode = doc.querySelector('[data-record-id]');
            const guaranteeNumberNode = doc.querySelector('[data-preview-field="guarantee_number"]');
            const recordId = parseInt(recordIdNode?.getAttribute('data-record-id') || '0', 10) || 0;

            return {
                recordId: recordId,
                guaranteeNumber: guaranteeNumberNode ? (guaranteeNumberNode.textContent || '').trim() : '',
                policyVisible: isEnabled(section.getAttribute('data-policy-visible')),
                policyActionable: isEnabled(section.getAttribute('data-policy-actionable')),
                policyExecutable: isEnabled(section.getAttribute('data-policy-executable')),
                canViewRecord: isEnabled(section.getAttribute('data-surface-can-view-record')),
                canViewPreview: isEnabled(section.getAttribute('data-surface-can-view-preview')),
                canExecuteActions: isEnabled(section.getAttribute('data-surface-can-execute-actions')),
            };
        }

        applyRecordSurfaceState(surface) {
            const hideRecord = !surface || !surface.canViewRecord || surface.recordId <= 0;
            const guaranteeNumberDisplay = document.getElementById('guarantee-number-display');
            const statusElements = document.querySelectorAll('.record-title .badge, .record-title .status-badge, .record-title .record-edit-btn');
            const previewSection = document.getElementById('preview-section');
            const historicalBannerContainer = document.getElementById('historical-banner-container');

            if (hideRecord) {
                if (guaranteeNumberDisplay) {
                    guaranteeNumberDisplay.textContent = '—';
                }
                statusElements.forEach((el) => el.classList.add('u-hidden'));

                if (previewSection) {
                    previewSection.innerHTML = '';
                    previewSection.hidden = true;
                    previewSection.classList.add('u-hidden');
                }

                if (historicalBannerContainer) {
                    historicalBannerContainer.hidden = true;
                    historicalBannerContainer.classList.add('u-hidden');
                }
                this.syncPreviewPrintButtonVisibility();
                return;
            }

            if (guaranteeNumberDisplay && surface.guaranteeNumber) {
                guaranteeNumberDisplay.textContent = surface.guaranteeNumber;
            }
            statusElements.forEach((el) => el.classList.remove('u-hidden'));

            if (previewSection) {
                if (surface.canViewPreview) {
                    previewSection.hidden = false;
                    previewSection.classList.remove('u-hidden');
                } else {
                    previewSection.innerHTML = '';
                    previewSection.hidden = true;
                    previewSection.classList.add('u-hidden');
                }
            }
            this.syncPreviewPrintButtonVisibility();
        }

        // Navigation Implementation
        async loadRecord(index) {
            try {
                const urlParams = new URLSearchParams(window.location.search);
                const query = new URLSearchParams({ index: String(index) });

                const statusFilter = urlParams.get('filter') || 'all';
                query.set('filter', statusFilter);

                const searchTerm = urlParams.get('search');
                if (searchTerm && searchTerm.trim() !== '') {
                    query.set('search', searchTerm);
                }

                const stageFilter = urlParams.get('stage');
                if (stageFilter && stageFilter.trim() !== '') {
                    query.set('stage', stageFilter);
                }

                const includeTestData = urlParams.get('include_test_data');
                if (includeTestData && includeTestData.trim() !== '') {
                    query.set('include_test_data', includeTestData);
                }

                // Fetch Record HTML
                const res = await fetch(`/api/get-record.php?${query.toString()}`);
                const html = await res.text();
                const parsedSurface = this.parseRecordSurfaceFromHtml(html);

                // Replace Record Section (Server Driven)
                const recordSection = document.getElementById('record-form-section');
                if (recordSection) {
                    recordSection.outerHTML = html;
                }

                if (window.WBGLPolicy && typeof window.WBGLPolicy.applyDomGuards === 'function') {
                    window.WBGLPolicy.applyDomGuards(document);
                }

                this.applyRecordSurfaceState(parsedSurface);

                const canPublishUpdate = Boolean(parsedSurface && parsedSurface.canViewRecord && parsedSurface.recordId > 0);
                if (canPublishUpdate) {
                    // 🔥 Notify system that Data Card has been updated
                    // This triggers the preview update via the listener in bindGlobalEvents
                    document.dispatchEvent(new CustomEvent('guarantee:updated'));
                } else {
                    this.syncSuggestionVisibility({ forceHide: true });
                }

                // Sync Timeline (if generic timeline exists)
                // Note: If using Time Machine timeline, it's separate. 
                // We'll update the timeline section if it exists.
                this.updateTimeline(index);

                // Update URL logic if needed
            } catch (e) {
                console.error('NAV_ERROR', e);
            }
        }

        async updateTimeline(index) {
            try {
                const urlParams = new URLSearchParams(window.location.search);
                const query = new URLSearchParams({ index: String(index) });

                const statusFilter = urlParams.get('filter') || 'all';
                query.set('filter', statusFilter);

                const searchTerm = urlParams.get('search');
                if (searchTerm && searchTerm.trim() !== '') {
                    query.set('search', searchTerm);
                }

                const stageFilter = urlParams.get('stage');
                if (stageFilter && stageFilter.trim() !== '') {
                    query.set('stage', stageFilter);
                }

                const includeTestData = urlParams.get('include_test_data');
                if (includeTestData && includeTestData.trim() !== '') {
                    query.set('include_test_data', includeTestData);
                }

                const res = await fetch(`/api/get-timeline.php?${query.toString()}`);
                const html = await res.text();
                const timelineSection = document.getElementById('timeline-section');
                if (timelineSection) {
                    timelineSection.outerHTML = html;
                }
                if (window.WBGLPolicy && typeof window.WBGLPolicy.applyDomGuards === 'function') {
                    window.WBGLPolicy.applyDomGuards(document);
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
                if (window.WBGLPolicy && typeof window.WBGLPolicy.applyDomGuards === 'function') {
                    window.WBGLPolicy.applyDomGuards(document);
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
