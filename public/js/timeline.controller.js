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

        init() {
            // Use event delegation for reliability
            // This works even if timeline cards are added dynamically
            document.addEventListener('click', (e) => {
                const eventWrapper = e.target.closest('.timeline-event-wrapper');
                if (eventWrapper) {
                    this.processTimelineClick(eventWrapper);
                }
            });

            BglLogger.debug('✅ Timeline Controller initialized');
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
                this.showError('تعذر تحديد الحدث التاريخي');
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
                    this.showError('لا توجد بيانات تاريخية لهذا الحدث');
                    return;
                }

                this.displayHistoricalState(snapshot, eventId);
                document.dispatchEvent(new CustomEvent('guarantee:updated'));

            } catch (error) {
                console.error('Error handling timeline click:', error);
                this.showError('حدث خطأ في عرض الحالة التاريخية');
            }
        }

        displayHistoricalState(snapshot, eventId) {
            BglLogger.debug('📜 Displaying historical state:', snapshot);

            // Parse snapshot if it's a string
            let snapshotData = snapshot;
            if (typeof snapshot === 'string') {
                try {
                    snapshotData = JSON.parse(snapshot);
                } catch (e) {
                    console.error('Failed to parse snapshot:', e);
                    return;
                }
            }

            // Check if snapshot is empty
            if (!snapshotData || Object.keys(snapshotData).length === 0) { // Removed snapshotData._no_snapshot check
                BglLogger.warn('⚠️ No snapshot data available after reconstruction attempt');
                if (window.showToast) window.showToast('لا توجد بيانات تاريخية لهذا الحدث', 'error');
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
                    BglLogger.debug('✨ Using HTML letter_snapshot (After State) for action event');

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
        }

        updateFormFields(snapshot) {
            BglLogger.debug('🔄 Updating fields with snapshot:', snapshot);

            // Update supplier input (ID: supplierInput)
            // Always update to prevent "leakage" from previous events
            const supplierInput = document.getElementById('supplierInput');
            if (supplierInput) {
                // Use official name OR raw name fallback
                const nameToShow = snapshot.supplier_name || snapshot.raw_supplier_name || '';
                supplierInput.value = nameToShow;
                BglLogger.debug('✓ Updated supplier:', nameToShow || '(cleared)');
            }

            // Update hidden supplier ID (ID: supplierIdHidden)
            const supplierIdHidden = document.getElementById('supplierIdHidden');
            if (supplierIdHidden) {
                supplierIdHidden.value = snapshot.supplier_id || '';
                BglLogger.debug('✓ Updated supplier ID:', snapshot.supplier_id || '(cleared)');
            }

            // Bank is now in Info Grid - updated via label matching below

            // Update hidden bank ID (ID: bankSelect)
            const bankSelect = document.getElementById('bankSelect');
            if (bankSelect) {
                bankSelect.value = snapshot.bank_id || '';
                BglLogger.debug('✓ Updated bank ID:', snapshot.bank_id || '(cleared)');
            }

            // Update info-value elements by matching labels
            document.querySelectorAll('.info-item').forEach(item => {
                const label = item.querySelector('.info-label')?.textContent || '';
                const valueEl = item.querySelector('.info-value');

                if (!valueEl) return;

                // Amount - with NaN protection
                if (label.includes('المبلغ') && snapshot.amount) {
                    let amountValue = snapshot.amount;

                    // Convert string to number safely
                    if (typeof amountValue === 'string') {
                        amountValue = parseFloat(amountValue.replace(/[^\d.]/g, ''));
                    }

                    // Validate number
                    if (!isNaN(amountValue) && isFinite(amountValue)) {
                        const formattedAmount = new Intl.NumberFormat('en-US').format(amountValue);
                        valueEl.textContent = formattedAmount + ' ر.س';
                        BglLogger.debug('✓ Updated amount:', formattedAmount);
                    } else {
                        BglLogger.warn('⚠️ Invalid amount value:', snapshot.amount);
                        valueEl.textContent = 'قيمة غير صحيحة ر.س';
                    }
                }

                // Expiry date
                if (label.includes('تاريخ الانتهاء') && snapshot.expiry_date) {
                    valueEl.textContent = snapshot.expiry_date;
                    BglLogger.debug('✓ Updated expiry:', snapshot.expiry_date);
                }

                // Issue date
                if (label.includes('تاريخ الإصدار') && snapshot.issue_date) {
                    valueEl.textContent = snapshot.issue_date;
                    BglLogger.debug('✓ Updated issue date:', snapshot.issue_date);
                }

                // Bank name
                if (label.includes('البنك') && snapshot.bank_name) {
                    valueEl.textContent = snapshot.bank_name;
                    BglLogger.debug('✓ Updated bank:', snapshot.bank_name);
                }
            });

            // Update status badge
            const statusBadge = document.querySelector('.status-badge');
            if (statusBadge && snapshot.status) {
                this.updateStatusBadge(statusBadge, snapshot.status);
                BglLogger.debug('✓ Updated status:', snapshot.status);
            }

            // 🔥 Update Hidden Event Context (Bridge to RecordsController)
            const eventSubtypeInput = document.getElementById('eventSubtype');
            if (eventSubtypeInput) {
                // If snapshot has event_subtype, use it. Otherwise clear it.
                // Note: snapshot.event_subtype comes from backend createSnapshot or logEvent
                const subtype = snapshot.event_subtype || '';
                eventSubtypeInput.value = subtype;
                BglLogger.debug('✓ Updated event context:', subtype || '(none)');
            }
        }

        updateStatusBadge(badge, status) {
            // Remove all status classes
            badge.classList.remove('status-pending', 'status-approved', 'status-extended', 'status-released');

            // Add appropriate class
            badge.classList.add(`status-${status}`);

            const statusLabels = {
                'pending': 'يحتاج قرار',
                'approved': 'معتمد',
                'extended': 'ممدد',
                'released': 'مُفرج عنه',
                'reduced': 'مخفض'
            };

            badge.textContent = statusLabels[status] || status;
        }

        showHistoricalBanner(eventElement = null) {
            const bannerContainer = document.getElementById('historical-banner-container');
            if (bannerContainer) {
                bannerContainer.style.display = 'block';

                if (eventElement) {
                    const hbTitle = document.getElementById('hb-title');
                    const hbSubtitle = document.getElementById('hb-subtitle');

                    if (hbTitle) {
                        const eventLabel = eventElement.querySelector('.timeline-event-card span[style*="font-weight: 600"]')?.textContent.trim() || 'نسخة تاريخية';
                        hbTitle.textContent = `مراجعة حدث: ${eventLabel}`;
                    }

                    if (hbSubtitle) {
                        const createdAt = eventElement.querySelector('span[style*="font-size: 11px"]')?.textContent.trim() || '';
                        const actor = eventElement.querySelector('span[style*="font-weight: 500"]')?.textContent.trim() || 'النظام';
                        hbSubtitle.textContent = `بواسطة ${actor} في ${createdAt}`;
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
                BglLogger.warn('⚠️ Highlighting failed:', e);
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
                bannerContainer.style.display = 'none';
            }
        }

        disableEditing() {
            // Disable all input fields
            const inputs = document.querySelectorAll('#supplierInput, #bankSelect');
            inputs.forEach(input => {
                input.disabled = true;
                input.style.opacity = '0.7';
                input.style.cursor = 'not-allowed';
            });

            // Hide action buttons (not just disable - prevent accidental interaction with history)
            const buttons = document.querySelectorAll('[data-action="extend"], [data-action="reduce"], [data-action="release"], [data-action="save-next"], [data-action="saveAndNext"]');
            buttons.forEach(btn => {
                btn.style.display = 'none';
            });
        }

        enableEditing() {
            // Enable all input fields
            const inputs = document.querySelectorAll('#supplierInput, #bankSelect');
            inputs.forEach(input => {
                input.disabled = false;
                input.style.opacity = '1';
                input.style.cursor = '';
            });

            // Show action buttons again
            const buttons = document.querySelectorAll('[data-action="extend"], [data-action="reduce"], [data-action="release"], [data-action="save-next"], [data-action="saveAndNext"]');
            buttons.forEach(btn => {
                btn.style.display = '';
            });
        }

        async loadCurrentState() {
            BglLogger.debug('🔄 Loading current state from server');

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
                    throw new Error(data.error || 'Failed to load current state');
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

                BglLogger.debug('✅ Current state loaded from server');
            } catch (error) {
                console.error('Failed to load current state:', error);
                if (window.showToast) {
                    window.showToast('فشل تحميل الحالة الحالية', 'error');
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
