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

    function normalizeDecisionStatus(value) {
        const normalized = String(value || '').trim().toLowerCase();
        return normalized === 'approved' ? 'ready' : normalized;
    }

    function historicalModeActive() {
        if (window.timelineController && window.timelineController.isHistoricalView) {
            return true;
        }

        const bannerContainer = document.getElementById('historical-banner-container');
        if (!bannerContainer) {
            return false;
        }

        return !bannerContainer.hidden && !bannerContainer.classList.contains('u-hidden');
    }

    function hasPrintAuditBypass() {
        const body = document.body;
        if (!body || !body.dataset) {
            return false;
        }
        return String(body.dataset.printAuditBypass || '').trim() === '1';
    }

    function resolvePrintGuard() {
        if (hasPrintAuditBypass()) {
            return { allowed: true, reason: 'bypass' };
        }

        const body = document.body;
        const hasPrintPermission = body && body.dataset
            ? String(body.dataset.printPermission || '').trim() !== '0'
            : true;
        if (!hasPrintPermission) {
            return { allowed: false, reason: 'permission_denied' };
        }

        const explicit = body && body.dataset ? String(body.dataset.printAllowed || '').trim() : '';
        if (explicit === '1' || explicit === '0') {
            return {
                allowed: explicit === '1',
                reason: body && body.dataset ? String(body.dataset.printGuardMode || '') : '',
            };
        }

        const decisionStatusInput = document.getElementById('decisionStatus');
        const workflowStepInput = document.getElementById('workflowStep');
        const activeActionInput = document.getElementById('activeAction');
        const signaturesInput = document.getElementById('signaturesReceived');
        const recordHolder = document.getElementById('record-form-sec');
        const hasDecisionContext = decisionStatusInput || workflowStepInput || activeActionInput || signaturesInput;
        if (!hasDecisionContext) {
            return { allowed: true, reason: 'no_decision_context' };
        }

        const statusRaw = decisionStatusInput
            ? decisionStatusInput.value
            : (recordHolder instanceof HTMLElement ? String(recordHolder.dataset.decisionStatus || '') : '');
        const workflowRaw = workflowStepInput
            ? workflowStepInput.value
            : (recordHolder instanceof HTMLElement ? String(recordHolder.dataset.workflowStep || '') : '');
        const activeActionRaw = activeActionInput
            ? activeActionInput.value
            : (recordHolder instanceof HTMLElement ? String(recordHolder.dataset.activeAction || '') : '');
        const signaturesRaw = signaturesInput
            ? signaturesInput.value
            : (recordHolder instanceof HTMLElement ? String(recordHolder.dataset.signaturesReceived || '0') : '0');

        const statusValue = normalizeDecisionStatus(statusRaw);
        const workflowStep = String(workflowRaw || '').trim().toLowerCase();
        const activeAction = String(activeActionRaw || '').trim().toLowerCase();
        const signaturesReceived = Number.parseInt(String(signaturesRaw || '0'), 10);
        const hasSignature = Number.isFinite(signaturesReceived) && signaturesReceived > 0;
        const allowed = !historicalModeActive()
            && statusValue === 'ready'
            && workflowStep === 'signed'
            && activeAction !== ''
            && hasSignature;

        return {
            allowed: allowed,
            reason: allowed ? '' : 'record',
        };
    }

    function blockedMessage(reason) {
        const permissionFallback = 'لا تملك صلاحية طباعة الخطابات.';
        const workflowFallback = 'الطباعة غير متاحة قبل اكتمال التوقيع النهائي.';
        const normalizedReason = String(reason || '').trim();
        if (window.WBGLI18n && typeof window.WBGLI18n.t === 'function') {
            if (normalizedReason === 'permission_denied' || normalizedReason === 'permission') {
                return window.WBGLI18n.t('messages.print.permission_denied', permissionFallback);
            }
            return window.WBGLI18n.t('messages.print.blocked_before_signed', workflowFallback);
        }
        return (normalizedReason === 'permission_denied' || normalizedReason === 'permission')
            ? permissionFallback
            : workflowFallback;
    }

    function notifyPrintBlocked(reason) {
        const message = blockedMessage(reason);
        if (typeof window.showToast === 'function') {
            window.showToast(message, 'warning');
            return;
        }
        window.alert(message);
    }

    function isPrintAllowed() {
        return resolvePrintGuard().allowed;
    }

    function setPrintOverlayVisibility(hidden) {
        const overlays = document.querySelectorAll('[data-print-overlay="1"]');
        overlays.forEach(function (node) {
            if (!(node instanceof HTMLElement)) {
                return;
            }

            if (hidden) {
                if (!Object.prototype.hasOwnProperty.call(node.dataset, 'prePrintDisplay')) {
                    node.dataset.prePrintDisplay = node.style.display || '';
                }
                node.style.setProperty('display', 'none', 'important');
                node.style.setProperty('visibility', 'hidden', 'important');
                node.style.setProperty('opacity', '0', 'important');
                return;
            }

            if (Object.prototype.hasOwnProperty.call(node.dataset, 'prePrintDisplay')) {
                node.style.display = node.dataset.prePrintDisplay;
                delete node.dataset.prePrintDisplay;
            } else {
                node.style.removeProperty('display');
            }
            node.style.removeProperty('visibility');
            node.style.removeProperty('opacity');
        });
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
        if (hasPrintAuditBypass()) {
            return {
                success: true,
                bypassed: true,
                event_type: String(eventType || ''),
                context: String(context || ''),
            };
        }

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
        const guard = resolvePrintGuard();
        if (!guard.allowed) {
            notifyPrintBlocked(guard.reason);
            return false;
        }

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

        setPrintOverlayVisibility(true);
        window.print();
        return false;
    }

    function recordSinglePrint(guaranteeId, meta) {
        const guard = resolvePrintGuard();
        if (!guard.allowed) {
            notifyPrintBlocked(guard.reason);
            return Promise.reject(new Error('print_blocked_before_signed'));
        }
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

    function bindPrintShortcutGuard() {
        if (window.__wbglPrintShortcutGuardBound) {
            return;
        }
        window.__wbglPrintShortcutGuardBound = true;

        document.addEventListener('keydown', function (event) {
            const key = String(event.key || '').toLowerCase();
            if (key !== 'p') {
                return;
            }
            if (!(event.ctrlKey || event.metaKey) || event.altKey) {
                return;
            }
            const guard = resolvePrintGuard();
            if (guard.allowed) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            notifyPrintBlocked(guard.reason);
        }, true);
    }

    function bindPrintLifecycleGuard() {
        if (window.__wbglPrintLifecycleGuardBound) {
            return;
        }
        window.__wbglPrintLifecycleGuardBound = true;

        window.addEventListener('beforeprint', function () {
            setPrintOverlayVisibility(true);
        });

        window.addEventListener('afterprint', function () {
            setPrintOverlayVisibility(false);
        });
    }

    bindPrintShortcutGuard();
    bindPrintLifecycleGuard();

    window.WBGLPrintAudit = {
        record: record,
        currentRecordId: currentRecordId,
        isPrintAllowed: isPrintAllowed,
        notifyPrintBlocked: notifyPrintBlocked,
        handleOverlayPrint: handleOverlayPrint,
        recordSinglePrint: recordSinglePrint,
        recordBatchOpen: recordBatchOpen,
        recordBatchPrint: recordBatchPrint,
    };
}());
