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

        processTimelineClick(element) {
            // DEBOUNCE: Prevent rapid double-clicks
            if (this.isProcessing) return;
            this.isProcessing = true;
            setTimeout(() => { this.isProcessing = false; }, 300);

            const eventId = this.normalizeEventId(element.dataset.eventId);
            const snapshotData = element.dataset.snapshot;
            if (eventId === null) {
                this.showError(this.t('timeline.error.event_id_unresolved'));
                return;
            }

            try {
                let snapshot = null;
                if (snapshotData && snapshotData !== '{}' && snapshotData !== 'null') {
                    snapshot = JSON.parse(snapshotData);
                }

                // Remove active class from all cards
                document.querySelectorAll('.timeline-event-wrapper').forEach(card => {
                    card.querySelector('.timeline-event-card')?.classList.remove('active-event');
                });

                // Add active class to clicked card
                element.querySelector('.timeline-event-card')?.classList.add('active-event');

                // Use resolved before-state snapshot from timeline payload.
                if (!snapshot || Object.keys(snapshot).length === 0) {
                    this.showError(this.t('timeline.error.no_historical_data'));
                    return;
                }

                this.displayHistoricalState(snapshot, eventId);
                document.dispatchEvent(new CustomEvent('guarantee:updated'));

            } catch (error) {
                console.error('TL_CLICK_ERR', error);
                this.showError(this.t('timeline.error.display_historical_state'));
            }
        }

        displayHistoricalState(snapshot, eventId) {
            BglLogger.debug('TL_SHOW_HISTORICAL_STATE', snapshot);

            // Parse snapshot if it's a string
            let snapshotData = snapshot;
            if (typeof snapshot === 'string') {
                try {
                    snapshotData = JSON.parse(snapshot);
                } catch (e) {
                    console.error('TL_PARSE_SNAPSHOT_ERR', e);
                    return;
                }
            }

            // Check if snapshot is empty
            if (!snapshotData || Object.keys(snapshotData).length === 0) { // Removed snapshotData._no_snapshot check
                BglLogger.warn('TL_SNAPSHOT_EMPTY');
                if (window.showToast) window.showToast(this.t('timeline.error.no_historical_data'), 'error');
                return;
            }


            // Mark as historical view (no client-side state saving - Server is source of truth)
            this.isHistoricalView = true;
            // ✨ NEW: Context-Aware Highlighting & Banner Metadata
            const eventElement = document.querySelector(`[data-event-id="${eventId}"]`);
            this.currentEventSubtype = eventElement?.dataset.eventSubtype || null;

            // 1. Update Banner with Audit Info
            this.showHistoricalBanner(eventElement);

            // 2. Highlight Delta (What changed in THIS step)
            this.highlightChanges(eventElement);

            let dataToDisplay = snapshotData;
            let htmlSnapshotUsed = false;  // Track if we used HTML snapshot

            // For Action Events: use letter_snapshot (After State - Final Values)
            if (this.currentEventSubtype === 'extension' ||
                this.currentEventSubtype === 'reduction' ||
                this.currentEventSubtype === 'release') {

                const letterSnapshotRaw = eventElement?.dataset.letterSnapshot;

                if (letterSnapshotRaw && letterSnapshotRaw !== 'null' && !letterSnapshotRaw.trim().startsWith('{')) {
                    BglLogger.debug('TL_USE_HTML_LETTER_SNAPSHOT');

                    // Replace preview section with pre-rendered HTML
                    const previewSection = document.getElementById('preview-section');
                    if (previewSection) {
                        previewSection.innerHTML = letterSnapshotRaw;

                        // ✨ Apply unified formatting layer (digits, text direction)
                        if (window.PreviewFormatter) {
                            window.PreviewFormatter.applyFormatting();
                        }

                        htmlSnapshotUsed = true;
                    }
                }
            }

            // Update form fields with selected snapshot (only if not using HTML)
            if (!htmlSnapshotUsed) {
                this.updateFormFields(dataToDisplay);

                // ✨ Apply formatting AFTER fields are updated
                if (window.PreviewFormatter) {
                    window.PreviewFormatter.applyFormatting();
                }
            } else {
                // Still update Data Card for context
                this.updateFormFields(snapshotData);

                // ✨ Apply formatting AFTER fields are updated
                if (window.PreviewFormatter) {
                    window.PreviewFormatter.applyFormatting();
                }
            }

            // 🔥 CRITICAL FIX: Persist subtype to hidden input for RecordsController to see
            const activeActionInput = document.getElementById('activeAction');
            if (activeActionInput) {
                activeActionInput.value = this.currentEventSubtype || '';
            }

            const eventSubtypeInput = document.getElementById('eventSubtype');
            if (eventSubtypeInput) {
                eventSubtypeInput.value = this.currentEventSubtype || '';
            }

            // Disable editing
            this.disableEditing();

            // ⚠️ CRITICAL: Skip updatePreviewFromDOM if HTML snapshot was used!
            if (!htmlSnapshotUsed && window.recordsController?.previewVisible) {
                window.recordsController.updatePreviewFromDOM();
            }

            // Ensure legacy/current print button markup always renders the same shape/style.
            this.normalizePreviewPrintButton();
            if (window.recordsController && typeof window.recordsController.syncPreviewPrintButtonVisibility === 'function') {
                window.recordsController.syncPreviewPrintButtonVisibility();
            }
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
                // Fetch current state from server (Server-Driven Architecture)
                const response = await fetch(`/api/get-current-state.php?id=${currentId}`);
                const data = await response.json();

                if (!data.success) {
                    throw new Error(data.error || 'LOAD_CURRENT_STATE_FAILED');
                }

                // ← NEW: إزالة event context بشكل صريح
                this.currentEventSubtype = null;

                // ✅ Update activeAction in Data Card from server snapshot
                const activeActionInput = document.getElementById('activeAction');
                if (activeActionInput && data.snapshot) {
                    activeActionInput.value = data.snapshot.active_action || '';
                }

                // Update form fields with current state snapshot
                this.updateFormFields(data.snapshot || {});

                // ✨ Apply formatting AFTER fields are updated
                if (window.PreviewFormatter) {
                    window.PreviewFormatter.applyFormatting();
                }

                this.normalizePreviewPrintButton();
                if (window.recordsController && typeof window.recordsController.syncPreviewPrintButtonVisibility === 'function') {
                    window.recordsController.syncPreviewPrintButtonVisibility();
                }

                // Hide historical banner (this is Current State)
                this.removeHistoricalBanner();

                // Enable editing (show buttons, enable inputs)
                this.enableEditing();

                // Reset timeline state
                this.isHistoricalView = false;
                this.currentEventId = null;
                this.currentGuaranteeId = null;

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
