<?php
/**
 * BGL System v3.0 - Server Driven Refactor
 * ==============================================
 * Stack: PHP 8 + Vanilla JS + CSS Variables
 * Logic: Stateless Interface (HTML is truth)
 */

header('Content-Type: text/html; charset=utf-8');

// --- Mock Data & State Handling ---
$recordId = $_GET['id'] ?? 14180;

// In a real app, these would come from Repositories
$mockRecord = [
    'id' => $recordId,
    'session_id' => 517,
    'guarantee_number' => 'BG-2024-' . substr($recordId, -5),
    'supplier_name' => 'شركة الاختبار التجريبية',
    'bank_name' => 'البنك الأهلي السعودي',
    'amount' => 500000,
    'expiry_date' => '2025-06-30',
    'issue_date' => '2024-01-15',
    'contract_number' => 'CNT-2024-001',
    'type' => 'ابتدائي',
    'status' => 'pending'
];

$mockTimeline = [
    ['id' => 7, 'type' => 'release', 'date' => '2025-01-15 11:45:00', 'description' => 'إصدار إفراج', 'details' => 'تم إصدار خطاب إفراج الضمان'],
    ['id' => 1, 'type' => 'import', 'date' => '2024-12-01 10:30:15', 'description' => 'استيراد من ملف Excel', 'details' => 'ملف: guarantees_dec_2024.xlsx']
];

