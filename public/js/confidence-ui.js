/**
 * Confidence UI Helper
 * 
 * JavaScript utilities for rendering confidence indicators
 */

const ConfidenceUI = {
    t(key, fallback, params) {
        if (window.WBGLI18n && typeof window.WBGLI18n.t === 'function') {
            return window.WBGLI18n.t(key, fallback, params);
        }
        return fallback || key;
    },

    /**
     * Render confidence badge
     * 
     * @param {number} confidence - Confidence percentage (0-100)
     * @param {string} reason - Explanation text
     * @returns {string} HTML string
     */
    renderBadge(confidence, reason = '') {
        const level = this.getLevel(confidence);
        const icon = this.getIcon(level);
        const label = this.getLabel(level);

        const tooltip = reason ? `data-reason="${this.escapeHtml(reason)}"` : '';

        return `
            <span class="confidence-badge confidence-${level} confidence-tooltip" ${tooltip}>
                <span>${icon}</span>
                <span class="confidence-percentage">${confidence}%</span>
                <span>${label}</span>
            </span>
        `;
    },

    /**
     * Get confidence level
     * 
     * @param {number} confidence
     * @returns {string} 'high', 'medium', or 'low'
     */
    getLevel(confidence) {
        if (confidence >= 90) return 'high';
        if (confidence >= 70) return 'medium';
        return 'low';
    },

    /**
     * Get icon for level
     * 
     * @param {string} level
     * @returns {string} Emoji icon
     */
    getIcon(level) {
        const icons = {
            high: '✅',
            medium: '⚠️',
            low: '❌'
        };
        return icons[level] || '❓';
    },

    /**
     * Get label for level
     * 
     * @param {string} level
     * @returns {string} Arabic label
     */
    getLabel(level) {
        const labels = {
            high: this.t('confidence.level.high', 'confidence.level.high'),
            medium: this.t('confidence.level.medium', 'confidence.level.medium'),
            low: this.t('confidence.level.low', 'confidence.level.low')
        };
        return labels[level] || this.t('confidence.level.unknown', 'confidence.level.unknown');
    },

    /**
     * Render warning message for low/medium confidence
     * 
     * @param {number} confidence
     * @param {string} fieldName
     * @returns {string} HTML string or empty
     */
    renderWarning(confidence, fieldName) {
        const level = this.getLevel(confidence);

        if (level === 'high') {
            return ''; // No warning needed
        }

        const messages = {
            medium: this.t('confidence.warning.medium', 'confidence.warning.medium', { confidence }),
            low: this.t('confidence.warning.low', 'confidence.warning.low', { confidence })
        };

        return `
            <div class="confidence-warning">
                <div class="confidence-warning-icon">${this.getIcon(level)}</div>
                <div>${messages[level] || this.t('confidence.warning.check_data', 'confidence.warning.check_data')}</div>
            </div>
        `;
    },

    /**
     * Add confidence indicator to input field
     * 
     * @param {HTMLElement} inputElement
     * @param {number} confidence
     * @param {string} reason
     */
    attachToField(inputElement, confidence, reason = '') {
        if (!inputElement) return;

        // Wrap field if not already wrapped
        if (!inputElement.parentElement.classList.contains('field-with-confidence')) {
            const wrapper = document.createElement('div');
            wrapper.className = 'field-with-confidence';
            inputElement.parentNode.insertBefore(wrapper, inputElement);
            wrapper.appendChild(inputElement);
        }

        // Create indicator
        const indicator = document.createElement('div');
        indicator.className = 'confidence-indicator';
        indicator.innerHTML = this.renderBadge(confidence, reason);

        // Insert indicator
        const wrapper = inputElement.parentElement;
        const existing = wrapper.querySelector('.confidence-indicator');
        if (existing) {
            existing.replaceWith(indicator);
        } else {
            wrapper.insertBefore(indicator, wrapper.firstChild);
        }

        // Add warning below field if needed
        const level = this.getLevel(confidence);
        if (level !== 'high') {
            const warning = wrapper.querySelector('.confidence-warning');
            if (warning) warning.remove(); // Remove old warning

            const warningDiv = document.createElement('div');
            warningDiv.innerHTML = this.renderWarning(confidence, inputElement.name || this.t('confidence.ui.field', 'confidence.ui.field'));
            inputElement.parentElement.appendChild(warningDiv.firstElementChild);
        }

        // Highlight field border based on confidence using CSS classes
        this.applyFieldBorderState(inputElement, level);
    },

    /**
     * Apply border class for confidence level
     * 
     * @param {HTMLElement} inputElement
     * @param {string} level
     */
    applyFieldBorderState(inputElement, level) {
        if (!(inputElement instanceof HTMLElement)) {
            return;
        }
        const classes = ['confidence-field-high', 'confidence-field-medium', 'confidence-field-low'];
        inputElement.classList.remove(...classes);
        inputElement.classList.add(`confidence-field-${level}`);
    },

    /**
     * Escape HTML
     * 
     * @param {string} text
     * @returns {string}
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
};

// Make globally available
window.ConfidenceUI = ConfidenceUI;
