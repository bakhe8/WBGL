<?php

/**
 * Partial: Record Form Section
 * Returns HTML fragment for the main record form
 * Used by: api/get-record.php, index.php initial load
 */

// Required variables from including script:
// $record - array with record data
// $banks - array of banks for dropdown

if (!isset($record)) {
    $record = [
        'id' => 0,
        'supplier_name' => '',
        'bank_name' => '',
        'amount' => 0,
        'expiry_date' => '',
        'issue_date' => '',
        'contract_number' => '',
        'type' => null,
        'status' => 'pending'
    ];
}

// Default $isHistorical to false if not set
$isHistorical = $isHistorical ?? false;
$showInlineHistoricalBanner = $showInlineHistoricalBanner ?? $isHistorical;
$recordCanExecuteActions = $recordCanExecuteActions ?? true;
$bannerData = $bannerData ?? null; // Should contain ['timestamp' => '...', 'reason' => '...']
?>

<!-- Record Form Content -->
<div id="record-form-sec"
    data-record-index="<?= $index ?? 1 ?>"
    data-record-id="<?= $record['id'] ?? 0 ?>"
    data-decision-status="<?= htmlspecialchars((string)($record['status'] ?? 'pending'), ENT_QUOTES, 'UTF-8') ?>"
    data-active-action="<?= htmlspecialchars((string)($record['active_action'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
    data-workflow-step="<?= htmlspecialchars((string)($record['workflow_step'] ?? 'draft'), ENT_QUOTES, 'UTF-8') ?>"
    data-signatures-received="<?= (int)($record['signatures_received'] ?? 0) ?>">

    <!-- Phase 4: Hidden Inputs from DB (Current View) -->
    <input type="hidden" id="decisionStatus" value="<?= htmlspecialchars($record['status'] ?? 'pending') ?>">
    <input type="hidden" id="activeAction" value="<?= htmlspecialchars($record['active_action'] ?? '') ?>">
    <input type="hidden" id="workflowStep" value="<?= htmlspecialchars($record['workflow_step'] ?? 'draft') ?>">
    <input type="hidden" id="signaturesReceived" value="<?= (int)($record['signatures_received'] ?? 0) ?>">
    <input type="hidden" id="relatedTo" value="<?= htmlspecialchars($record['related_to'] ?? 'contract') ?>">

    <!-- Legacy: Keep for backward compatibility during transition -->
    <input type="hidden" id="eventSubtype" data-preview-field="event_subtype" value="<?= htmlspecialchars($latestEventSubtype ?? '') ?>">

</div>
<?php if ($showInlineHistoricalBanner): ?>
    <div class="historical-banner-card">
        <div class="historical-banner-info">
            <span class="historical-banner-icon">📜</span>
            <div>
                <div class="historical-banner-title" data-i18n="index.historical.read_only_title">نسخة تاريخية (READ ONLY)</div>
                <div class="historical-banner-subtitle">
                    <span data-i18n="index.historical.saved_at">تم الحفظ في:</span> <?= $bannerData['timestamp'] ?? 'N/A' ?>
                    <?php if (!empty($bannerData['reason'])): ?>
                        • <span data-i18n="index.historical.reason">السبب:</span> <?= htmlspecialchars($bannerData['reason']) ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <button class="historical-banner-btn" data-action="load-record" data-index="<?= $index ?? 1 ?>">
            <span data-i18n="index.historical.return_to_current">العودة للوضع الحالي ↩️</span>
        </button>
    </div>
<?php endif; ?>

<!-- Record Form Content -->
<?php
// ===== LIFECYCLE GATE: Determine if actions are allowed =====
// Only allow actions on READY guarantees AND policy executable records.
$recordStatusNormalized = strtolower(trim((string)($record['status'] ?? 'pending')));
$isReady = in_array($recordStatusNormalized, ['ready', 'approved'], true);
$recordWorkflowStep = strtolower(trim((string)($record['workflow_step'] ?? 'draft')));
$recordActiveAction = strtolower(trim((string)($record['active_action'] ?? '')));

