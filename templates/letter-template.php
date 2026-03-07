<?php
/**
 * Letter Template - Single Source of Truth
 * 
 * Used for both:
 * - Preview (single guarantee in index.php)
 * - Batch Print (multiple guarantees in batch-print.php)
 * 
 * Required variables (extracted from $letterData):
 * @var array $header - Bank information
 * @var array $subject_parts - Subject parts (for proper lang attributes)
 * @var array $content - Content paragraphs and address box
 * @var array $signature - Signature details
 * @var array|null $cc - CC recipients (null if not applicable)
 * @var string $action - Action type (extension, release, etc.)
 * @var int|null $guarantee_id - Current guarantee id (if available)
 * @var bool $show_print_button - Overlay print button visibility
 */
 $signatureMarginTop = trim((string)($signature['margin_top'] ?? '3em'));
 if ($signatureMarginTop === '') {
     $signatureMarginTop = '3em';
 }
 $signatureMarginToken = preg_replace('/[^0-9]+/', '-', strtolower($signatureMarginTop));
 $signatureMarginToken = trim((string)$signatureMarginToken, '-');
 if ($signatureMarginToken === '') {
     $signatureMarginToken = 'default';
 }
 $signatureMarginClass = 'signature-seal-margin-' . $signatureMarginToken;
