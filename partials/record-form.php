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
$bannerData = $bannerData ?? null; // Should contain ['timestamp' => '...', 'reason' => '...']
?>

<!-- Record Form Content -->
<div id="record-form-sec" 
     data-record-index="<?= $index ?? 1 ?>" 
     data-record-id="<?= $record['id'] ?? 0 ?>">
     
    <!-- Phase 4: Hidden Inputs from DB (Current View) -->
    <input type="hidden" id="decisionStatus" value="<?= htmlspecialchars($record['status'] ?? 'pending') ?>">
    <input type="hidden" id="activeAction" value="<?= htmlspecialchars($record['active_action'] ?? '') ?>">
    <input type="hidden" id="relatedTo" value="<?= htmlspecialchars($record['related_to'] ?? 'contract') ?>">
     
    <!-- Legacy: Keep for backward compatibility during transition -->
    <input type="hidden" id="eventSubtype" data-preview-field="event_subtype" value="<?= htmlspecialchars($latestEventSubtype ?? '') ?>">

</div>
<?php if ($isHistorical): ?>
<div style="background-color: #fffbeb; border: 1px solid #f59e0b; padding: 12px; border-radius: 8px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">
    <div style="display: flex; align-items: center; gap: 12px;">
        <span style="font-size: 20px;">📜</span>
        <div>
            <div style="font-weight: bold; color: #92400e; font-size: 14px;">نسخة تاريخية (READ ONLY)</div>
            <div style="font-size: 12px; color: #b45309;">
                تم الحفظ في: <?= $bannerData['timestamp'] ?? 'N/A' ?>
                <?php if(!empty($bannerData['reason'])): ?>
                     • السبب: <?= htmlspecialchars($bannerData['reason']) ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <button class="btn btn-sm btn-outline-warning" data-action="load-record" data-index="<?= $index ?? 1 ?>" style="background: white; border: 1px solid #d97706; color: #d97706;">
        العودة للوضع الحالي ↩️
    </button>
</div>
<?php endif; ?>

<!-- Record Form Content -->
<?php 
// ===== LIFECYCLE GATE: Determine if actions are allowed =====
// Only allow actions (extend/reduce/release) on READY guarantees
$isReady = ($record['status'] ?? 'pending') === 'ready';
$disabledAttr = !$isReady ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : '';
$disabledTitle = !$isReady ? 'title="غير متاح قبل اكتمال بيانات الضمان"' : '';
// ============================================================
?>
<header class="card-header">
    <div class="header-title" style="display: flex; align-items: center; width: 100%;">
        <div class="record-actions" style="display: flex; gap: 8px; flex: 1; align-items: center;">
            <button class="btn btn-secondary btn-sm" data-action="saveAndNext">💾 حفظ</button>
            <div style="width: 1px; height: 20px; background: #e2e8f0; margin: 0 4px;"></div>
            
            <!-- ⚠️ Actions disabled if not ready -->
            <button class="btn btn-secondary btn-sm" 
                    data-action="extend" 
                    <?= $disabledAttr ?> 
                    <?= $disabledTitle ?>>
                🔄 تمديد
            </button>
            <button class="btn btn-secondary btn-sm" 
                    data-action="reduce" 
                    <?= $disabledAttr ?> 
                    <?= $disabledTitle ?>>
                📉 تخفيض
            </button>
            <button class="btn btn-secondary btn-sm" 
                    data-action="release" 
                    <?= $disabledAttr ?> 
                    <?= $disabledTitle ?>>
                📤 إفراج
            </button>
        </div>

</header>