// Resolve role slug (index passes $currentUserRoleSlug; API fallback resolves from current session user).
$effectiveRoleSlug = strtolower(trim((string)($currentUserRoleSlug ?? '')));
if ($effectiveRoleSlug === '') {
    try {
        $currentUserForRole = \App\Support\AuthService::getCurrentUser();
        if ($currentUserForRole && $currentUserForRole->roleId !== null) {
            $roleRepo = new \App\Repositories\RoleRepository(\App\Support\Database::connect());
            $role = $roleRepo->find((int)$currentUserForRole->roleId);
            $effectiveRoleSlug = strtolower(trim((string)($role->slug ?? '')));
        }
    } catch (\Throwable) {
        $effectiveRoleSlug = '';
    }
}
$isDataEntryRole = ($effectiveRoleSlug === 'data_entry');

// Finalized operationally: released + signed -> read-only archive.
$isFinalizedReleasedSigned = ($recordStatusNormalized === 'released' && $recordWorkflowStep === 'signed');

// Save decision is a data-completeness action, not a workflow-actionability action.
// It should follow guarantee_save permission + non-historical + non-finalized constraints.
$canSaveDecision = !$isHistorical
    && \App\Support\Guard::has('guarantee_save')
    && !$isFinalizedReleasedSigned;

// Data-entry can start a new lifecycle action when record is ready and has no active action.
$canStartLifecycleAction = $isDataEntryRole
    && $isReady
    && !$isFinalizedReleasedSigned
    && $recordActiveAction === '';

