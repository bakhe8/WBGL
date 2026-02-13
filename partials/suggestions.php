<?php
/**
 * Supplier Suggestions Partial
 * Renders a list of suggestion chips driven by UnifiedLearningAuthority data.
 * 
 * Variables:
 * @var array $suggestions Array of suggestion data (id, official_name, score, etc.)
 */

if (empty($suggestions)): ?>
    <div style="font-size: 11px; color: #94a3b8; padding: 4px;">لا توجد اقتراحات</div>
<?php else: 
    foreach ($suggestions as $sugg):
        $score = $sugg['score'] ?? 0;
        
        // Determine tooltip
        $tooltipText = 'ثقة عالية';
        if ($score < 70) $tooltipText = 'ثقة متوسطة';
        if ($score < 50) $tooltipText = 'ثقة منخفضة';
        
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
        data-id="<?= htmlspecialchars($sugg['id'] ?? '') ?>"
        data-name="<?= $safeName ?>"
        data-confidence="<?= $confidenceLevel ?>"
        title="<?= $tooltipText ?>">
        <span class="chip-name"><?= $safeName ?></span>
        <span class="chip-confidence"><?= $score ?>%</span>
    </button>
<?php endforeach; 
endif; ?>
