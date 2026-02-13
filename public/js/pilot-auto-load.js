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
        if (decisionStatus && (decisionStatus.value === 'ready' || decisionStatus.value === 'released')) {
            BglLogger.debug('[Pilot] Skipping auto-load - record already has matched supplier');
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
                BglLogger.debug('[Pilot] Skipping auto-load - PHP already provided high-confidence suggestions');
                return;
            }
        }

        // Get the Excel supplier name from UI
        const excelSupplierEl = document.getElementById('excelSupplier');
        if (!excelSupplierEl) {
            BglLogger.debug('[Pilot] No Excel supplier element found');
            return;
        }

        const rawSupplierName = excelSupplierEl.textContent.trim();
        if (!rawSupplierName || rawSupplierName === '-') {
            BglLogger.debug('[Pilot] No supplier name to search for');
            return;
        }

        BglLogger.debug('[Pilot] Auto-loading suggestions for:', rawSupplierName);

        // Trigger the existing suggestion mechanism
        const supplierInput = document.getElementById('supplierInput');
        if (supplierInput) {
            // Set value and dispatch input event to trigger records controller
            supplierInput.value = rawSupplierName;
            supplierInput.dispatchEvent(new Event('input', { bubbles: true }));

            BglLogger.debug('[Pilot] Suggestion search triggered');
        }
    }

    BglLogger.debug('[Phase 5] Auto-load glue code loaded');
})();
