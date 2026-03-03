<?php
/**
 * Supplier Suggestions Partial
 * Renders a list of suggestion chips driven by UnifiedLearningAuthority data.
 * 
 * Variables:
 * @var array $suggestions Array of canonical suggestion data (supplier_id, official_name, confidence, ...)
 */

if (empty($suggestions)): ?>
    <div class="record-suggestions-empty" data-i18n="index.suggestions.empty">لا توجد اقتراحات</div>
<?php else: 
    foreach ($suggestions as $sugg):
        $score = $sugg['confidence'] ?? 0;
        
        // Determine tooltip
        $tooltipKey = 'index.suggestions.confidence.high';
        if ($score < 70) $tooltipKey = 'index.suggestions.confidence.medium';
        if ($score < 50) $tooltipKey = 'index.suggestions.confidence.low';
        
        // Determine confidence level for CSS
        $confidenceLevel = 'high';
        if ($score < 85) $confidenceLevel = 'medium';
        if ($score < 65) $confidenceLevel = 'low';
        
        // Safe Name
        $safeName = htmlspecialchars($sugg['official_name'] ?? '', ENT_QUOTES);
?>
    <button 
        type="button" 
        class="chip chip-unified"
        data-action="selectSupplier"
        data-id="<?= htmlspecialchars($sugg['supplier_id'] ?? '') ?>"
        data-name="<?= $safeName ?>"
        data-confidence="<?= $confidenceLevel ?>"
        title=""
        data-i18n-title="<?= htmlspecialchars($tooltipKey, ENT_QUOTES, 'UTF-8') ?>">
        <span class="chip-name"><?= $safeName ?></span>
        <span class="chip-confidence"><?= $score ?>%</span>
    </button>
<?php endforeach; 
endif; ?>