$mockCandidates = [
    'suppliers' => [
        ['id' => 1, 'name' => 'شركة الاختبار التجريبية', 'confidence' => 95, 'source' => 'learned', 'usage_count' => 15],
        ['id' => 2, 'name' => 'شركة الاختبار', 'confidence' => 85, 'source' => 'excel', 'usage_count' => 3]
    ],
    'banks' => [
        ['id' => 1, 'name' => 'البنك الأهلي السعودي', 'confidence' => 95, 'source' => 'learned']
    ]
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BGL System v3.0 (Vanilla)</title>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- App Logic -->
    
    <style>
        /* CSS Variables (Keep original design system) */
        :root {
            --bg-body: #f1f5f9; --bg-card: #ffffff; --bg-secondary: #f8fafc;
            --border-primary: #e2e8f0; --accent-primary: #3b82f6; --accent-success: #16a34a;
            --text-primary: #1e293b; --text-muted: #64748b;
            --font-family: 'Tajawal', sans-serif;
            --radius-md: 8px;
        }
        
        /* Reset & Base */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: var(--font-family); background: var(--bg-body); color: var(--text-primary); display: flex; flex-direction: column; height: 100vh; overflow: hidden; }
        
        /* Layout */
        .app-container { display: flex; flex: 1; overflow: hidden; }
        .top-bar { height: 56px; background: var(--bg-card); border-bottom: 1px solid var(--border-primary); display: flex; align-items: center; justify-content: space-between; padding: 0 24px; }
        .center-section { flex: 1; display: flex; flex-direction: column; }
        .content-wrapper { display: flex; flex: 1; overflow: hidden; }
        
        /* Sidebar Components */
        .sidebar { width: 290px; background: var(--bg-card); border-right: 1px solid var(--border-primary); display: flex; flex-direction: column; }
        .timeline-panel { width: 360px; background: var(--bg-card); border-right: 1px solid var(--border-primary); overflow-y: auto; padding: 16px; }
        .main-content { flex: 1; padding: 24px; overflow-y: auto; background: var(--bg-body); }
        
        /* Components */
        .btn { padding: 8px 16px; border-radius: 6px; border: 1px solid var(--border-primary); background: white; cursor: pointer; font-family: inherit; }
        .btn-primary { background: var(--accent-primary); color: white; border: none; }
        .hidden { display: none !important; }
        
        /* Field Inputs */
        .field-group { margin-bottom: 20px; }
        .field-input { width: 100%; padding: 10px; border: 1px solid var(--border-primary); border-radius: 6px; font-family: inherit; }
        .chip { display: inline-flex; padding: 4px 12px; border-radius: 50px; border: 1px solid var(--border-primary); background: white; margin-left: 8px; cursor: pointer; font-size: 12px; }
        .chip:hover { background: #eff6ff; border-color: #93c5fd; }
        .chip-selected { background: #dcfce7; border-color: #86efac; color: var(--accent-success); }
        
        /* Info Grid */
        .info-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; background: var(--bg-secondary); padding: 16px; border-radius: 8px; }
        .info-label { font-size: 11px; color: var(--text-muted); text-transform: uppercase; }
        .info-value { font-weight: 600; }
        
        /* Timeline */
        .timeline-item { position: relative; padding-right: 20px; margin-bottom: 20px; }
        .timeline-dot { position: absolute; right: -5px; top: 5px; width: 10px; height: 10px; background: var(--text-muted); border-radius: 50%; }
        .event-card { background: white; border: 1px solid var(--border-primary); padding: 12px; border-radius: 8px; }
        
        /* Preview */
        .preview-section { margin-top: 24px; background: white; border: 1px solid var(--border-primary); border-radius: 8px; overflow: hidden; }
        .letter-paper { padding: 40px; line-height: 1.8; }
    </style>
</head>
<body>
    
    <!-- Top Bar -->
    <header class="top-bar">
        <div style="font-weight: 800; font-size: 18px;">&#x1F4CB; نظام الضمانات <span style="font-weight:400; font-size:12px; color: #666;">(Vanilla JS)</span></div>
        <nav>
            <button class="btn" data-action="next-record" data-payload='{"nextIndex": "next"}'>&#x23ED; التالي</button>
        </nav>
    </header>

    <div class="app-container">
        
        <!-- Sidebar (Links & Stats) -->
        <aside class="sidebar">
            <!-- Input Actions (Restored) -->
            <div class="input-toolbar" style="padding: 16px; border-bottom: 1px solid var(--border-primary); background: #ffffff;">
                <div class="toolbar-label" style="font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 10px; letter-spacing: 0.5px;">إدخال جديد</div>
                <div class="toolbar-actions" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px;">
                    <button class="btn-input" style="display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 6px; background: #f8fafc; border: 1px solid var(--border-primary); border-radius: 8px; padding: 10px 4px; cursor: pointer;"
                            data-action="open-modal" data-payload='{"target": "manual-input"}'>
                        <span style="font-size: 18px;">&#x270D;</span>
                        <span style="font-size: 11px; font-weight: 500;">يدوي</span>
                    </button>
                    <button class="btn-input" style="display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 6px; background: #f8fafc; border: 1px solid var(--border-primary); border-radius: 8px; padding: 10px 4px; cursor: pointer;"
                            data-action="trigger-import">
                        <span style="font-size: 18px;">&#x1F4CA;</span>
                        <span style="font-size: 11px; font-weight: 500;">ملف</span>
                    </button>`n      <input type="file" id="import-file-input" hidden accept=".csv,.xlsx,.xls">
                    <button class="btn-input" style="display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 6px; background: #f8fafc; border: 1px solid var(--border-primary); border-radius: 8px; padding: 10px 4px; cursor: pointer;"
                            data-action="open-modal" data-payload='{"target": "paste-data"}'>
                        <span style="font-size: 18px;">&#x1F4CB;</span>
                        <span style="font-size: 11px; font-weight: 500;">لصق</span>
                    </button>
                </div>
            </div>

            <div style="padding: 20px; text-align: center; color: var(--text-muted);">
                قسم الإحصائيات
                <br><br>
                (محتوى ثابت)
            </div>
        </aside>

        <!-- Center Section -->
        <div class="center-section">
            <header class="top-bar" style="background: #f8fafc;">
                <h1>ضمان رقم <?= $mockRecord['guarantee_number'] ?></h1>
                <div>
                   <span style="background:#fef3c7; color:#d97706; padding:4px 12px; border-radius:20px; font-size:12px; font-weight:bold;">يحتاج قرار</span>
                </div>
            </header>

            <div class="content-wrapper">
                
                <!-- Timeline Panel -->
                <aside class="timeline-panel">
                    <h3 style="margin-bottom: 20px; font-size: 14px; color: var(--text-muted);">سجل الأحداث</h3>
                    <?php foreach ($mockTimeline as $event): ?>
                    <div class="timeline-item">
                        <div class="timeline-dot"></div>
                        <div class="event-card">
                            <div style="font-weight: bold; font-size: 13px;"><?= $event['description'] ?></div>
                            <div style="font-size: 12px; color: var(--text-muted); margin-top: 4px;"><?= $event['details'] ?></div>
                            <div style="font-size: 10px; color: #999; margin-top: 8px; border-top: 1px solid #eee; padding-top: 4px;">
                                <?= $event['date'] ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </aside>

                <!-- Main Content -->
                <main class="main-content">
                    <div class="event-card" id="main-form"> <!-- Reused card style for main form -->
                        <div style="padding: 16px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
                            <h2 style="font-size: 16px;">بيانات الضمان</h2>
                            <button class="btn" data-action="toggle-preview">&#x1F441; معاينة الخطاب</button>
                        </div>
                        
                        <div style="padding: 24px;">
                            <!-- Supplier -->
                            <div class="field-group">
                                <label style="display:block; margin-bottom:8px; font-weight:600;">المورد</label>
                                <input type="text" id="input-supplier" class="field-input" value="<?= $mockRecord['supplier_name'] ?>">
                                <div style="margin-top: 8px;">
                                    <?php foreach ($mockCandidates['suppliers'] as $cand): ?>
                                    <button class="chip" 
                                            data-action="select-suggestion" 
                                            data-payload='<?= json_encode(['id' => $cand['id'], 'name' => $cand['name'], 'score' => $cand['confidence']]) ?>'>
                                        <?= $cand['name'] ?> (<?= $cand['confidence'] ?>%)
                                    </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Bank -->
                            <div class="field-group">
                                <label style="display:block; margin-bottom:8px; font-weight:600;">البنك</label>
                                <input type="text" id="input-bank" class="field-input" value="<?= $mockRecord['bank_name'] ?>">
                            </div>

                            <!-- Info Grid -->
                            <div class="info-grid">
                                <div>
                                    <div class="info-label">المبلغ</div>
                                    <div class="info-value" style="color: var(--accent-success); font-size: 16px;">
                                        <?= number_format($mockRecord['amount'], 2, '.', ',') ?> ر.س
                                    </div>
                                </div>
                                <div>
                                    <div class="info-label">تاريخ الانتهاء</div>
                                    <div class="info-value"><?= $mockRecord['expiry_date'] ?></div>
                                </div>
                                <div>
                                    <div class="info-label">رقم العقد</div>
                                    <div class="info-value"><?= $mockRecord['contract_number'] ?></div>
                                </div>
                                <div>
                                    <div class="info-label">النوع</div>
                                    <div class="info-value"><?= $mockRecord['type'] ?></div>
                                </div>
                            </div>

                        </div>
                        
                        <!-- Actions Footer -->
                        <div style="padding: 16px; background: #f8fafc; border-top: 1px solid #eee; display: flex; gap: 10px;">
                            <button class="btn btn-primary" 
                                    data-action="save-decision" 
                                    data-id="<?= $mockRecord['id'] ?>">
                                حفظ وقرار
                            </button>
                            <button class="btn" data-action="save-draft">حفظ مسودة</button>
                        </div>
                    </div>

                    <!-- Preview Section (Hidden by default) -->
                    <div id="preview-section" class="preview-section hidden">
                        <div style="padding: 16px; background: #f8fafc; border-bottom: 1px solid #eee;">
                            <strong>معاينة الخطاب</strong>
                        </div>
                        <div style="padding: 40px; display: flex; justify-content: center;">
                            <div class="letter-paper" style="background: white; box-shadow: 0 4px 12px rgba(0,0,0,0.1); max-width: 600px;">
                                <p>إلى: <strong><?= $mockRecord['bank_name'] ?></strong></p>
                                <br>
                                <p><strong>الموضوع: تمديد ضمان رقم <?= $mockRecord['guarantee_number'] ?></strong></p>
                                <br>
                                <p>يرجى التكرم بتمديد الضمان المذكور أعلاه والخاص بالمورد <strong><?= $mockRecord['supplier_name'] ?></strong> حتى تاريخ <strong><?= $mockRecord['expiry_date'] ?></strong>.</p>
                            </div>
                        </div>
                    </div>

                </main>
            </div>
        </div>
    </div>

<script src="../public/js/main.js"></script></body>
</html>





