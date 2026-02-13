/**
 * Preview Formatting Layer
 * Responsible for visual formatting of preview content (digits, text direction, fonts)
 * This layer is presentation-only and never modifies content semantics
 */

if (!window.PreviewFormatter) {
    window.PreviewFormatter = (function () {
        'use strict';

        // ========================
        // Constants
        // ========================
        const EASTERN_DIGITS = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

        // ========================
        // Core Functions
        // ========================

        /**
         * Convert Western digits (0-9) to Eastern/Arabic-Indic digits (٠-٩)
         * @param {string} text - Text containing digits
         * @returns {string} Text with Eastern digits
         */
        function toEasternDigits(text) {
            if (!text) return text;
            return String(text).replace(/\d/g, digit => EASTERN_DIGITS[Number(digit)]);
        }

        /**
         * Convert digits in DOM nodes (Context-Aware & Idempotent)
         * @param {Element} root - Root element to process
         */
        function convertDigitsInNode(root) {
            if (!root) return;

            const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
            const elementsToConvert = [];
            const elementsToKeepEnglish = new Set();

            while (walker.nextNode()) {
                const current = walker.currentNode;
                const parent = current.parentElement;

                // Skip script, style, and footer elements
                if (parent && (parent.tagName === 'SCRIPT' || parent.tagName === 'STYLE')) {
                    continue;
                }
                if (parent && parent.closest('.sheet-footer')) {
                    continue;
                }

                // ✅ TRUST EXPLICIT LANG
                if (parent && parent.getAttribute('lang') === 'en') {
                    elementsToKeepEnglish.add(parent);
                    continue;
                }

                // 🔄 FORCE CONVERSION FOR LANG="AR"
                // If it was previously 'en' but now 'ar', we MUST convert
                if (parent && parent.getAttribute('lang') === 'ar') {
                    // No-op, just ensure it falls through to elementsToConvert
                }

                if (/\d/.test(current.nodeValue)) {
                    elementsToConvert.push({ node: current, parent: parent });
                }
            }

            // Convert digits for elements that should be Arabic
            elementsToConvert.forEach(function (item) {
                // Skip if parent is marked to keep English
                if (item.parent && elementsToKeepEnglish.has(item.parent)) {
                    return;
                }
                item.node.nodeValue = toEasternDigits(item.node.nodeValue);

                // Remove lang=en from parent if it was pure numbers (fallback for auto-detect)
                if (item.parent && item.parent.getAttribute('lang') === 'en') {
                    const text = item.parent.textContent.trim();
                    if (/^[٠-٩\s\.\-\/\(\)]+$/.test(text)) {
                        item.parent.removeAttribute('lang');
                    }
                }
            });
        }

        /**
         * Mark English text elements with lang="en"
         * @param {Element} root - Root element to process
         */
        function markEnglishText(root) {
            if (!root) return;

            // Regex for detecting primarily English text (Latin characters)
            // ✅ FIX: Require at least one letter [A-Za-z] to avoid misidentifying PO numbers
            const englishPattern = /^(?=.*[A-Za-z])[A-Za-z0-9\s\.\,\-\_\@\#\$\%\&\*\(\)\[\]\{\}\:\;\"\'\<\>\/\\\+\=\!\?\|\~\`]+$/;

            // Get all text-containing elements
            const elements = root.querySelectorAll('span, div, p');
            elements.forEach(function (el) {
                // Skip if already has lang attribute or is a container with mixed content
                if (el.hasAttribute('lang')) return;
                if (el.children.length > 0) return;

                const text = el.textContent.trim();
                if (text && englishPattern.test(text)) {
                    el.setAttribute('lang', 'en');
                }
            });

            // Also wrap punctuation symbols in mixed text
            wrapPunctuationAsEnglish(root);
        }

        /**
         * Wrap punctuation in spans with lang=en
         * @param {Element} root - Root element to process
         */
        function wrapPunctuationAsEnglish(root) {
            if (!root) return;

            const punctuationPattern = /([().\-\/:@,])/g;
            const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
            const nodesToProcess = [];

            while (walker.nextNode()) {
                const node = walker.currentNode;
                const parent = node.parentElement;

                // Skip if parent already has lang=en or is script/style
                if (parent && parent.getAttribute('lang') === 'en') continue;
                if (parent && (parent.tagName === 'SCRIPT' || parent.tagName === 'STYLE')) continue;

                // Reset lastIndex to fix alternating behavior with global regex
                punctuationPattern.lastIndex = 0;
                if (punctuationPattern.test(node.nodeValue)) {
                    nodesToProcess.push(node);
                }
            }

            nodesToProcess.forEach(function (node) {
                const text = node.nodeValue;
                const fragment = document.createDocumentFragment();
                let lastIndex = 0;

                text.replace(punctuationPattern, function (match, p1, offset) {
                    // Add text before punctuation
                    if (offset > lastIndex) {
                        fragment.appendChild(document.createTextNode(text.substring(lastIndex, offset)));
                    }
                    // Add punctuation in span with lang=en
                    const span = document.createElement('span');
                    span.setAttribute('lang', 'en');
                    span.textContent = match;
                    fragment.appendChild(span);
                    lastIndex = offset + match.length;
                    return match;
                });

                // Add remaining text
                if (lastIndex < text.length) {
                    fragment.appendChild(document.createTextNode(text.substring(lastIndex)));
                }

                node.parentNode.replaceChild(fragment, node);
            });
        }

        /**
         * Main entry point: Apply all preview formatting
         * This is the ONLY function that should be called externally
         * @param {Element} root - Root element (default: .letter-paper)
         */
        function applyPreviewFormatting(root) {
            // Default to .letter-paper (letter body only, NOT data card)
            // This keeps technical data (info card) in Western numerals,
            // while the formal letter uses Arabic numerals for localization
            if (!root) {
                root = document.querySelector('.letter-paper');
            }

            if (!root) {
                BglLogger.warn('PreviewFormatter: No root element found');
                return;
            }

            // Apply formatting in order
            // ✅ FIX: Mark English first to establish context
            markEnglishText(root);

            // ✅ Then convert digits, respecting the lang="en" established above
            convertDigitsInNode(root);
        }

        // ========================
        // Public API
        // ========================
        return {
            applyFormatting: applyPreviewFormatting,
            // Expose individual functions for testing/debugging
            toEasternDigits: toEasternDigits,
            convertDigits: convertDigitsInNode,
            markEnglish: markEnglishText
        };
    })();
}
