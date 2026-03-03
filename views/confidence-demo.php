<?php
require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\ViewPolicy;

ViewPolicy::guardView('confidence-demo.php');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart Paste Confidence Demo - WBGL</title>
    <link rel="stylesheet" href="../public/css/confidence-indicators.css">
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; background: #f9fafb; }
        .demo-container { background: white; padding: 30px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        h1 { color: #1f2937; margin-bottom: 10px; }
        .subtitle { color: #6b7280; margin-bottom: 30px; }
        .demo-section { margin-bottom: 30px; padding: 20px; background: #f9fafb; border-radius: 8px; }
        .demo-title { font-weight: 600; color: #374151; margin-bottom: 15px; font-size: 16px; }
        input { width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; }
        .example-grid { display: grid; gap: 15px; margin-top: 15px; }
        button { padding: 10px 20px; background: #3b82f6; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; }
        button:hover { background: #2563eb; }
        .code-block { background: #1f2937; color: #e5e7eb; padding: 15px; border-radius: 6px; font-family: 'Courier New', monospace; font-size: 13px; overflow-x: auto; margin-top: 10px; }
        .demo-input-high { border-color: #10b981; border-width: 2px; }
        .demo-input-medium { border-color: #f59e0b; border-width: 2px; }
        .demo-input-low { border-color: #ef4444; border-width: 2px; }
        .interactive-help { font-size: 14px; color: #6b7280; margin-bottom: 10px; }
        .demo-input-interactive { margin-bottom: 10px; }
        .demo-actions { display: flex; gap: 10px; }
        .tech-list { font-size: 14px; color: #374151; line-height: 1.8; }
        .thresholds-wrap { margin-top: 15px; }
        .thresholds-title { color: #1f2937; }
        .code-block-sm { font-size: 12px; }
    </style>
</head>
<body data-i18n-namespaces="common">
    <div class="demo-container">
        <h1 data-i18n="common.ui.confidence_demo.title">🎯 Smart Paste Confidence Layer - Demo</h1>
        <p class="subtitle" data-i18n="common.ui.txt_c1b02a51">عرض توضيحي لنظام تقييم الثقة في البيانات المستخرجة</p>
        
        <!-- Example 1: High Confidence -->
        <div class="demo-section">
            <div class="demo-title" data-i18n="common.ui.txt_bed4e131">مثال 1: ثقة عالية (95%) - تطابق تام</div>
            <div class="example-grid">
                <div class="field-with-confidence">
                    <div class="confidence-indicator">
                        <span class="confidence-badge confidence-high confidence-tooltip" data-i18n-title="common.ui.txt_4fcf7ee3" title="تطابق تام مع اسم معروف">
                            <span>✅</span>
                            <span class="confidence-percentage">95%</span>
                            <span data-i18n="common.ui.txt_59de5bef">عالية</span>
                        </span>
                    </div>
                    <input type="text" value="شركة المقاولون العرب" data-i18n-placeholder="common.ui.txt_3e0ae740" readonly class="demo-input-high">
                </div>
            </div>
            <div class="code-block" data-i18n="common.ui.confidence_demo.code_high">النص المُدخل: "ضمان بنكي من شركة المقاولون العرب"<br>نوع المطابقة: exact match<br>النتيجة: ✅ قبول تلقائي</div>
        </div>
        
        <!-- Example 2: Medium Confidence -->
        <div class="demo-section">
            <div class="demo-title" data-i18n="common.ui.txt_90350a7a">مثال 2: ثقة متوسطة (75%) - يحتاج مراجعة</div>
            <div class="example-grid">
                <div class="field-with-confidence">
                    <div class="confidence-indicator">
                        <span class="confidence-badge confidence-medium confidence-tooltip" data-i18n-title="common.ui.txt_1bc16293" title="تشابه متوسط (88%)">
                            <span>⚠️</span>
                            <span class="confidence-percentage">75%</span>
                            <span data-i18n="common.ui.txt_4167c04a">متوسطة</span>
                        </span>
                    </div>
                    <input type="text" value="شركة النهضه للمقاولات" data-i18n-placeholder="common.ui.confidence_demo.medium_supplier" readonly class="demo-input-medium">
                </div>
                <div class="confidence-warning">
                    <div class="confidence-warning-icon">⚠️</div>
                    <div data-i18n="common.ui.txt_a83625b6">الثقة في البيانات المستخرجة متوسطة (75%). يُرجى المراجعة.</div>
                </div>
            </div>
            <div class="code-block" data-i18n="common.ui.confidence_demo.code_medium">النص المُدخل: "ضمان من شركة النهضه"<br>نوع المطابقة: fuzzy match (88% similarity)<br>النتيجة: ⚠️ يُعرض مع تحذير</div>
        </div>
        
        <!-- Example 3: Low Confidence -->
        <div class="demo-section">
            <div class="demo-title" data-i18n="common.ui.txt_76ac73a5">مثال 3: ثقة منخفضة (45%) - مرفوض</div>
            <div class="example-grid">
                <div class="field-with-confidence">
                    <div class="confidence-indicator">
                        <span class="confidence-badge confidence-low confidence-tooltip" data-i18n-title="common.ui.txt_45d748c3" title="تشابه ضعيف (62%) + نص مشبوه">
                            <span>❌</span>
                            <span class="confidence-percentage">45%</span>
                            <span data-i18n="common.ui.txt_81cffdee">منخفضة</span>
                        </span>
                    </div>
                    <input type="text" value="الراجحي" data-i18n-placeholder="common.ui.txt_bf22e734" readonly class="demo-input-low">
                </div>
                <div class="confidence-warning">
                    <div class="confidence-warning-icon">❌</div>
                    <div data-i18n="common.ui.txt_0d935c06">الثقة منخفضة جداً (45%). يُنصح بالإدخال اليدوي.</div>
                </div>
            </div>
            <div class="code-block" data-i18n="common.ui.confidence_demo.code_low">النص المُدخل: "Lorem ipsum dolor sit"<br>نوع المطابقة: fuzzy match (62% similarity)<br>النتيجة: ❌ مرفوض - gibberish text detected</div>
        </div>
        
        <!-- Interactive Demo -->
        <div class="demo-section">
            <div class="demo-title" data-i18n="common.ui.txt_88a0f02b">🎮 تجربة تفاعلية</div>
            <p class="interactive-help" data-i18n="common.ui.txt_e3bb7391">جرب إضافة مؤشر ثقة لحقل إدخال:</p>
            <input type="text" id="demoInput" data-i18n-placeholder="common.ui.txt_726a87d4" placeholder="اكتب اسم مورد..." class="demo-input-interactive">
            <div class="demo-actions">
                <button data-i18n="common.ui.txt_81cffdee" onclick="addConfidenceByKey(95, 'common.ui.confidence_demo.reason_exact')">ثقة عالية (95%)</button>
                <button data-i18n="common.ui.txt_21bd1446" onclick="addConfidenceByKey(75, 'common.ui.confidence_demo.reason_medium')">ثقة متوسطة (75%)</button>
                <button data-i18n="common.ui.txt_811f1823" onclick="addConfidenceByKey(45, 'common.ui.confidence_demo.reason_low')">ثقة منخفضة (45%)</button>
            </div>
        </div>
        
        <!-- Technical Details -->
        <div class="demo-section">
            <div class="demo-title" data-i18n="common.ui.txt_e66cfbb6">📚 التفاصيل التقنية</div>
            <ul class="tech-list">
                <li><strong>ConfidenceCalculator:</strong> [`app/Services/SmartPaste/ConfidenceCalculator.php`]</li>
                <li><strong data-i18n="common.ui.api_endpoint">API Endpoint:</strong> [`api/smart-paste-confidence.php`]</li>
                <li><strong data-i18n="common.ui.css_styles">CSS Styles:</strong> [`public/css/confidence-indicators.css`]</li>
                <li><strong data-i18n="common.ui.js_helper">JS Helper:</strong> [`public/js/confidence-ui.js`]</li>
            </ul>
            
            <div class="thresholds-wrap">
                <strong class="thresholds-title" data-i18n="common.ui.confidence_thresholds">Confidence Thresholds:</strong>
                <div class="code-block code-block-sm">
HIGH:   ≥ 90% - ✅ Auto-accept<br>
MEDIUM: ≥ 70% - ⚠️ Show with warning<br>
LOW:    < 70% - ❌ Reject
                </div>
            </div>
        </div>
    </div>
    
    <script src="../public/js/confidence-ui.js"></script>
    <script>
        function addConfidenceByKey(confidence, reasonKey) {
            const input = document.getElementById('demoInput');
            const reason = window.WBGLI18n && typeof window.WBGLI18n.t === 'function'
                ? window.WBGLI18n.t(reasonKey, reasonKey)
                : reasonKey;
            ConfidenceUI.attachToField(input, confidence, reason);
        }
    </script>
</body>
</html>
