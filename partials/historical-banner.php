<?php
/**
 * Historical Banner Partial
 * Shows a banner indicating the user is viewing a historical snapshot.
 */
?>
<div id="historical-banner" class="historical-banner">
    <div class="historical-banner-card">
        <div class="historical-banner-info">
            <span class="historical-banner-icon">🕰️</span>
            <div>
                <div class="historical-banner-title">نسخة تاريخية</div>
                <div class="historical-banner-subtitle">تعرض الحالة قبل حدوث التغيير</div>
            </div>
        </div>
        <button data-action="timeline-load-current" class="historical-banner-btn">
            ↩️ العودة للوضع الحالي
        </button>
    </div>
</div>
