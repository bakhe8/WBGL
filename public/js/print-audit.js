(function () {
    'use strict';

    const endpoint = '/api/print-events.php';

    function normalizeIds(input) {
        if (!Array.isArray(input)) {
            return [];
        }

        const out = [];
        for (let i = 0; i < input.length; i += 1) {
            const value = input[i];
            const num = Number(value);
            if (Number.isInteger(num) && num > 0) {
                out.push(num);
            }
        }

        return Array.from(new Set(out));
    }

    function currentRecordId() {
        const holder = document.getElementById('record-form-sec');
        if (!holder || !holder.dataset) {
            return null;
        }
        const id = Number(holder.dataset.recordId || 0);
        return Number.isInteger(id) && id > 0 ? id : null;
    }

    async function postJson(payload) {
        const res = await fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
            credentials: 'same-origin',
        });

        if (!res.ok) {
            throw new Error('print-audit request failed');
        }

        const data = await res.json();
        if (!data || data.success !== true) {
            throw new Error((data && data.error) ? data.error : 'print-audit rejected');
        }

        return data;
    }

    async function record(eventType, context, options) {
        const opts = options || {};
        const ids = normalizeIds(
            Array.isArray(opts.guaranteeIds)
                ? opts.guaranteeIds
                : (opts.guaranteeId ? [opts.guaranteeId] : [])
        );

        const payload = {
            event_type: String(eventType || ''),
            context: String(context || ''),
            channel: String(opts.channel || 'browser'),
            source_page: String(opts.sourcePage || window.location.pathname || ''),
            meta: (opts.meta && typeof opts.meta === 'object') ? opts.meta : {},
        };

        if (ids.length === 1) {
            payload.guarantee_id = ids[0];
        } else if (ids.length > 1) {
            payload.guarantee_ids = ids;
        }

        if (opts.batchIdentifier) {
            payload.batch_identifier = String(opts.batchIdentifier);
        }

        return postJson(payload);
    }

    function fireAndForget(promise) {
        if (promise && typeof promise.catch === 'function') {
            promise.catch(function () { /* silent on purpose */ });
        }
    }

    function handleOverlayPrint(buttonEl) {
        const wrapper = buttonEl && buttonEl.closest ? buttonEl.closest('.letter-preview') : null;
        let guaranteeId = null;
        if (wrapper && wrapper.dataset && wrapper.dataset.guaranteeId) {
            const parsed = Number(wrapper.dataset.guaranteeId);
            if (Number.isInteger(parsed) && parsed > 0) {
                guaranteeId = parsed;
            }
        }
        if (!guaranteeId) {
            guaranteeId = currentRecordId();
        }

        fireAndForget(record('print_requested', 'single_letter', {
            guaranteeId: guaranteeId,
            meta: { trigger: 'overlay_button' },
        }));

        window.print();
        return false;
    }

    function recordSinglePrint(guaranteeId, meta) {
        return record('print_requested', 'single_letter', {
            guaranteeId: guaranteeId,
            meta: meta || {},
        });
    }

    function recordBatchOpen(guaranteeIds, batchIdentifier, meta) {
        return record('preview_opened', 'batch_letter', {
            guaranteeIds: guaranteeIds,
            batchIdentifier: batchIdentifier || '',
            meta: meta || {},
        });
    }

    function recordBatchPrint(guaranteeIds, batchIdentifier, meta) {
        return record('print_requested', 'batch_letter', {
            guaranteeIds: guaranteeIds,
            batchIdentifier: batchIdentifier || '',
            meta: meta || {},
        });
    }

    window.WBGLPrintAudit = {
        record: record,
        currentRecordId: currentRecordId,
        handleOverlayPrint: handleOverlayPrint,
        recordSinglePrint: recordSinglePrint,
        recordBatchOpen: recordBatchOpen,
        recordBatchPrint: recordBatchPrint,
    };
}());
