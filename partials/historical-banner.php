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
                <div class="historical-banner-title" id="hb-title" data-i18n="index.historical.snapshot_title">نسخة تاريخية</div>
                <div class="historical-banner-subtitle" id="hb-subtitle" data-i18n="index.historical.snapshot_subtitle">تعرض الحالة قبل حدوث التغيير</div>
            </div>
        </div>
        <button data-action="timeline-load-current" class="historical-banner-btn">
            <span data-i18n="index.historical.return_to_current">↩️ العودة للوضع الحالي</span>
        </button>
    </div>
</div>
