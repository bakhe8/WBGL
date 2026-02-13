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
 * @var string $subject - Subject line (plain string)
 * @var array $subject_parts - Subject parts (for proper lang attributes)
 * @var array $content - Content paragraphs and address box
 * @var array $signature - Signature details
 * @var array|null $cc - CC recipients (null if not applicable)
 * @var string $action - Action type (extension, release, etc.)
 */
?>
<div class="letter-preview">
    <main class="letter-paper" style="position: relative;">
        
        <!-- Print Button Overlay (Only for screen) -->
        <button onclick="window.print()" class="btn-print-overlay" title="طباعة الخطاب">
            🖨️
        </button>
        <style>
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
                <div>السادة <span class="symbol">/</span> <span><?= htmlspecialchars($header['bank_name']) ?></span></div>
            </div>
            <div class="preview-salutation">
                <div>المحترمين</div>
            </div>
        </div>
        
        <!-- معلومات البنك -->
        <div class="preview-recipient">
            <div><?= htmlspecialchars($header['bank_center']) ?></div>
            <div>ص.ب. <span lang="ar"><?= htmlspecialchars($header['bank_po_box']) ?></span></div>
            <div>البريد الإلكتروني<span class="symbol">:</span> <span lang="en"><?= htmlspecialchars($header['bank_email']) ?></span></div>
        </div>
        
        <!-- السلام عليكم -->
        <div class="preview-greeting">
            <div>السَّلام عليكُم ورحمَة الله وبركاتِه</div>
        </div>
        
        <!-- الموضوع -->
        <div class="preview-subject">
            <div class="preview-subject-label">الموضوع<span class="symbol">:</span>&nbsp;</div>
            <div class="preview-subject-text">
                <?= $subject_parts['text'] ?> الضمان البنكي رقم (<span data-preview-target="guarantee_number" lang="en" dir="ltr" style="display:inline-block;"><?= $subject_parts['guarantee_number'] ?></span>) والعائد <span data-preview-target="related_label"><?= $subject_parts['related_label'] ?></span> (<span data-preview-target="contract_number" <?= $relatedTo === 'contract' ? 'lang="en"' : 'lang="ar"' ?>><?= $subject_parts['contract_number'] ?></span>).
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
                            <div class="letter-line">مستشفى الملك فيصل التخصصي ومركز الأبحاث - الرياض</div>
                            <div class="letter-line">ص.ب ٣٣٥٤ الرياض ١١٢١١</div>
                            <div class="letter-line">مكتب الخدمات الإدارية</div>
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
            <div class="letter-line preview-note">وَتفضَّلوا بِقبُول خَالِص تحيَّاتي</div>
            <div class="preview-signature">
                <div><?= $signature['title'] ?></div>
                <div class="signature-seal" style="margin-top: <?= $signature['margin_top'] ?>;"><?= $signature['name'] ?></div>
            </div>
        </div>
        
        <?php if ($cc !== null): ?>
            <!-- صورة إلى (CC) - فقط للإفراج -->
            <div class="cc-section" style="margin-top: 40px; font-size: 12px !important;">
                <div style="font-weight: bold; margin-bottom: 0px !important; line-height: 14px !important; padding: 0 !important;">صورة إلى:</div>
                <ul style="list-style-type: none; padding-right: 20px !important; margin: 0 !important;">
                    <?php foreach ($cc['recipients'] as $recipient): ?>
                        <li style="margin: 0 !important; padding: 0 !important; line-height: 14px !important;">
                            - <?= $recipient ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        
        <!-- التذييل (ثابت - لا يتغير أبداً) -->
        <div class="sheet-footer">
            <span class="footer-left" lang="en">MBC: 9-2</span>
            <span class="footer-right" lang="en">BAMZ</span>
        </div>
        
    </main>
</div>