<div class="card-body">
    <!-- Supplier Field Section -->
    <div style="margin-bottom: 20px;">
        <?php 
        $isDecisionMade = ($record['status'] ?? 'pending') === 'ready' || ($record['status'] ?? 'pending') === 'issued';
        $hideSuggestions = $isHistorical || $isDecisionMade;
        ?>
    <!-- Supplier Field -->
    <div class="field-group">
        <div class="field-row">
            <label class="field-label">
                المورد
                <?php 
                // Contextual status indicator for supplier
                $supplierMissing = empty($record['supplier_id']);
                if ($supplierMissing && ($record['status'] ?? 'pending') === 'pending'):
                ?>
                    <span class="field-status-indicator field-status-missing" title="المورد غير محدد - يحتاج قرار">⚠️</span>
                <?php elseif (!$supplierMissing): ?>
                    <span class="field-status-indicator field-status-ok" title="المورد محدد">✓</span>
                <?php endif; ?>
            </label>
            <input type="text" 
                   class="field-input" 
                   id="supplierInput" 
                   name="supplier_name"
                   data-preview-field="supplier_name"
                   value="<?= htmlspecialchars($record['supplier_name'], ENT_QUOTES, 'UTF-8', false) ?>"
                   data-record-id="<?= $record['id'] ?>"
                   data-action="processSupplierInput"
                   <?= ($isHistorical || $isDecisionMade) ? 'readonly disabled style="background:#f9fafb;cursor:not-allowed;"' : '' ?>>
            <input type="hidden" id="supplierIdHidden" name="supplier_id" value="<?= $record['supplier_id'] ?? '' ?>">
        </div>
        
        <!-- Suggestions Chips -->
        <div class="chips-row" id="supplier-suggestions" <?= $hideSuggestions ? 'style="display:none"' : '' ?>>
            <?php if (!empty($supplierMatch['suggestions'])): ?>
                <?php foreach ($supplierMatch['suggestions'] as $sugg): 
                    // Skip if this suggestion is already the selected & approved supplier
                    $isSelected = ($record['supplier_id'] == ($sugg['id'] ?? 0));
                    $isApproved = ($record['status'] ?? '') === 'ready' || ($record['status'] ?? '') === 'issued'; // "Ready" or "Issued"
                    
                    if ($isSelected && $isApproved) continue;
                ?>
                    <?php
                    // Determine confidence level for tooltip
                    $score = $sugg['score'] ?? 0;
                    $tooltipText = 'ثقة عالية';
                    if ($score < 70) $tooltipText = 'ثقة متوسطة';
                    if ($score < 50) $tooltipText = 'ثقة منخفضة';
                    
                    // Determine confidence level for CSS (high/medium/low)
                    $confidenceLevel = 'high';
                    if ($score < 85) $confidenceLevel = 'medium';
                    if ($score < 65) $confidenceLevel = 'low';
                    ?>
                    <!-- ✅ UX UNIFICATION: Uniform display with subtle opacity + tooltip -->
                    <button class="chip chip-unified" 
                            data-action="selectSupplier"
                            data-id="<?= $sugg['id'] ?? 0 ?>"
                            data-name="<?= htmlspecialchars($sugg['name'] ?? '') ?>"
                            data-confidence="<?= $confidenceLevel ?>"
                            title="<?= $tooltipText ?>">
                        <span class="chip-name"><?= htmlspecialchars($sugg['name'] ?? '') ?></span>
                        <span class="chip-confidence"><?= $sugg['score'] ?? 0 ?>%</span>
                    </button>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="font-size: 11px; color: #94a3b8; padding: 4px;">لا توجد اقتراحات</div>
            <?php endif; ?>
        </div>
        
        <!-- Add Supplier Button (Hidden by default OR when status is ready) -->
        <?php 
        $hideAddButton = $isHistorical || $isDecisionMade || ($record['status'] ?? 'pending') === 'ready';
        ?>
        <div id="addSupplierContainer" style="display:<?= $hideAddButton ? 'none' : 'none' ?>; margin-top: 8px;">
            <button class="btn btn-sm btn-outline-primary" 
                    data-action="createSupplier"
                    style="width: 100%; justify-content: center; gap: 8px; border-style: dashed;">
                <span>➕</span>
                <span>إضافة "<span id="newSupplierName"></span>" وتعيينه كمورد لهذا الضمان</span>
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
            <div class="info-label">رقم الضمان</div>
            <div class="info-value" data-preview-field="guarantee_number"><?= htmlspecialchars($record['guarantee_number'] ?? 'N/A') ?></div>
        </div>
        <div class="info-item">
            <div class="info-label">
                <?php 
                // 🔥 Read from raw_data to determine correct label
                $relatedTo = $guarantee->rawData['related_to'] ?? 'contract';
                echo $relatedTo === 'purchase_order' ? 'رقم أمر الشراء' : 'رقم العقد';
                ?>
            </div>
            <div class="info-value" data-preview-field="contract_number"><?= htmlspecialchars($record['contract_number']) ?></div>
        </div>
        <div class="info-item">
            <div class="info-label">المبلغ</div>
            <div class="info-value highlight" data-preview-field="amount">
                <?= number_format($record['amount'], 2, '.', ',') ?> ر.س
            </div>
        </div>
        <div class="info-item">
            <div class="info-label">تاريخ الانتهاء</div>
            <div class="info-value" data-preview-field="expiry_date"><?= htmlspecialchars($record['expiry_date']) ?></div>
        </div>
        <?php if (!empty($record['type'])): ?>
        <div class="info-item">
            <div class="info-label">النوع</div>
            <div class="info-value" data-preview-field="type"><?= htmlspecialchars($record['type']) ?></div>
        </div>
        <?php endif; ?>
        <div class="info-item">
            <div class="info-label">البنك</div>
            <div class="info-value" data-preview-field="bank_name"><?= htmlspecialchars($record['bank_name'] ?? 'غير محدد') ?></div>
        </div>
    </div>
</div>
