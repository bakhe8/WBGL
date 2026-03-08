/**
 * Phase 5: Auto-load Supplier Suggestions on Page Load
 * 
 * PILOT GLUE CODE - Temporary bridge to enable Phase 5
 * Automatically triggers suggestion search when guarantee page loads
 * 
 * This code can be removed after Pilot completion.
 */

(function () {
    'use strict';

    const safeLogger = {
        debug: (...args) => {
            if (window.BglLogger && typeof window.BglLogger.debug === 'function') {
                window.BglLogger.debug(...args);
                return;
            }
            if (window.console && typeof window.console.debug === 'function') {
                window.console.debug(...args);
            }
        }
    };

    // Wait for DOM to be ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', autoLoadSuggestions);
    } else {
        autoLoadSuggestions();
    }

    function autoLoadSuggestions() {
        // Only run on guarantee detail pages (not settings, etc.)
        if (!document.getElementById('supplierInput')) {
            return;
        }

        // 🔧 FIX: Don't overwrite supplier name if record is already "ready" or "released"
        const decisionStatus = document.getElementById('decisionStatus');
        const normalizedStatus = String(decisionStatus?.value || '').trim().toLowerCase();
        if (['ready', 'released', 'approved', 'issued', 'signed'].includes(normalizedStatus)) {
            safeLogger.debug('[Pilot] Skipping auto-load - record already has matched supplier');
            return;
        }

        const suggestionsContainer = document.getElementById('supplier-suggestions');
        if (suggestionsContainer && suggestionsContainer.hidden) {
            safeLogger.debug('[Pilot] Skipping auto-load - suggestions are hidden for this state');
            return;
        }

        // 🔧 FIX: Skip if suggestions already exist from PHP (prevent duplicate loading)
        const existingChips = document.querySelectorAll('#supplier-suggestions .chip');
        if (existingChips.length > 0) {
            // Check if there's a high-confidence match (>= 95%)
            const hasHighConfidence = Array.from(existingChips).some(chip => {
                const confidenceText = chip.querySelector('.chip-confidence')?.textContent;
                const confidence = parseInt(confidenceText);
                return confidence >= 95;
            });

            if (hasHighConfidence) {
                safeLogger.debug('[Pilot] Skipping auto-load - PHP already provided high-confidence suggestions');
                return;
            }
        }

        // Get the Excel supplier name from UI
        const excelSupplierEl = document.getElementById('excelSupplier');
        if (!excelSupplierEl) {
            safeLogger.debug('[Pilot] No Excel supplier element found');
            return;
        }

        const rawSupplierName = excelSupplierEl.textContent.trim();
        if (!rawSupplierName || rawSupplierName === '-') {
            safeLogger.debug('[Pilot] No supplier name to search for');
            return;
        }

        safeLogger.debug('[Pilot] Auto-loading suggestions for:', rawSupplierName);

        // Trigger the existing suggestion mechanism
        const supplierInput = document.getElementById('supplierInput');
        if (supplierInput) {
            const currentValue = String(supplierInput.value || '').trim();
            const hasStableVisibleValue = currentValue !== '' && currentValue !== '-';

            // Keep visible data stable during initial load:
            // if input already has a value from server-rendered state, do not overwrite it.
            if (hasStableVisibleValue) {
                safeLogger.debug('[Pilot] Keeping existing supplier value - no prefill overwrite');
                supplierInput.dispatchEvent(new Event('input', { bubbles: true }));
                return;
            }

            // Prefill only when the field is empty.
            supplierInput.value = rawSupplierName;
            supplierInput.dispatchEvent(new Event('input', { bubbles: true }));

            safeLogger.debug('[Pilot] Suggestion search triggered');
        }
    }

    safeLogger.debug('[Phase 5] Auto-load glue code loaded');
})();
