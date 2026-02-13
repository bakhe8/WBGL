<?php
/**
 * Partial: Supplier Suggestions
 * Returns HTML fragment for supplier suggestion chips
 * Used by: api/suggestions.php
 */

// $suggestions array must be provided by including script
if (!isset($suggestions)) {
    $suggestions = [];
}
?>

<div id="supplier-suggestions" class="chips-row">
    <?php if (empty($suggestions)): ?>
        <div style="font-size: 11px; color: #94a3b8; padding: 4px;">لا توجد اقتراحات</div>
    <?php else: ?>
        <?php foreach ($suggestions as $sugg): ?>
        <button 
            type="button" 
            class="chip<?= $sugg['is_learning'] ?? false ? ' chip-warning' : '' ?>"
            onclick="selectSupplierSuggestion(<?= $sugg['id'] ?>, '<?= htmlspecialchars($sugg['official_name'], ENT_QUOTES) ?>')"
        >
            <span class="chip-text">
                <?= htmlspecialchars($sugg['official_name']) ?>
                <?php if ($sugg['is_learning'] ?? false): ?>
                    <?php 
                    // UI LOGIC PROJECTION (Phase 3): Enhanced learning badge with SAFE LEARNING context
                    $learningTooltip = "🛡️ تعلم آلي\n" .
                                      "تم تعلمه من قرار سابق\n" .
                                      "النتيجة: 90% (محجوب تلقائياً)\n\n" .
                                      "لماذا؟\n" .
                                      "سياسة SAFE LEARNING تمنع " .
                                      "الموافقة التلقائية للأسماء المتعلمة";
                    ?>
                    <span class="badge badge-learning" title="<?= htmlspecialchars($learningTooltip) ?>">تعلم آلي</span>
                <?php endif; ?>
            </span>
            <?php if (isset($sugg['star_rating'])): ?>
                <span class="chip-stars">
                    <?php for ($i = 0; $i < $sugg['star_rating']; $i++): ?>⭐<?php endfor; ?>
                </span>
            <?php endif; ?>
        </button>
    <?php endforeach; ?>
    <?php endif; ?>
</div>
