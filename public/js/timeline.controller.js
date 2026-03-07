/**
 * Timeline Handler - Time Machine Functionality
 * Handles click interactions on timeline events
 * Shows historical state of guarantee at any point in time
 */

// Safety fallback for BglLogger (Defined in main.js)
window.BglLogger = window.BglLogger || {
    debug: () => {},
    info: () => {},
    warn: () => {},
    error: (...args) => console.error(...args)
};

if (!window.TimelineController) {
    window.TimelineController = class TimelineController {
        constructor() {
            this.currentEventId = null;
            this.isHistoricalView = false;
            this.originalState = null;
            this.init();
        }

        t(key, params = null) {
            if (window.WBGLI18n && typeof window.WBGLI18n.t === 'function') {
                return window.WBGLI18n.t(key, key, params || undefined);
            }

            let output = key;
            if (params && typeof params === 'object') {
                Object.keys(params).forEach((token) => {
                    output = output.replace(new RegExp(`{{\\s*${token}\\s*}}`, 'g'), String(params[token]));
                });
            }
            return output;
        }

        init() {
            // Use event delegation for reliability
            // This works even if timeline cards are added dynamically
            document.addEventListener('click', (e) => {
                const eventWrapper = e.target.closest('.timeline-event-wrapper');
                if (eventWrapper) {
                    this.processTimelineClick(eventWrapper);
                }
            });

            BglLogger.debug('TL_INIT_OK');
        }

        normalizeEventId(rawId) {
            const text = String(rawId ?? '').trim();
            if (!text) {
                return null;
            }

            if (/^\d+$/.test(text)) {
                return parseInt(text, 10);
            }

            const match = text.match(/(\d+)$/);
            if (!match) {
                return null;
            }

            return parseInt(match[1], 10);
        }

        async processTimelineClick(element) {
            // DEBOUNCE: Prevent rapid double-clicks
            if (this.isProcessing) return;
            this.isProcessing = true;
            setTimeout(() => { this.isProcessing = false; }, 300);

            const eventId = this.normalizeEventId(element.dataset.eventId);
            if (eventId === null) {
                this.showError(this.t('timeline.error.event_id_unresolved'));
                return;
            }

            try {
                // Remove active class from all cards
                document.querySelectorAll('.timeline-event-wrapper').forEach(card => {
                    card.querySelector('.timeline-event-card')?.classList.remove('active-event');
                });

                // Add active class to clicked card
                element.querySelector('.timeline-event-card')?.classList.add('active-event');

                await this.displayHistoricalState(eventId, element);

            } catch (error) {
                console.error('TL_CLICK_ERR', error);
                this.showError(this.t('timeline.error.display_historical_state'));
            }
        }

        getCurrentIndex() {
            const currentRecordEl = document.getElementById('record-form-section');
            const fromDom = currentRecordEl ? parseInt(String(currentRecordEl.dataset.recordIndex || '1'), 10) : 1;
            if (Number.isInteger(fromDom) && fromDom > 0) {
                return fromDom;
            }

            const urlParams = new URLSearchParams(window.location.search);
            const fromQuery = parseInt(String(urlParams.get('index') || '1'), 10);
            return Number.isInteger(fromQuery) && fromQuery > 0 ? fromQuery : 1;
        }

        async fetchTimelineViewState(params) {
            const query = new URLSearchParams();
            if (params.historyId) {
                query.set('history_id', String(params.historyId));
            }
            if (params.guaranteeId) {
                query.set('guarantee_id', String(params.guaranteeId));
            }
            query.set('index', String(this.getCurrentIndex()));

            const response = await fetch(`/api/get-timeline-view-state.php?${query.toString()}`);
            const data = await response.json();
            if (!data || !data.success) {
                throw new Error((data && data.error) || 'TIMELINE_VIEW_STATE_FAILED');
            }
            return data;
        }

        applyServerStatePayload(payload) {
            if (!payload || typeof payload !== 'object') {
                throw new Error('Invalid server payload');
            }

            const recordHtml = String(payload.record_html || '');
            const previewHtml = String(payload.preview_html || '');

            const recordSection = document.getElementById('record-form-section');
            if (recordSection && recordHtml.trim() !== '') {
                recordSection.outerHTML = recordHtml;
            }

            const previewSection = document.getElementById('preview-section');
            if (previewSection && previewHtml.trim() !== '') {
                previewSection.outerHTML = previewHtml;
            } else if (previewSection) {
                previewSection.innerHTML = '';
                previewSection.hidden = true;
                previewSection.classList.add('u-hidden');
            }

            if (window.WBGLPolicy && typeof window.WBGLPolicy.applyDomGuards === 'function') {
                window.WBGLPolicy.applyDomGuards(document);
            }

            if (window.recordsController && typeof window.recordsController.capturePreviewBaseline === 'function') {
                window.recordsController.capturePreviewBaseline(true);
            }

            if (window.PreviewFormatter) {
                window.PreviewFormatter.applyFormatting();
            }

            this.normalizePreviewPrintButton();
            if (window.recordsController && typeof window.recordsController.syncSuggestionVisibility === 'function') {
                window.recordsController.syncSuggestionVisibility();
            }
            if (window.recordsController && typeof window.recordsController.syncPreviewPrintButtonVisibility === 'function') {
                window.recordsController.syncPreviewPrintButtonVisibility();
            }
        }

        async displayHistoricalState(eventId, eventElement) {
            BglLogger.debug('TL_SHOW_HISTORICAL_STATE_SERVER', { eventId: eventId });

            const data = await this.fetchTimelineViewState({ historyId: eventId });
            this.applyServerStatePayload(data);

            this.currentGuaranteeId = parseInt(String(data.guarantee_id || '0'), 10) || null;
            this.currentEventId = eventId;
            this.isHistoricalView = true;
            this.currentEventSubtype = String(data.event_subtype || eventElement?.dataset.eventSubtype || '');

            this.showHistoricalBanner(eventElement || null);
            this.highlightChanges(eventElement || null);

            const previewAction = String(data.preview_action || this.currentEventSubtype || '');
            const activeActionInput = document.getElementById('activeAction');
            if (activeActionInput) {
                activeActionInput.value = previewAction;
            }

            const eventSubtypeInput = document.getElementById('eventSubtype');
            if (eventSubtypeInput) {
                eventSubtypeInput.value = this.currentEventSubtype || '';
            }

            this.disableEditing();
        }

        normalizePreviewPrintButton() {
            const previewSection = document.getElementById('preview-section');
            if (!(previewSection instanceof HTMLElement)) {
                return;
            }

            const selectorParts = ['button.print-icon-btn', 'button.btn-print-overlay', 'button.wbgl-unified-print-btn'];
            const buttons = previewSection.querySelectorAll(selectorParts.join(','));
            if (!buttons.length) {
                return;
            }

            const printLabel = this.t('timeline.ui.print_letter');

            buttons.forEach((btn) => {
                btn.classList.remove('print-icon-btn', 'btn-print-overlay');
                btn.classList.add('btn-print-overlay', 'wbgl-unified-print-btn');

                if (!btn.onclick) {
                    btn.onclick = function () {
                        if (window.WBGLPrintAudit && window.WBGLPrintAudit.handleOverlayPrint) {
                            return window.WBGLPrintAudit.handleOverlayPrint(this);
                        }
                        window.print();
                        return false;
                    };
                }
                if (!btn.getAttribute('title')) {
                    btn.setAttribute('title', printLabel);
                }
                if (!btn.getAttribute('aria-label')) {
                    btn.setAttribute('aria-label', printLabel);
                }
            });
        }

        updateFormFields(snapshot) {
            BglLogger.debug('TL_UPDATE_FIELDS_FROM_SNAPSHOT', snapshot);

            // Update supplier input (ID: supplierInput)
            // Always update to prevent "leakage" from previous events
            const supplierInput = document.getElementById('supplierInput');
            if (supplierInput) {
                // Use official name OR raw name fallback
                const nameToShow = snapshot.supplier_name || snapshot.raw_supplier_name || '';
                supplierInput.value = nameToShow;
                BglLogger.debug('TL_SUPPLIER_UPDATED', nameToShow || 'CLEARED');
            }

            // Update hidden supplier ID (ID: supplierIdHidden)
            const supplierIdHidden = document.getElementById('supplierIdHidden');
            if (supplierIdHidden) {
                supplierIdHidden.value = snapshot.supplier_id || '';
                BglLogger.debug('TL_SUPPLIER_ID_UPDATED', snapshot.supplier_id || 'CLEARED');
            }

            // Bank is now in Info Grid - updated via label matching below

            // Update hidden bank ID (ID: bankSelect)
            const bankSelect = document.getElementById('bankSelect');
            if (bankSelect) {
                bankSelect.value = snapshot.bank_id || '';
                BglLogger.debug('TL_BANK_ID_UPDATED', snapshot.bank_id || 'CLEARED');
            }

            // Update info-value elements by matching labels
            const amountLabel = this.t('timeline.fields.amount');
            const expiryLabel = this.t('timeline.fields.expiry_date');
            const issueDateLabel = this.t('timeline.fields.issue_date');
            const bankLabel = this.t('timeline.fields.bank');
            const invalidAmount = this.t('timeline.ui.invalid_amount_currency');
            document.querySelectorAll('.info-item').forEach(item => {
                const label = item.querySelector('.info-label')?.textContent || '';
                const valueEl = item.querySelector('.info-value');

                if (!valueEl) return;

                // Amount - with NaN protection
                if (label.includes(amountLabel) && snapshot.amount) {
                    let amountValue = snapshot.amount;

                    // Convert string to number safely
                    if (typeof amountValue === 'string') {
                        amountValue = parseFloat(amountValue.replace(/[^\d.]/g, ''));
                    }

                    // Validate number
                    if (!isNaN(amountValue) && isFinite(amountValue)) {
                        const formattedAmount = new Intl.NumberFormat('en-US').format(amountValue);
                        valueEl.textContent = formattedAmount + ' ' + this.t('timeline.currency.sar');
                        BglLogger.debug('TL_AMOUNT_UPDATED', formattedAmount);
                    } else {
                        BglLogger.warn('TL_INVALID_AMOUNT', snapshot.amount);
                        valueEl.textContent = invalidAmount;
                    }
                }

                // Expiry date
                if (label.includes(expiryLabel) && snapshot.expiry_date) {
                    valueEl.textContent = snapshot.expiry_date;
                    BglLogger.debug('TL_EXPIRY_UPDATED', snapshot.expiry_date);
                }

                // Issue date
                if (label.includes(issueDateLabel) && snapshot.issue_date) {
                    valueEl.textContent = snapshot.issue_date;
                    BglLogger.debug('TL_ISSUE_DATE_UPDATED', snapshot.issue_date);
                }

                // Bank name
                if (label.includes(bankLabel) && snapshot.bank_name) {
                    valueEl.textContent = snapshot.bank_name;
                    BglLogger.debug('TL_BANK_UPDATED', snapshot.bank_name);
                }
            });

            // Update status badge
            const statusBadge = document.querySelector('.status-badge');
            if (statusBadge && snapshot.status) {
                this.updateStatusBadge(statusBadge, snapshot.status);
                BglLogger.debug('TL_STATUS_UPDATED', snapshot.status);
            }

            // Keep hidden decision status in sync with active snapshot/current state.
            const decisionStatusInput = document.getElementById('decisionStatus');
            if (decisionStatusInput && snapshot.status) {
                decisionStatusInput.value = snapshot.status;
            }

            // 🔥 Update Hidden Event Context (Bridge to RecordsController)
            const eventSubtypeInput = document.getElementById('eventSubtype');
            if (eventSubtypeInput) {
                // If snapshot has event_subtype, use it. Otherwise clear it.
                // Note: snapshot.event_subtype comes from backend createSnapshot or logEvent
                const subtype = snapshot.event_subtype || '';
                eventSubtypeInput.value = subtype;
                BglLogger.debug('TL_EVENT_CONTEXT_UPDATED', subtype || 'NONE');
            }
        }

        updateStatusBadge(badge, status) {
            // Remove all status classes
            badge.classList.remove('status-pending', 'status-approved', 'status-extended', 'status-released');

            // Add appropriate class
            badge.classList.add(`status-${status}`);

            const statusLabels = {
                pending: this.t('timeline.status.pending'),
                approved: this.t('timeline.status.approved'),
                ready: this.t('timeline.status.ready'),
                issued: this.t('timeline.status.issued'),
                extended: this.t('timeline.status.extended'),
                released: this.t('timeline.status.released'),
                reduced: this.t('timeline.status.reduced'),
            };

            badge.textContent = statusLabels[status] || status;
        }

        showHistoricalBanner(eventElement = null) {
            const bannerContainer = document.getElementById('historical-banner-container');
            if (bannerContainer) {
                bannerContainer.hidden = false;
                bannerContainer.classList.remove('u-hidden');

                if (eventElement) {
                    const hbTitle = document.getElementById('hb-title');
                    const hbSubtitle = document.getElementById('hb-subtitle');

                    if (hbTitle) {
                        const eventLabel = eventElement.querySelector('.timeline-event-label')?.textContent.trim() || this.t('timeline.banner.historical_copy');
                        hbTitle.textContent = this.t('timeline.banner.review_event', { event: eventLabel });
                    }

                    if (hbSubtitle) {
                        const createdAt = eventElement.querySelector('.timeline-event-meta span')?.textContent.trim() || '';
                        const actor = eventElement.querySelector('.timeline-event-user')?.textContent.trim() || this.t('timeline.system_actor');
                        hbSubtitle.textContent = this.t('timeline.banner.by_actor_at', { actor: actor, at: createdAt });
                    }
                }
            }
        }

        /**
         * 🔬 NEW: Highlight specific fields that changed in this event
         */
        highlightChanges(eventElement) {
            this.clearHighlights();
            if (!eventElement) return;

            try {
                const detailsRaw = eventElement.dataset.eventDetails;
                if (!detailsRaw) return;

                const details = JSON.parse(detailsRaw);
                const changes = details.changes || [];

                changes.forEach(change => {
                    const field = change.field;
                    // Find matching input or display element
                    const selectors = [
                        `[data-preview-field="${field}"]`,
                        `[name="${field}"]`,
                        `#${field}Input`
                    ];

                    selectors.forEach(selector => {
                        const el = document.querySelector(selector);
                        if (el) {
                            el.classList.add('historical-highlight');
                            // If it's a text element, also highlight the text
                            if (!['INPUT', 'SELECT', 'TEXTAREA'].includes(el.tagName)) {
                                el.classList.add('historical-highlight-text');
                            }
                        }
                    });
                });
            } catch (e) {
                BglLogger.warn('TL_HIGHLIGHT_FAIL', e);
            }
        }

        clearHighlights() {
            document.querySelectorAll('.historical-highlight, .historical-highlight-text').forEach(el => {
                el.classList.remove('historical-highlight', 'historical-highlight-text');
            });
        }

        removeHistoricalBanner() {
            // ✅ COMPLIANCE: Toggle Server-Side Element
            const bannerContainer = document.getElementById('historical-banner-container');
            if (bannerContainer) {
                bannerContainer.hidden = true;
                bannerContainer.classList.add('u-hidden');
            }
        }

        disableEditing() {
            // Disable all input fields
            const inputs = document.querySelectorAll('#supplierInput, #bankSelect');
            inputs.forEach(input => {
                input.disabled = true;
                input.classList.add('timeline-readonly-input');
            });

            if (window.recordsController && typeof window.recordsController.syncSuggestionVisibility === 'function') {
                window.recordsController.syncSuggestionVisibility({ forceHide: true });
            }

            // Hide action buttons (not just disable - prevent accidental interaction with history)
            const buttons = document.querySelectorAll('[data-action="extend"], [data-action="reduce"], [data-action="release"], [data-action="save-next"], [data-action="saveAndNext"]');
            buttons.forEach(btn => {
                btn.classList.add('timeline-history-hidden');
            });
        }

        enableEditing() {
            // Enable all input fields
            const inputs = document.querySelectorAll('#supplierInput, #bankSelect');
            inputs.forEach(input => {
                input.disabled = false;
                input.classList.remove('timeline-readonly-input');
            });

            // Show action buttons again
            const buttons = document.querySelectorAll('[data-action="extend"], [data-action="reduce"], [data-action="release"], [data-action="save-next"], [data-action="saveAndNext"]');
            buttons.forEach(btn => {
                btn.classList.remove('timeline-history-hidden');
            });

            if (window.recordsController && typeof window.recordsController.syncSuggestionVisibility === 'function') {
                window.recordsController.syncSuggestionVisibility();
            }
        }

        async loadCurrentState() {
            BglLogger.debug('TL_LOAD_CURRENT_STATE');

            this.removeHistoricalBanner();
            this.clearHighlights();

            // Get guarantee ID
            const currentId = this.currentGuaranteeId ||
                document.querySelector('[data-record-id]')?.dataset.recordId;

            if (!currentId) {
                console.error('No guarantee ID found');
                return;
            }

            try {
                const data = await this.fetchTimelineViewState({ guaranteeId: currentId });
                this.applyServerStatePayload(data);

                // Hide historical banner (this is Current State)
                this.removeHistoricalBanner();

                // Reset timeline state
                this.isHistoricalView = false;
                this.currentEventId = null;
                this.currentGuaranteeId = parseInt(String(currentId), 10) || null;
                this.currentEventSubtype = null;

                // Enable editing (show buttons, enable inputs)
                this.enableEditing();

                // Re-activate latest timeline event
                document.querySelectorAll('.timeline-event-wrapper').forEach(card => {
                    card.querySelector('.timeline-event-card')?.classList.remove('active-event');
                });

                const latestEvent = document.querySelector('.timeline-event-wrapper[data-is-latest="1"]');
                if (latestEvent) {
                    latestEvent.querySelector('.timeline-event-card')?.classList.add('active-event');
                }

                // Note: Preview HTML already exists from server-side rendering in index.php
                // We only need to apply formatting, not rebuild the preview
                // updatePreviewFromDOM() would hide preview if no activeAction exists (ADR-007)

                BglLogger.debug('TL_LOAD_CURRENT_STATE_OK');
            } catch (error) {
                console.error('TL_LOAD_CURRENT_STATE_ERR', error);
                if (window.showToast) {
                    window.showToast(this.t('timeline.error.load_current_state'), 'error');
                }
            }
        }

        showError(message) {
            if (window.showToast) window.showToast(message, 'error');
        }

        // Badge methods removed - using #activeAction in Data Card instead
    };
}

// Initialize Time Machine immediately
if (!window.timelineController) {
    if (window.TimelineController) {
        window.timelineController = new window.TimelineController();
        // Make globally accessible for onclick handlers
        // window.timelineController is already set above
    }
}