// Lifecycle mutation buttons are a data-entry surface only.
$showLifecycleMutationButtons = $isDataEntryRole;
$canMutateRecord = $canStartLifecycleAction;
$saveDisabledAttr = !$canSaveDecision ? 'disabled' : '';
$saveDisabledClass = !$canSaveDecision ? ' record-action-disabled' : '';
$disabledAttr = !$canMutateRecord ? 'disabled' : '';
$disabledClass = !$canMutateRecord ? ' record-action-disabled' : '';
$disabledTitle = !$canMutateRecord ? 'data-i18n-title="index.actions.unavailable_before_ready" title="غير متاح قبل اكتمال بيانات الضمان"' : '';
// ============================================================
?>
<header class="card-header">
    <div class="header-title record-header-title">
        <div class="record-actions record-actions-row">
            <button class="btn btn-secondary btn-sm<?= $saveDisabledClass ?>"
                data-action="saveAndNext"
                data-authorize-resource="guarantee"
                data-authorize-action="save"
                data-authorize-mode="disable"
                data-authorize-denied-key="index.permissions.denied.save_changes"
                <?= $saveDisabledAttr ?>>
                <span data-i18n="index.actions.save">💾 حفظ</span>
            </button>
            <div class="record-actions-divider"></div>

            <!-- Lifecycle actions are started by data-entry only -->
            <?php if ($showLifecycleMutationButtons): ?>
                <button class="btn btn-secondary btn-sm<?= $disabledClass ?>"
                    data-action="extend"
                    data-authorize-resource="guarantee"
                    data-authorize-action="extend"
                    data-authorize-mode="disable"
                    data-authorize-denied-key="index.permissions.denied.extend"
                    <?= $disabledAttr ?>
                    <?= $disabledTitle ?>>
                    <span data-i18n="index.actions.extend">🔄 تمديد</span>
                </button>
                <button class="btn btn-secondary btn-sm<?= $disabledClass ?>"
                    data-action="reduce"
                    data-authorize-resource="guarantee"
                    data-authorize-action="reduce"
                    data-authorize-mode="disable"
                    data-authorize-denied-key="index.permissions.denied.reduce"
                    <?= $disabledAttr ?>
                    <?= $disabledTitle ?>>
                    <span data-i18n="index.actions.reduce">📉 تخفيض</span>
                </button>
                <button class="btn btn-secondary btn-sm<?= $disabledClass ?>"
                    data-action="release"
                    data-authorize-resource="guarantee"
                    data-authorize-action="release"
                    data-authorize-mode="disable"
                    data-authorize-denied-key="index.permissions.denied.release"
                    <?= $disabledAttr ?>
                    <?= $disabledTitle ?>>
                    <span data-i18n="index.actions.release">📤 إفراج</span>
                </button>
            <?php endif; ?>

            <!-- Phase 3: Workflow Action Button -->
            <?php

            use App\Services\WorkflowService;
            use App\Support\Guard;
            use App\Models\GuaranteeDecision;

            // Mock object for logic check
            $decisionModel = new GuaranteeDecision(
                id: null,
                guaranteeId: (int)($record['id'] ?? 0),
                status: (string)($record['status'] ?? 'pending'),
                isLocked: (bool)($record['is_locked'] ?? false),
                activeAction: trim((string)($record['active_action'] ?? '')) !== '' ? (string)$record['active_action'] : null,
                workflowStep: $record['workflow_step'] ?? 'draft',
                signaturesReceived: $record['signatures_received'] ?? 0
            );

            $canAdvance = WorkflowService::canAdvance($decisionModel);
            $canReject = WorkflowService::canReject($decisionModel);
            $actionLabel = WorkflowService::getActionLabel($record['workflow_step'] ?? 'draft');
            $workflowStep = trim((string)($record['workflow_step'] ?? 'draft'));
            if ($workflowStep === '') {
                $workflowStep = 'draft';
            }
            $workflowActionI18nMap = [
                'draft' => 'index.workflow.action.audit',
                'audited' => 'index.workflow.action.analyze',
                'analyzed' => 'index.workflow.action.supervise',
                'supervised' => 'index.workflow.action.approve',
                'approved' => 'index.workflow.action.sign',
                'signed' => 'index.workflow.action.completed',
            ];
            $workflowStageUi = \App\Services\WorkflowStageDisplayService::describe(
                $workflowStep,
                trim((string)($record['active_action'] ?? '')),
                'index.workflow.step'
            );
            $workflowStepI18nKey = $workflowStageUi['key'] !== '' ? $workflowStageUi['key'] : null;
            $workflowActionI18nKey = $workflowActionI18nMap[$workflowStep] ?? null;
            $workflowStepLabel = $workflowStageUi['fallback_label'];
            // Operational UI default: hide raw workflow-step badge to reduce noise.
            // Can be enabled explicitly by setting $showWorkflowStagePill = true before including this partial.
            $showWorkflowStagePill = isset($showWorkflowStagePill) ? (bool)$showWorkflowStagePill : false;

            // ✅ PHASE 12: Contextual Role Reinforcement
            $stagePermissionsMap = [
                'draft' => ['p' => 'audit_data'],
                'audited' => ['p' => 'analyze_guarantee'],
                'analyzed' => ['p' => 'supervise_analysis'],
                'supervised' => ['p' => 'approve_decision'],
                'approved' => ['p' => 'sign_letters'],
            ];
            $req = $stagePermissionsMap[$workflowStep] ?? null;
            $requiredWorkflowPermission = $req['p'] ?? '';
            ?>
            <div class="workflow-controls">
                <?php if ($showWorkflowStagePill): ?>
                    <div class="workflow-stage-pill" title="" data-i18n-title="index.workflow.current_stage_title">
                        <span data-i18n="index.workflow.stage_label">المرحلة:</span>
                        <span class="workflow-stage-value"
                            <?= $workflowStepI18nKey ? 'data-i18n="' . htmlspecialchars($workflowStepI18nKey, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
                            <?= htmlspecialchars($workflowStepLabel) ?>
                        </span>
                    </div>
                <?php endif; ?>

                <?php if ($canAdvance && $recordCanExecuteActions): ?>
                    <button class="btn btn-secondary btn-sm workflow-action-btn"
                        data-action="workflow-advance"
                        data-step="<?= $workflowStep ?>"
                        data-authorize-permission="<?= htmlspecialchars($requiredWorkflowPermission, ENT_QUOTES, 'UTF-8') ?>"
                        data-authorize-mode="hide"
                        title=""
                        data-i18n-title="index.workflow.execute_next_step">
                        ⚡ <span <?= $workflowActionI18nKey ? 'data-i18n="' . htmlspecialchars($workflowActionI18nKey, ENT_QUOTES, 'UTF-8') . '"' : '' ?>><?= htmlspecialchars($actionLabel) ?></span>
                    </button>
                <?php endif; ?>
                <?php if ($canReject && $recordCanExecuteActions): ?>
                    <button class="btn btn-secondary btn-sm workflow-action-btn"
                        data-action="workflow-reject"
                        data-step="<?= $workflowStep ?>"
                        data-authorize-permission="<?= htmlspecialchars($requiredWorkflowPermission, ENT_QUOTES, 'UTF-8') ?>"
                        data-authorize-mode="hide"
                        title=""
                        data-i18n-title="index.workflow.reject.title">
                        ⛔ <span data-i18n="index.workflow.reject.label">رفض</span>
                    </button>
                <?php endif; ?>
            </div>
        </div>

</header>

<div class="card-body">
    <!-- Supplier Field Section -->
    <div class="record-supplier-section">
        <?php
        $isDecisionMade = in_array($recordStatusNormalized, ['ready', 'issued', 'approved', 'released', 'signed'], true);
        $hideSuggestions = $isHistorical || $isDecisionMade;
        ?>
        <!-- Supplier Field -->
        <div class="field-group">
            <div class="field-row">
                <label class="field-label">
                    <span data-i18n="index.fields.supplier">المورد</span>
                    <?php
                    // Contextual status indicator for supplier
                    $supplierMissing = empty($record['supplier_id']);
                    if ($supplierMissing && ($record['status'] ?? 'pending') === 'pending'):
                    ?>
                        <span class="field-status-indicator field-status-missing" title="" data-i18n-title="index.fields.supplier_missing">⚠️</span>
                    <?php elseif (!$supplierMissing): ?>
                        <span class="field-status-indicator field-status-ok" title="" data-i18n-title="index.fields.supplier_defined">✓</span>
                    <?php endif; ?>
                </label>
                <?php $supplierInputDisabled = ($isHistorical || $isDecisionMade || !$canSaveDecision); ?>
                <input type="text"
                    class="field-input<?= $supplierInputDisabled ? ' field-input--readonly' : '' ?>"
                    id="supplierInput"
                    name="supplier_name"
                    data-preview-field="supplier_name"
                    value="<?= htmlspecialchars($record['supplier_name'], ENT_QUOTES, 'UTF-8', false) ?>"
                    data-record-id="<?= $record['id'] ?>"
                    data-action="processSupplierInput"
                    <?= $supplierInputDisabled ? 'readonly disabled' : '' ?>>
                <input type="hidden" id="supplierIdHidden" name="supplier_id" value="<?= $record['supplier_id'] ?? '' ?>">
            </div>

            <!-- Suggestions Chips -->
            <div class="chips-row" id="supplier-suggestions" <?= $hideSuggestions ? 'hidden' : '' ?>>
                <?php if (!empty($supplierMatch['suggestions'])): ?>
                    <?php foreach ($supplierMatch['suggestions'] as $sugg):
                        // Skip if this suggestion is already the selected & approved supplier
                        $isSelected = ($record['supplier_id'] == ($sugg['supplier_id'] ?? 0));
                        $isApproved = in_array($recordStatusNormalized, ['ready', 'issued', 'approved', 'released', 'signed'], true);

                        if ($isSelected && $isApproved) continue;
                    ?>
                        <?php
                        // Determine confidence level for tooltip
                        $score = $sugg['confidence'] ?? 0;
                        $tooltipKey = 'index.suggestions.confidence.high';
                        if ($score < 70) $tooltipKey = 'index.suggestions.confidence.medium';
                        if ($score < 50) $tooltipKey = 'index.suggestions.confidence.low';

                        // Determine confidence level for CSS (high/medium/low)
                        $confidenceLevel = 'high';
                        if ($score < 85) $confidenceLevel = 'medium';
                        if ($score < 65) $confidenceLevel = 'low';
                        ?>
                        <!-- ✅ UX UNIFICATION: Uniform display with subtle opacity + tooltip -->
                        <button class="chip chip-unified"
                            data-action="selectSupplier"
                            data-id="<?= $sugg['supplier_id'] ?? 0 ?>"
                            data-name="<?= htmlspecialchars($sugg['official_name'] ?? '') ?>"
                            data-confidence="<?= $confidenceLevel ?>"
                            title=""
                            data-i18n-title="<?= htmlspecialchars($tooltipKey, ENT_QUOTES, 'UTF-8') ?>">
                            <span class="chip-name"><?= htmlspecialchars($sugg['official_name'] ?? '') ?></span>
                            <span class="chip-confidence"><?= $sugg['confidence'] ?? 0 ?>%</span>
                        </button>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="record-suggestions-empty" data-i18n="index.suggestions.empty">لا توجد اقتراحات</div>
                <?php endif; ?>
            </div>

            <!-- Add Supplier Button (Hidden by default OR when status is ready) -->
            <div id="addSupplierContainer" class="add-supplier-container" hidden>
                <button class="btn btn-sm btn-outline-primary add-supplier-btn"
                    data-action="createSupplier"
                    data-authorize-resource="supplier"
                    data-authorize-action="manage"
                    data-authorize-mode="disable"
                    data-authorize-denied-key="index.permissions.denied.add_supplier">
                    <span>➕</span>
                    <span data-i18n="index.supplier.add_and_assign_prefix">إضافة "</span><span id="newSupplierName"></span><span data-i18n="index.supplier.add_and_assign_suffix">" وتعيينه كمورد لهذا الضمان</span>
                </button>
            </div>

            <div class="field-hint">
                <div class="hint-group">
                    <span class="hint-label">Excel:</span>
                    <span class="hint-value" id="excelSupplier">
                        <?= htmlspecialchars($guarantee->rawData['supplier'] ?? '') ?>
                    </span>
                </div>
            </div>
        </div>
    </div>
    <!-- End of fields-grid -->

    <!-- Info Grid -->
    <div class="info-grid">
        <div class="info-item">
            <div class="info-label" data-i18n="index.fields.guarantee_number">رقم الضمان</div>
            <div class="info-value" data-preview-field="guarantee_number"><?= htmlspecialchars($record['guarantee_number'] ?? 'N/A') ?></div>
        </div>
        <div class="info-item">
            <div class="info-label">
                <?php
                // 🔥 Read from raw_data to determine correct label
                $relatedTo = $guarantee->rawData['related_to'] ?? 'contract';
                $contractLabelKey = $relatedTo === 'purchase_order'
                    ? 'index.fields.purchase_order_number'
                    : 'index.fields.contract_number';
                ?>
                <span data-i18n="<?= htmlspecialchars($contractLabelKey, ENT_QUOTES, 'UTF-8') ?>"></span>
            </div>
            <div class="info-value" data-preview-field="contract_number"><?= htmlspecialchars($record['contract_number']) ?></div>
        </div>
        <div class="info-item">
            <div class="info-label" data-i18n="index.fields.amount">المبلغ</div>
            <div class="info-value highlight" data-preview-field="amount">
                <?= number_format($record['amount'], 2, '.', ',') ?> ر.س
            </div>
        </div>
        <div class="info-item">
            <div class="info-label" data-i18n="index.fields.expiry_date">تاريخ الانتهاء</div>
            <div class="info-value" data-preview-field="expiry_date"><?= htmlspecialchars($record['expiry_date']) ?></div>
        </div>
        <?php if (!empty($record['type'])): ?>
            <div class="info-item">
                <div class="info-label" data-i18n="index.fields.type">النوع</div>
                <div class="info-value" data-preview-field="type"><?= htmlspecialchars($record['type']) ?></div>
            </div>
        <?php endif; ?>
        <div class="info-item">
            <div class="info-label" data-i18n="index.fields.bank">البنك</div>
            <?php
            $bankNameValue = trim((string)($record['bank_name'] ?? ''));
            ?>
            <div class="info-value" data-preview-field="bank_name">
                <?php if ($bankNameValue !== ''): ?>
                    <?= htmlspecialchars($bankNameValue) ?>
                <?php else: ?>
                    <span data-i18n="index.fields.undefined">غير محدد</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