?>
<div class="letter-preview" data-guarantee-id="<?= isset($guarantee_id) ? (int)$guarantee_id : 0 ?>">
    <main class="letter-paper">
        
        <!-- Print Button Overlay (Only for screen) -->
        <button
            onclick="return (window.WBGLPrintAudit && window.WBGLPrintAudit.handleOverlayPrint)
                ? window.WBGLPrintAudit.handleOverlayPrint(this)
                : (window.print(), false)"
            class="btn-print-overlay"
            data-print-overlay="1"
            title=""
            aria-label=""
            data-i18n-title="timeline.ui.print_letter"
            data-i18n-aria-label="timeline.ui.print_letter"
            style="<?= !empty($show_print_button) ? '' : 'display:none;' ?>">
            🖨️
        </button>
        <style>
            .letter-paper {
                position: relative;
            }

            .preview-inline-code {
                display: inline-block;
            }

            .cc-section {
                margin-top: 40px;
                font-size: 12px !important;
            }

            .cc-title {
                font-weight: bold;
                margin-bottom: 0 !important;
                line-height: 14px !important;
                padding: 0 !important;
            }

            .cc-list {
                list-style-type: none;
                padding-right: 20px !important;
                margin: 0 !important;
            }

            .cc-item {
                margin: 0 !important;
                padding: 0 !important;
                line-height: 14px !important;
            }

            .<?= htmlspecialchars($signatureMarginClass, ENT_QUOTES, 'UTF-8') ?> {
                margin-top: <?= htmlspecialchars($signatureMarginTop, ENT_QUOTES, 'UTF-8') ?>;
            }

            .btn-print-overlay {
                position: absolute;
                top: 20px;
                left: 20px; /* Arabic RTL: Left is the "end" or correct side for LTR interface elements, or maybe user wants it on left? Screenshot arrow points to left corner. */
                width: 36px;
                height: 36px;
                border: 1px solid #e5e7eb;
                background: #fff;
                border-radius: 50%;
                cursor: pointer;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 18px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.05);
                transition: all 0.2s;
                z-index: 100;
                color: #4b5563;
            }
            .btn-print-overlay:hover {
                transform: scale(1.05);
                box-shadow: 0 4px 6px rgba(0,0,0,0.1);
                color: #111;
                border-color: #d1d5db;
            }
            @media print {
                .btn-print-overlay { display: none !important; }
            }
        </style>
        
        <!-- رأس الخطاب: اسم البنك + المحترمين -->
        <div class="preview-header">
            <div class="preview-recipient-name">
                <div data-i18n-skip="true">السادة <span class="symbol">/</span> <span data-preview-target="bank_name"><?= htmlspecialchars($header['bank_name']) ?></span></div>
            </div>
            <div class="preview-salutation">
                <div data-i18n-skip="true">المحترمين</div>
            </div>
        </div>
        
        <!-- معلومات البنك -->
        <div class="preview-recipient">
            <div><?= htmlspecialchars($header['bank_center']) ?></div>
            <div data-i18n-skip="true">ص.ب. <span lang="ar"><?= htmlspecialchars($header['bank_po_box']) ?></span></div>
            <div data-i18n-skip="true">البريد الإلكتروني<span class="symbol">:</span> <span lang="en"><?= htmlspecialchars($header['bank_email']) ?></span></div>
        </div>
        
        <!-- السلام عليكم -->
        <div class="preview-greeting">
            <div data-i18n-skip="true">السَّلام عليكُم ورحمَة الله وبركاتِه</div>
        </div>
        
        <!-- الموضوع -->
        <div class="preview-subject">
            <div class="preview-subject-label" data-i18n-skip="true">الموضوع<span class="symbol">:</span>&nbsp;</div>
            <div class="preview-subject-text">
                <span data-i18n-skip="true"><?= $subject_parts['text'] ?> الضمان البنكي رقم (<span data-preview-target="guarantee_number" lang="en" dir="ltr" class="preview-inline-code"><?= $subject_parts['guarantee_number'] ?></span>) والعائد <span data-preview-target="related_label"><?= $subject_parts['related_label'] ?></span> (<span data-preview-target="contract_number" <?= $relatedTo === 'contract' ? 'lang="en"' : 'lang="ar"' ?>><?= $subject_parts['contract_number'] ?></span>).</span>
            </div>
        </div>
        
        <!-- Content section: Dynamically built -->
        <div class="preview-content">
            <?php foreach ($content['paragraphs'] as $index => $paragraph): ?>
                <?php if ($index === 0): ?>
                    <!-- First paragraph -->
                    <div class="letter-paragraph"><?= $paragraph ?></div>
                    
                    <!-- Address box AFTER first paragraph (if applicable) -->
                    <?php if ($content['has_address_box']): ?>
                        <div class="preview-address-box" lang="ar">
                            <div class="letter-line" data-i18n-skip="true">مستشفى الملك فيصل التخصصي ومركز الأبحاث - الرياض</div>
                            <div class="letter-line" data-i18n-skip="true">ص.ب ٣٣٥٤ الرياض ١١٢١١</div>
                            <div class="letter-line" data-i18n-skip="true">مكتب الخدمات الإدارية</div>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <!-- Other paragraphs (including second paragraph) -->
                    <div class="letter-paragraph"><?= $paragraph ?></div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        
        <!-- التوقيع -->
        <div class="preview-clearfix">
            <div class="letter-line preview-note" data-i18n-skip="true">وَتفضَّلوا بِقبُول خَالِص تحيَّاتي</div>
            <div class="preview-signature">
                <div><?= $signature['title'] ?></div>
                <div class="signature-seal <?= htmlspecialchars($signatureMarginClass, ENT_QUOTES, 'UTF-8') ?>"><?= $signature['name'] ?></div>
            </div>
        </div>
        
        <?php if ($cc !== null): ?>
            <!-- صورة إلى (CC) - فقط للإفراج -->
            <div class="cc-section">
                <div class="cc-title" data-i18n-skip="true">صورة إلى:</div>
                <ul class="cc-list">
                    <?php foreach ($cc['recipients'] as $recipient): ?>
                        <li class="cc-item">
                            - <?= $recipient ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        
        <!-- التذييل (ثابت - لا يتغير أبداً) -->
        <div class="sheet-footer">
            <span class="footer-left" lang="en" data-i18n-skip="true">MBC: 9-2</span>
            <span class="footer-right" lang="en">BAMZ</span>
        </div>
        
    </main>
</div>
