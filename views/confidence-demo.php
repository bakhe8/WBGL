<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart Paste Confidence Demo - BGL3</title>
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
    </style>
</head>
<body>
    <div class="demo-container">
        <h1>🎯 Smart Paste Confidence Layer - Demo</h1>
        <p class="subtitle">عرض توضيحي لنظام تقييم الثقة في البيانات المستخرجة</p>
        
        <!-- Example 1: High Confidence -->
        <div class="demo-section">
            <div class="demo-title">مثال 1: ثقة عالية (95%) - تطابق تام</div>
            <div class="example-grid">
                <div class="field-with-confidence">
                    <div class="confidence-indicator">
                        <span class="confidence-badge confidence-high confidence-tooltip" data-reason="تطابق تام مع اسم معروف">
                            <span>✅</span>
                            <span class="confidence-percentage">95%</span>
                            <span>عالية</span>
                        </span>
                    </div>
                    <input type="text" value="شركة المقاولون العرب" readonly style="border-color: #10b981; border-width: 2px;">
                </div>
            </div>
            <div class="code-block">النص المُدخل: "ضمان بنكي من شركة المقاولون العرب"<br>نوع المطابقة: exact match<br>النتيجة: ✅ قبول تلقائي</div>
        </div>
        
        <!-- Example 2: Medium Confidence -->
        <div class="demo-section">
            <div class="demo-title">مثال 2: ثقة متوسطة (75%) - يحتاج مراجعة</div>
            <div class="example-grid">
                <div class="field-with-confidence">
                    <div class="confidence-indicator">
                        <span class="confidence-badge confidence-medium confidence-tooltip" data-reason="تشابه متوسط (88%)">
                            <span>⚠️</span>
                            <span class="confidence-percentage">75%</span>
                            <span>متوسطة</span>
                        </span>
                    </div>
                    <input type="text" value="شركة النهضه للمقاولات" readonly style="border-color: #f59e0b; border-width: 2px;">
                </div>
                <div class="confidence-warning">
                    <div class="confidence-warning-icon">⚠️</div>
                    <div>الثقة في البيانات المستخرجة متوسطة (75%). يُرجى المراجعة.</div>
                </div>
            </div>
            <div class="code-block">النص المُدخل: "ضمان من شركة النهضه"<br>نوع المطابقة: fuzzy match (88% similarity)<br>النتيجة: ⚠️ يُعرض مع تحذير</div>
        </div>
        
        <!-- Example 3: Low Confidence -->
        <div class="demo-section">
            <div class="demo-title">مثال 3: ثقة منخفضة (45%) - مرفوض</div>
            <div class="example-grid">
                <div class="field-with-confidence">
                    <div class="confidence-indicator">
                        <span class="confidence-badge confidence-low confidence-tooltip" data-reason="تشابه ضعيف (62%) + نص مشبوه">
                            <span>❌</span>
                            <span class="confidence-percentage">45%</span>
                            <span>منخفضة</span>
                        </span>
                    </div>
                    <input type="text" value="الراجحي" readonly style="border-color: #ef4444; border-width: 2px;">
                </div>
                <div class="confidence-warning">
                    <div class="confidence-warning-icon">❌</div>
                    <div>الثقة منخفضة جداً (45%). يُنصح بالإدخال اليدوي.</div>
                </div>
            </div>
            <div class="code-block">النص المُدخل: "Lorem ipsum dolor sit"<br>نوع المطابقة: fuzzy match (62% similarity)<br>النتيجة: ❌ مرفوض - gibberish text detected</div>
        </div>
        
        <!-- Interactive Demo -->
        <div class="demo-section">
            <div class="demo-title">🎮 تجربة تفاعلية</div>
            <p style="font-size: 14px; color: #6b7280; margin-bottom: 10px;">جرب إضافة مؤشر ثقة لحقل إدخال:</p>
            <input type="text" id="demoInput" placeholder="اكتب اسم مورد..." style="margin-bottom: 10px;">
            <div style="display: flex; gap: 10px;">
                <button onclick="addConfidence(95, 'تطابق تام')">ثقة عالية (95%)</button>
                <button onclick="addConfidence(75, 'تشابه متوسط')">ثقة متوسطة (75%)</button>
                <button onclick="addConfidence(45, 'تشابه ضعيف')">ثقة منخفضة (45%)</button>
            </div>
        </div>
        
        <!-- Technical Details -->
        <div class="demo-section">
            <div class="demo-title">📚 التفاصيل التقنية</div>
            <ul style="font-size: 14px; color: #374151; line-height: 1.8;">
                <li><strong>ConfidenceCalculator:</strong> [`app/Services/SmartPaste/ConfidenceCalculator.php`]</li>
                <li><strong>API Endpoint:</strong> [`api/smart-paste-confidence.php`]</li>
                <li><strong>CSS Styles:</strong> [`public/css/confidence-indicators.css`]</li>
                <li><strong>JS Helper:</strong> [`public/js/confidence-ui.js`]</li>
            </ul>
            
            <div style="margin-top: 15px;">
                <strong style="color: #1f2937;">Confidence Thresholds:</strong>
                <div class="code-block" style="font-size: 12px;">
HIGH:   ≥ 90% - ✅ Auto-accept<br>
MEDIUM: ≥ 70% - ⚠️ Show with warning<br>
LOW:    < 70% - ❌ Reject
                </div>
            </div>
        </div>
    </div>
    
    <script src="../public/js/confidence-ui.js"></script>
    <script>
        function addConfidence(confidence, reason) {
            const input = document.getElementById('demoInput');
            ConfidenceUI.attachToField(input, confidence, reason);
        }
    </script>
</body>
</html>
