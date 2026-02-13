/**
 * Timeline Handler - Time Machine Functionality
 * Handles click interactions on timeline events
 * Shows historical state of guarantee at any point in time
 */

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

        processTimelineClick(element) {
            // DEBOUNCE: Prevent rapid double-clicks
            if (this.isProcessing) return;
            this.isProcessing = true;
            setTimeout(() => { this.isProcessing = false; }, 300);

            const eventId = element.dataset.eventId;
            const snapshotData = element.dataset.snapshot;

            try {
                const snapshot = JSON.parse(snapshotData);

                // Remove active class from all cards
                document.querySelectorAll('.timeline-event-wrapper').forEach(card => {
                    card.querySelector('.timeline-event-card')?.classList.remove('active-event');
                });

                // Add active class to clicked card
                element.querySelector('.timeline-event-card')?.classList.add('active-event');

                // All events (including latest) show historical snapshot
                this.displayHistoricalState(snapshot, eventId);

                // 🔥 DISPATCH EVENT: Notify system that Data Card has been updated
                // This triggers the preview update via records.controller.js
                // strictly complying with "Preview reads from Data Card" rule.
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

            // Check if snapshot is empty or legacy
            if (!snapshotData || Object.keys(snapshotData).length === 0 || snapshotData._no_snapshot) {
                BglLogger.warn('⚠️ No snapshot data available');
                if (window.showToast) window.showToast('لا توجد بيانات تاريخية لهذا الحدث', 'error');
                return;
            }


            // Mark as historical view (no client-side state saving - Server is source of truth)
            this.isHistoricalView = true;
            this.currentEventId = eventId;
            this.currentGuaranteeId = snapshotData.guarantee_id ||
                document.querySelector('[data-record-id]')?.dataset.recordId;

            // 🔥 BACKWARD COMPATIBILITY: Fill missing fields removed.
            // Snapshots are now self-contained with raw_data fallback.
            // This prevents "future state leakage" in historical views.

            // ✨ NEW: Conditional Snapshot Selection (After State for Actions)
            const eventElement = document.querySelector(`[data-event-id="${eventId}"]`);
            this.currentEventSubtype = eventElement?.dataset.eventSubtype || null;
            BglLogger.debug('📋 Event subtype:', this.currentEventSubtype);

            let dataToDisplay = snapshotData;
            let htmlSnapshotUsed = false;  // Track if we used HTML snapshot

            // For Action Events: use letter_snapshot (After State - Final Values)
            if (this.currentEventSubtype === 'extension' ||
                this.currentEventSubtype === 'reduction' ||
                this.currentEventSubtype === 'release') {

                const letterSnapshotRaw = eventElement?.dataset.letterSnapshot;

                if (letterSnapshotRaw && letterSnapshotRaw !== 'null') {
                    // Check if it's HTML (not JSON)
                    if (!letterSnapshotRaw.trim().startsWith('{')) {
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
                    } else {
                        // Fallback: old JSON snapshot
                        try {
                            const letterSnapshot = JSON.parse(letterSnapshotRaw);
                            if (letterSnapshot && Object.keys(letterSnapshot).length > 0) {
                                BglLogger.debug('📦 Using JSON letter_snapshot (legacy)');
                                dataToDisplay = letterSnapshot;
                            }
                        } catch (e) {
                            BglLogger.warn('⚠️ Failed to parse letter_snapshot, using snapshot_data');
                        }
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
            // ✅ Update activeAction in Data Card (source of truth)
            const activeActionInput = document.getElementById('activeAction');
            if (activeActionInput) {
                activeActionInput.value = this.currentEventSubtype || '';
            }

            const eventSubtypeInput = document.getElementById('eventSubtype');
            if (eventSubtypeInput) {
                eventSubtypeInput.value = this.currentEventSubtype || '';
            }

            // Show historical banner
            this.showHistoricalBanner();

            // Disable editing
            this.disableEditing();

            // ⚠️ CRITICAL: Skip updatePreviewFromDOM if HTML snapshot was used!
            // updatePreviewFromDOM rebuilds letter from fields → loses Arabic formatting
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

        showHistoricalBanner() {
            // ✅ COMPLIANCE: Toggle Server-Side Element
            const bannerContainer = document.getElementById('historical-banner-container');
            if (bannerContainer) {
                bannerContainer.style.display = 'block';
            }
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
