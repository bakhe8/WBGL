<?php
require_once __DIR__ . '/../app/Support/autoload.php';

use App\Services\SettingsDashboardService;
use App\Support\ViewPolicy;

ViewPolicy::guardView('settings.php');

$settingsViewModel = SettingsDashboardService::buildViewModel(
    $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null
);
$currentSettings = is_array($settingsViewModel['currentSettings'] ?? null)
    ? $settingsViewModel['currentSettings']
    : [];
$pageLocale = (string)($settingsViewModel['pageLocale'] ?? 'ar');
$pageDirection = (string)($settingsViewModel['pageDirection'] ?? ($pageLocale === 'ar' ? 'rtl' : 'ltr'));
$currentDateTimeLabel = (string)($settingsViewModel['currentDateTimeLabel'] ?? date('Y-m-d H:i:s'));
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($pageLocale, ENT_QUOTES, 'UTF-8') ?>" dir="<?= htmlspecialchars($pageDirection, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-i18n="settings.page.html_title">الإعدادات - WBGL System v3.0</title>
    
    <!-- Design System CSS -->
    <link rel="stylesheet" href="../public/css/design-system.css">
    <link rel="stylesheet" href="../public/css/components.css">
    <link rel="stylesheet" href="../public/css/layout.css">
    <link rel="stylesheet" href="../public/css/a11y.css">
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* Settings Page - Unique Styles Only */
        .container { width: 100%; max-width: 100%; margin: 0 auto; padding: var(--space-lg); }
        
        /* Tabs Styling */
        .tabs {
            display: flex; 
            gap: 10px; 
            margin-bottom: 20px; 
            border-bottom: 2px solid var(--border-primary); 
            padding-bottom: 0; 
        }
        
        .tab-btn {
            padding: 12px 24px;
            background: transparent;
            border: none;
            border-bottom: 3px solid transparent;
            color: var(--text-secondary);
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 16px;
            font-family: var(--font-family);
            flex: 1;
            text-align: center;
        }
        
        .tab-btn:hover {
            color: var(--accent-primary);
            background: var(--accent-primary-light);
        }
        
        .tab-btn.active {
            color: var(--accent-primary);
            border-bottom-color: var(--accent-primary);
            background: var(--bg-card);
            border-radius: 8px 8px 0 0;
        }
        
        .tab-content { display: none; width: 100%; max-width: 100%; margin: 0 auto; }
        .tab-content.active { display: block; animation: fadeIn 0.3s ease; }
        .tab-content .card { width: 100%; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
        .btn {
            padding: 10px 20px;
            background: var(--accent-primary);
            color: white;
            border: none;
            border-radius: var(--radius-md);
            text-decoration: none;
            font-weight: 600;
            transition: all 0.2s;
            display: inline-block;
            cursor: pointer;
            font-family: var(--font-family);
            font-size: 14px;
        }
        .btn:hover { opacity: 0.9; transform: translateY(-1px); }
        .btn-secondary { background: var(--bg-secondary); color: var(--text-primary); border: 1px solid var(--border-primary); }
        .btn-success { background: var(--accent-success); }
        .btn-danger { background: var(--accent-danger); }
        .btn-primary { background: var(--accent-primary); }

        
        /* Settings-specific styles */

        /* Card & Forms */
        .card {
            background: var(--bg-card);
            border-radius: var(--radius-md);
            padding: 24px;
            box-shadow: var(--shadow-md);
            margin-bottom: 20px;
        }
        .card-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 20px;
            color: var(--text-primary);
            border-bottom: 2px solid var(--border-primary);
            padding-bottom: 12px;
        }
        .form-group { margin-bottom: 20px; }
        .form-label { display: block; font-weight: 600; margin-bottom: 8px; color: var(--text-primary); font-size: 14px; }
        .form-help { display: block; font-size: 12px; color: var(--text-muted); margin-bottom: 8px; }
        .form-input {
            width: 100%; padding: 10px 12px; border: 1px solid var(--border-primary);
            background: var(--bg-card); color: var(--text-primary);
            border-radius: var(--radius-md); font-family: var(--font-family); font-size: 14px; transition: all 0.2s;
        }
        .form-input:focus { outline: none; border-color: var(--border-focus); box-shadow: var(--shadow-focus); }
        .form-actions { display: flex; gap: 12px; margin-top: 24px; padding-top: 24px; border-top: 1px solid var(--border-primary); }
        
        /* Alerts */
        .alert { padding: 16px; border-radius: var(--radius-md); margin-bottom: 20px; font-weight: 500; }
        .alert-success { background: rgba(22, 163, 74, 0.1); color: var(--accent-success); border: 1px solid var(--accent-success); }
        .alert-error { background: rgba(220, 38, 38, 0.1); color: var(--accent-danger); border: 1px solid var(--accent-danger); }
        .alert-hidden { display: none; }

        .grid-2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; }
        @media (max-width: 768px) { .grid-2 { grid-template-columns: 1fr; } }
        
        /* Tables */
        .data-table { width: 100%; border-collapse: separate; border-spacing: 0; margin-top: 10px; border-radius: 8px; overflow: hidden; border: 1px solid var(--border-primary); }
        .data-table th, .data-table td { padding: 10px 12px; text-align: right; border-bottom: 1px solid var(--border-primary); }
        .data-table th { background: var(--bg-secondary); font-weight: 700; color: var(--text-secondary); white-space: nowrap; }
        .data-table tr:last-child td { border-bottom: none; }
        .data-table tr:hover { background: var(--bg-hover); }
        
        /* Editable Inputs */
        .row-input {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid var(--border-neutral);
            background: var(--bg-card);
            color: var(--text-primary);
            border-radius: 6px;
            font-family: inherit;
            font-size: 14px;
            transition: all 0.2s;
            box-shadow: var(--shadow-sm);
        }
        .row-input:focus {
            border-color: var(--border-focus);
            outline: none;
            box-shadow: var(--shadow-focus);
        }
        .row-input:disabled { background: var(--bg-secondary); color: var(--text-muted); }
        
        select.row-input { appearance: none; background-repeat: no-repeat; background-position: left 8px center; background-size: 16px; padding-left: 30px; }

        /* Loading State */
        .loading { position: relative; opacity: 0.6; pointer-events: none; min-height: 100px; }
        .loading::after {
            content: attr(data-loading-label);
            position: absolute; top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            background: var(--theme-overlay-backdrop); color: var(--bg-card);
            padding: 8px 16px; border-radius: 20px;
            font-size: 14px; font-weight: bold;
        }

        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }
        .modal {
            background: var(--bg-card);
            color: var(--text-primary);
            padding: 24px;
            border-radius: 12px;
            width: 100%;
            max-width: 500px;
            box-shadow: var(--shadow-lg);
        }
        .modal-header { font-size: 1.25rem; font-weight: 700; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; }
        .modal-body .form-group { margin-bottom: 12px; }
        .modal-footer { margin-top: 20px; display: flex; justify-content: flex-end; gap: 8px; }
        .close-modal { cursor: pointer; background: none; border: none; font-size: 1.5rem; }
        .prod-toggle-group { margin-top: 20px; }
        .prod-toggle-label { display: flex; align-items: center; gap: 10px; }
        .prod-toggle-checkbox { width: 20px; height: 20px; cursor: pointer; }
        .prod-toggle-note { background: var(--theme-warning-surface-soft); border: 1px solid var(--theme-warning-border); border-radius: 8px; padding: 12px; margin-top: 8px; }
        .prod-toggle-list { margin: 0; padding-right: 20px; color: var(--theme-warning-text); font-size: 13px; line-height: 1.6; }
        .section-toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
        .section-toolbar-actions { display: flex; gap: 10px; }
        .merge-warning-box { background: var(--theme-danger-surface); border: 1px solid var(--theme-danger-border); border-radius: 8px; padding: 12px; margin-bottom: 20px; font-size: 13px; }
        .merge-source-input { background: var(--bg-secondary); }
        .learning-counter-success { font-size: 24px; font-weight: bold; color: var(--accent-success); }
        .learning-counter-danger { font-size: 24px; font-weight: bold; color: var(--accent-danger); }
        .confirm-modal { max-width: 400px; }
        .confirm-message { color: var(--text-secondary); margin-bottom: 20px; }
        .alias-input-spacing { margin-bottom: 10px; }
        .add-alias-btn { margin-top: 8px; font-size: 12px; padding: 6px 12px; }
        .override-code { font-size: 12px; }
        .table-action-btn { padding: 4px 8px; font-size: 12px; }
        .table-action-btn-spaced { margin-inline-start: 5px; }
        .learning-empty { padding: 10px; color: var(--text-muted); }

        .btn-sm {
            padding: 4px 8px;
            font-size: 12px;
        }

        .btn-merge {
            background: var(--accent-primary);
            color: #fff;
        }

        .btn-merge:hover {
            background: var(--accent-primary-hover);
        }

        .pagination {
            margin: 20px 0;
            text-align: center;
            display: flex;
            justify-content: center;
            gap: 5px;
            align-items: center;
        }

        .pagination-info {
            padding: 5px 10px;
            line-height: 28px;
            color: var(--text-secondary);
        }
    </style>
</head>
<body data-i18n-namespaces="common,settings">
    
    <!-- Unified Header -->
    <?php include __DIR__ . '/../partials/unified-header.php'; ?>
    
    <div class="container">

        <!-- Alert Messages -->
        <div id="alertSuccess" class="alert alert-success alert-hidden" role="status" aria-live="polite"></div>
        <div id="alertError" class="alert alert-error alert-hidden" role="alert" aria-live="assertive"></div>

        <!-- Tabs Navigation -->
        <div class="tabs" role="tablist" aria-label="أقسام الإعدادات" data-i18n-aria-label="settings.tabs.aria_sections">
            <button id="tab-general" class="tab-btn active" role="tab" aria-selected="true" aria-controls="general" onclick="switchTab('general')" data-i18n="settings.tabs.general_full">🛠️ الإعدادات العامة</button>
            <button id="tab-banks" class="tab-btn" role="tab" aria-selected="false" aria-controls="banks" onclick="switchTab('banks')" data-i18n="settings.tabs.banks_full">🏦 البنوك</button>
            <button id="tab-suppliers" class="tab-btn" role="tab" aria-selected="false" aria-controls="suppliers" onclick="switchTab('suppliers')" data-i18n="settings.tabs.suppliers_full">📦 الموردين</button>
            <button id="tab-overrides" class="tab-btn" role="tab" aria-selected="false" aria-controls="overrides" onclick="switchTab('overrides')" data-i18n="settings.tabs.overrides_full">🎯 Overrides</button>
            <button id="tab-learning" class="tab-btn" role="tab" aria-selected="false" aria-controls="learning" onclick="switchTab('learning')" data-i18n="settings.tabs.learning_full">🧠 التعلم الآلي</button>
        </div>
        
        <!-- Tab 1: General Settings -->
        <div id="general" class="tab-content active" role="tabpanel" aria-labelledby="tab-general">
            <form id="settingsForm">
                <!-- Matching Thresholds -->
                <div class="card">
                    <h2 class="card-title" data-i18n="settings.general.matching_thresholds.title">عتبات المطابقة</h2>
                    <div class="form-group">
                        <label class="form-label" data-i18n="settings.general.matching_thresholds.auto_accept_label">عتبة القبول التلقائي</label>
                        <span class="form-help">MATCH_AUTO_THRESHOLD (>= 95)</span>
                        <input type="number" class="form-input" name="MATCH_AUTO_THRESHOLD" value="<?= $currentSettings['MATCH_AUTO_THRESHOLD'] ?>" min="0" max="100" step="1" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" data-i18n="settings.general.matching_thresholds.review_label">عتبة المراجعة</label>
                        <span class="form-help" data-i18n="settings.general.matching_thresholds.review_hint">MATCH_REVIEW_THRESHOLD (&lt; 70%)</span>
                        <input type="number" class="form-input" name="MATCH_REVIEW_THRESHOLD" value="<?= $currentSettings['MATCH_REVIEW_THRESHOLD'] ?>" min="0" max="1" step="0.01" required>
                    </div>
                </div>

                <!-- Base Scores -->
                <div class="card">
                    <h2 class="card-title" data-i18n="settings.general.base_scores.title">🎯 Base Scores (نقاط الأساس حسب نوع الإشارة)</h2>
                    <p class="form-help" style="margin-bottom: 15px;" data-i18n="settings.general.base_scores.help">هذه هي النقاط الأساسية المستخدمة فعلياً في نظام المطابقة. كل نوع إشارة له نقاط أساسية مختلفة حسب قوته.</p>
                    <div class="grid-2">
                        <div class="form-group">
                            <label class="form-label" data-i18n="settings.general.base_scores.override_exact.label">Override مطابقة صريحة</label>
                            <span class="form-help" data-i18n="settings.general.base_scores.override_exact.help">مطابقة قادمة من جدول overrides (أعلى أولوية)</span>
                            <input type="number" class="form-input" name="BASE_SCORE_OVERRIDE_EXACT" value="<?= $currentSettings['BASE_SCORE_OVERRIDE_EXACT'] ?? 100 ?>" min="0" max="100" step="1" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" data-i18n="settings.general.base_scores.alias_exact.label">مطابقة تامة (Alias Exact)</label>
                            <span class="form-help" data-i18n="settings.general.base_scores.alias_exact.help">مطابقة تامة مع اسم بديل محفوظ</span>
                            <input type="number" class="form-input" name="BASE_SCORE_ALIAS_EXACT" value="<?= $currentSettings['BASE_SCORE_ALIAS_EXACT'] ?? 100 ?>" min="0" max="100" step="1" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" data-i18n="settings.general.base_scores.anchor_unique.label">مرساة فريدة (Entity Anchor Unique)</label>
                            <span class="form-help" data-i18n="settings.general.base_scores.anchor_unique.help">مطابقة عبر كلمة فريدة مميزة</span>
                            <input type="number" class="form-input" name="BASE_SCORE_ENTITY_ANCHOR_UNIQUE" value="<?= $currentSettings['BASE_SCORE_ENTITY_ANCHOR_UNIQUE'] ?? 90 ?>" min="0" max="100" step="1" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" data-i18n="settings.general.base_scores.anchor_generic.label">مرساة عامة (Entity Anchor Generic)</label>
                            <span class="form-help" data-i18n="settings.general.base_scores.anchor_generic.help">مطابقة عبر كلمة عامة</span>
                            <input type="number" class="form-input" name="BASE_SCORE_ENTITY_ANCHOR_GENERIC" value="<?= $currentSettings['BASE_SCORE_ENTITY_ANCHOR_GENERIC'] ?? 75 ?>" min="0" max="100" step="1" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" data-i18n="settings.general.base_scores.fuzzy_strong.label">مطابقة ضبابية قوية (Fuzzy Strong)</label>
                            <span class="form-help" data-i18n="settings.general.base_scores.fuzzy_strong.help">تشابه >= 95%</span>
                            <input type="number" class="form-input" name="BASE_SCORE_FUZZY_OFFICIAL_STRONG" value="<?= $currentSettings['BASE_SCORE_FUZZY_OFFICIAL_STRONG'] ?? 85 ?>" min="0" max="100" step="1" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" data-i18n="settings.general.base_scores.fuzzy_medium.label">مطابقة ضبابية متوسطة (Fuzzy Medium)</label>
                            <span class="form-help" data-i18n="settings.general.base_scores.fuzzy_medium.help">تشابه 85-94%</span>
                            <input type="number" class="form-input" name="BASE_SCORE_FUZZY_OFFICIAL_MEDIUM" value="<?= $currentSettings['BASE_SCORE_FUZZY_OFFICIAL_MEDIUM'] ?? 70 ?>" min="0" max="100" step="1" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" data-i18n="settings.general.base_scores.fuzzy_weak.label">مطابقة ضبابية ضعيفة (Fuzzy Weak)</label>
                            <span class="form-help" data-i18n="settings.general.base_scores.fuzzy_weak.help">تشابه 75-84%</span>
                            <input type="number" class="form-input" name="BASE_SCORE_FUZZY_OFFICIAL_WEAK" value="<?= $currentSettings['BASE_SCORE_FUZZY_OFFICIAL_WEAK'] ?? 55 ?>" min="0" max="100" step="1" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" data-i18n="settings.general.base_scores.historical_frequent.label">نمط تاريخي متكرر (Historical Frequent)</label>
                            <span class="form-help" data-i18n="settings.general.base_scores.historical_frequent.help">استخدم بشكل متكرر في الماضي</span>
                            <input type="number" class="form-input" name="BASE_SCORE_HISTORICAL_FREQUENT" value="<?= $currentSettings['BASE_SCORE_HISTORICAL_FREQUENT'] ?? 60 ?>" min="0" max="100" step="1" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" data-i18n="settings.general.base_scores.historical_occasional.label">نمط تاريخي نادر (Historical Occasional)</label>
                            <span class="form-help" data-i18n="settings.general.base_scores.historical_occasional.help">استخدم بشكل نادر في الماضي</span>
                            <input type="number" class="form-input" name="BASE_SCORE_HISTORICAL_OCCASIONAL" value="<?= $currentSettings['BASE_SCORE_HISTORICAL_OCCASIONAL'] ?? 45 ?>" min="0" max="100" step="1" required>
                        </div>
                    </div>
                </div>

                <!-- Learning & Penalty Settings -->
                <div class="card">
                    <h2 class="card-title" data-i18n="settings.general.learning_penalties.title">📚 إعدادات التعلم والعقوبات</h2>
                    <div class="grid-2">
                        <div class="form-group">
                            <label class="form-label" data-i18n="settings.general.learning_penalties.rejection_penalty.label">نسبة العقوبة لكل رفض (%)</label>
                            <span class="form-help" data-i18n="settings.general.learning_penalties.rejection_penalty.help">النسبة المئوية التي تُخصم من الثقة عند كل رفض (افتراضي: 25%)</span>
                            <input type="number" class="form-input" name="REJECTION_PENALTY_PERCENTAGE" value="<?= $currentSettings['REJECTION_PENALTY_PERCENTAGE'] ?? 25 ?>" min="0" max="100" step="1" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" data-i18n="settings.general.learning_penalties.confirmation_tier1.label">تعزيز التأكيد: مستوى 1</label>
                            <span class="form-help" data-i18n="settings.general.learning_penalties.confirmation_tier1.help">نقاط إضافية عند 1-2 تأكيد (افتراضي: +5)</span>
                            <input type="number" class="form-input" name="CONFIRMATION_BOOST_TIER1" value="<?= $currentSettings['CONFIRMATION_BOOST_TIER1'] ?? 5 ?>" min="0" max="50" step="1" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" data-i18n="settings.general.learning_penalties.confirmation_tier2.label">تعزيز التأكيد: مستوى 2</label>
                            <span class="form-help" data-i18n="settings.general.learning_penalties.confirmation_tier2.help">نقاط إضافية عند 3-5 تأكيدات (افتراضي: +10)</span>
                            <input type="number" class="form-input" name="CONFIRMATION_BOOST_TIER2" value="<?= $currentSettings['CONFIRMATION_BOOST_TIER2'] ?? 10 ?>" min="0" max="50" step="1" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" data-i18n="settings.general.learning_penalties.confirmation_tier3.label">تعزيز التأكيد: مستوى 3</label>
                            <span class="form-help" data-i18n="settings.general.learning_penalties.confirmation_tier3.help">نقاط إضافية عند 6+ تأكيدات (افتراضي: +15)</span>
                            <input type="number" class="form-input" name="CONFIRMATION_BOOST_TIER3" value="<?= $currentSettings['CONFIRMATION_BOOST_TIER3'] ?? 15 ?>" min="0" max="50" step="1" required>
                        </div>
                    </div>
                </div>

                <!-- System Settings (Timezone) -->
                <div class="card">
                    <h2 class="card-title" data-i18n="settings.general.system.title">⚙️ إعدادات النظام</h2>
                    <div class="form-group">
                        <label class="form-label">
                            <span data-i18n="settings.general.system.timezone_label">المنطقة الزمنية (Timezone)</span>
                        </label>
                        <select class="form-input" name="TIMEZONE" required>
                            <option value="Asia/Riyadh" <?= ($currentSettings['TIMEZONE'] ?? 'Asia/Riyadh') === 'Asia/Riyadh' ? 'selected' : '' ?> data-i18n="settings.general.system.timezone.riyadh">🇸🇦 الرياض (Asia/Riyadh) - UTC+3</option>
                            <option value="Asia/Dubai" <?= ($currentSettings['TIMEZONE'] ?? '') === 'Asia/Dubai' ? 'selected' : '' ?> data-i18n="settings.general.system.timezone.dubai">🇦🇪 دبي (Asia/Dubai) - UTC+4</option>
                            <option value="Asia/Kuwait" <?= ($currentSettings['TIMEZONE'] ?? '') === 'Asia/Kuwait' ? 'selected' : '' ?> data-i18n="settings.general.system.timezone.kuwait">🇰🇼 الكويت (Asia/Kuwait) - UTC+3</option>
                            <option value="Asia/Qatar" <?= ($currentSettings['TIMEZONE'] ?? '') === 'Asia/Qatar' ? 'selected' : '' ?> data-i18n="settings.general.system.timezone.qatar">🇶🇦 الدوحة (Asia/Qatar) - UTC+3</option>
                            <option value="Asia/Bahrain" <?= ($currentSettings['TIMEZONE'] ?? '') === 'Asia/Bahrain' ? 'selected' : '' ?> data-i18n="settings.general.system.timezone.bahrain">🇧🇭 البحرين (Asia/Bahrain) - UTC+3</option>
                            <option value="Africa/Cairo" <?= ($currentSettings['TIMEZONE'] ?? '') === 'Africa/Cairo' ? 'selected' : '' ?> data-i18n="settings.general.system.timezone.cairo">🇪🇬 القاهرة (Africa/Cairo) - UTC+2</option>
                            <option value="UTC" <?= ($currentSettings['TIMEZONE'] ?? '') === 'UTC' ? 'selected' : '' ?> data-i18n="settings.general.system.timezone.utc">🌍 UTC - التوقيت العالمي</option>
                        </select>
                        <small class="form-help">
                            <span data-i18n="settings.general.system.current_time_label">التوقيت الحالي:</span>
                            <?= htmlspecialchars($currentDateTimeLabel, ENT_QUOTES, 'UTF-8') ?>
                        </small>
                    </div>
	
                    <!-- Production Mode Toggle -->
                    <div class="form-group prod-toggle-group">
                        <label class="form-label prod-toggle-label">
                            <input type="checkbox" name="PRODUCTION_MODE" value="1" 
                                   <?= !empty($currentSettings['PRODUCTION_MODE']) ? 'checked' : '' ?>
                                   class="prod-toggle-checkbox">
                            <span data-i18n="settings.general.system.production_mode.title">🚀 Production Mode (وضع الإنتاج) - تحذير هام</span>
                        </label>
                        <div class="prod-toggle-note">
                            <strong style="color: #c2410c; display: block; margin-bottom: 6px;" data-i18n="settings.general.system.production_mode.warning_title">⚠️ عند تفعيل هذا الوضع:</strong>
                            <ul class="prod-toggle-list">
                                <li><span data-i18n="settings.general.system.production_mode.item_hide">سيتم إخفاء جميع خيارات إنشاء بيانات الاختبار (UI).</span></li>
                                <li><span data-i18n="settings.general.system.production_mode.item_filter">سيتم فلترة جميع بيانات الاختبار من لوحات القيادة والإحصائيات والتقارير.</span></li>
                                <li><span data-i18n="settings.general.system.production_mode.item_block_api">سيتم منع إنشاء بيانات اختبار جديدة عبر الواجهة البرمجية (API).</span></li>
                                <li><span data-i18n="settings.general.system.production_mode.item_hide_tools">لن تظهر أدوات الصيانة وحذف بيانات الاختبار.</span></li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="form-actions">
                    <button type="submit" class="btn btn-success" data-i18n="settings.general.actions.save">💾 حفظ التغييرات</button>
                    <button type="button" id="resetBtn" class="btn btn-danger" data-i18n="settings.general.actions.reset">🔄 استعادة الافتراضيات</button>
                </div>
            </form>
        </div>

        <!-- Tab 2: Banks -->
        <div id="banks" class="tab-content" role="tabpanel" aria-labelledby="tab-banks" hidden>
            <div class="card">
                <div class="section-toolbar">
                    <h2 class="card-title" style="margin-bottom: 0; border-bottom: none; padding-bottom: 0;" data-i18n="settings.banks.title">إدارة البنوك</h2>
                    <div class="section-toolbar-actions">
                        <button class="btn btn-secondary" onclick="exportData('banks')" data-i18n="settings.common.export_json">⬇️ تصدير JSON</button>
                        <button class="btn btn-secondary" onclick="document.getElementById('importBanksFile').click()" data-i18n="settings.common.import_json">⬆️ استيراد JSON</button>
                        <input type="file" id="importBanksFile" hidden accept=".json" onchange="importData('banks', this)">
                        <button class="btn btn-primary" onclick="openModal('addBankModal')" data-i18n="settings.banks.actions.add_new">+ إضافة بنك جديد</button>
                    </div>
                </div>
                <div id="banksTableContainer" data-loading-label-key="settings.common.loading_spinner" data-loading-label="⏳ جاري التحميل..." data-i18n-fallback="جاري التحميل..." data-i18n="settings.common.loading_inline">جاري التحميل...</div>
            </div>
        </div>

        <!-- Tab 3: Suppliers -->
        <div id="suppliers" class="tab-content" role="tabpanel" aria-labelledby="tab-suppliers" hidden>
            <div class="card">
                <div class="section-toolbar">
                    <h2 class="card-title" style="margin-bottom: 0; border-bottom: none; padding-bottom: 0;" data-i18n="settings.suppliers.title">إدارة الموردين</h2>
                    <div class="section-toolbar-actions">
                        <button class="btn btn-secondary" onclick="exportData('suppliers')" data-i18n="settings.common.export_json">⬇️ تصدير JSON</button>
                        <button class="btn btn-secondary" onclick="document.getElementById('importSuppliersFile').click()" data-i18n="settings.common.import_json">⬆️ استيراد JSON</button>
                        <input type="file" id="importSuppliersFile" hidden accept=".json" onchange="importData('suppliers', this)">
                        <button class="btn btn-primary" onclick="openModal('addSupplierModal')" data-i18n="settings.suppliers.actions.add_new">+ إضافة مورد جديد</button>
                    </div>
                </div>
                <div id="suppliersTableContainer" data-loading-label-key="settings.common.loading_spinner" data-loading-label="⏳ جاري التحميل..." data-i18n-fallback="جاري التحميل..." data-i18n="settings.common.loading_inline">جاري التحميل...</div>
            </div>
        </div>

        <!-- Tab 4: Matching Overrides -->
        <div id="overrides" class="tab-content" role="tabpanel" aria-labelledby="tab-overrides" hidden>
            <div class="card">
                <div class="section-toolbar">
                    <h2 class="card-title" style="margin-bottom: 0; border-bottom: none; padding-bottom: 0;" data-i18n="settings.overrides.title">إدارة Matching Overrides</h2>
                    <div class="section-toolbar-actions">
                        <button class="btn btn-secondary" onclick="exportData('overrides')" data-i18n="settings.common.export_json">⬇️ تصدير JSON</button>
                        <button class="btn btn-secondary" onclick="document.getElementById('importOverridesFile').click()" data-i18n="settings.common.import_json">⬆️ استيراد JSON</button>
                        <input type="file" id="importOverridesFile" hidden accept=".json" onchange="importData('overrides', this)">
                        <button class="btn btn-primary" onclick="openModal('addOverrideModal')" data-i18n="settings.overrides.actions.add_new">+ إضافة Override</button>
                    </div>
                </div>
                <p class="form-help" style="margin-bottom: 12px;" data-i18n="settings.overrides.help">
                    أي Override نشط يعطي أولوية مطابقة حاسمة (`override_exact`) على النص الخام المطبع.
                </p>
                <div id="overridesTableContainer" data-loading-label-key="settings.common.loading_spinner" data-loading-label="⏳ جاري التحميل..." data-i18n-fallback="جاري التحميل..." data-i18n="settings.common.loading_inline">جاري التحميل...</div>
            </div>
        </div>

        <!-- Merge Supplier Modal -->
        <div id="mergeSupplierModal" class="modal-overlay" aria-hidden="true">
            <div class="modal" role="dialog" aria-modal="true" aria-labelledby="mergeSupplierModalTitle" tabindex="-1">
                <div class="modal-header">
                    <h2 id="mergeSupplierModalTitle" data-i18n="settings.merge.title">🔗 دمج مورد مكرر</h2>
                    <button type="button" class="close-modal" aria-label="إغلاق نافذة دمج المورد" data-i18n-aria-label="settings.merge.close_aria_label" onclick="closeModal('mergeSupplierModal')">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="merge-warning-box">
                        <strong style="color: #991b1b; display: block; margin-bottom: 4px;" data-i18n="settings.merge.warning_title">⚠️ تحذير:</strong>
                        <p style="color: #b91c1c; margin: 0;" data-i18n="settings.merge.warning_text">سيتم حذف المورد التالي ونقل كافة بياناته وتاريخه إلى مورد آخر. لا يمكن التراجع عن هذه الخطوة.</p>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" data-i18n="settings.merge.source_supplier_label">المورد المكرر (سيتم حذفه)</label>
                        <input type="text" id="sourceSupplierName" class="form-input merge-source-input" disabled>
                        <input type="hidden" id="sourceSupplierId">
                    </div>

                    <div class="form-group">
                        <label class="form-label" data-i18n="settings.merge.target_supplier_label">الدمج في المورد الأساسي (المعرف المستهدف)</label>
                        <input type="number" id="targetSupplierId" class="form-input" placeholder="أدخل معرف المورد (ID) الذي تريد الإبقاء عليه" data-i18n-placeholder="settings.merge.target_supplier_placeholder">
                        <small class="form-help" data-i18n="settings.merge.target_supplier_help">أدخل رقم المعرف (ID) الموجود في العمود الأول من الجدول.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" onclick="closeModal('mergeSupplierModal')" data-i18n="settings.common.cancel">إلغاء</button>
                    <button class="btn btn-success" id="confirmMergeBtn" onclick="executeMerge()" data-i18n="settings.merge.execute_action">🚀 تنفيذ الدمج والربط</button>
                </div>
            </div>
        </div>

        <!-- Tab 5: Machine Learning -->
        <div id="learning" class="tab-content" role="tabpanel" aria-labelledby="tab-learning" hidden>
            <!-- Learning Stats -->
            <div class="card">
                <h2 class="card-title" data-i18n="settings.learning.title">🧠 حالة نظام التعلم</h2>
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label" data-i18n="settings.learning.confirmations_count_label">عدد الأنماط المكتسبة (Confirmations)</label>
                        <div id="confirmsCount" class="learning-counter-success">...</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" data-i18n="settings.learning.rejections_count_label">عدد حالات الحظر/العقاب (Rejections)</label>
                        <div id="rejectsCount" class="learning-counter-danger">...</div>
                    </div>
                </div>
            </div>

            <!-- Blocked/Penalized Table -->
            <div class="card">
                <h2 class="card-title" style="color: var(--accent-danger);" data-i18n="settings.learning.rejections_table_title">🚫 قائمة العقوبات (Lowest Confidence)</h2>
                <p class="form-help" data-i18n="settings.learning.rejections_table_help">هذه القائمة تحتوي على الاقتراحات التي رفضها المستخدمون. يتم تطبيق عقوبة 33.4% لكل رفض.</p>
                <div id="rejectionsTableContainer" data-loading-label-key="settings.common.loading_spinner" data-loading-label="⏳ جاري التحميل..." data-i18n-fallback="جاري التحميل..." data-i18n="settings.common.loading_inline">جاري التحميل...</div>
            </div>

            <!-- Learned Patterns Table -->
            <div class="card">
                <h2 class="card-title" style="color: var(--accent-success);" data-i18n="settings.learning.confirmations_table_title">✅ الأنماط المؤكدة (Learned Patterns)</h2>
                <p class="form-help" data-i18n="settings.learning.confirmations_table_help">هذه الاقتراحات تم تأكيدها من قبل المستخدمين وتظهر بثقة عالية.</p>
                <div id="confirmationsTableContainer" data-loading-label-key="settings.common.loading_spinner" data-loading-label="⏳ جاري التحميل..." data-i18n-fallback="جاري التحميل..." data-i18n="settings.common.loading_inline">جاري التحميل...</div>
            </div>
        </div>
    </div>
    
    <!-- Modals (AddBank, AddSupplier, Confirm) remain unchanged -->
    <!-- Add Bank Modal -->
    <div id="addBankModal" class="modal-overlay" aria-hidden="true">
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="addBankModalTitle" tabindex="-1">
            <div class="modal-header">
                <h2 id="addBankModalTitle" data-i18n="settings.modals.bank.title">إضافة بنك جديد</h2>
                <button type="button" class="close-modal" aria-label="إغلاق نافذة إضافة بنك" data-i18n-aria-label="settings.modals.bank.close_aria_label" onclick="closeModal('addBankModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="addBankForm" onsubmit="handleAddBankSubmit(event)">
                    <div class="form-group"><label class="form-label" data-i18n="settings.modals.bank.arabic_name_label">الاسم العربي *</label><input required name="arabic_name" class="form-input"></div>
                    <div class="form-group"><label class="form-label" data-i18n="settings.modals.bank.english_name_label">الاسم الإنجليزي</label><input name="english_name" class="form-input"></div>
                    <div class="form-group"><label class="form-label" data-i18n="settings.modals.bank.short_name_label">الاسم المختصر</label><input name="short_name" class="form-input"></div>
                    
                    <!-- Aliases Section -->
                    <div class="form-group">
                        <label class="form-label" data-i18n="settings.modals.bank.aliases_label">الصيغ البديلة (اختياري)</label>
                        <small class="form-help" data-i18n="settings.modals.bank.aliases_help">أضف الصيغ التي قد تظهر في ملفات Excel (عربي، إنجليزي، اختصارات)</small>
                        <div id="aliases-container-settings">
                            <input type="text" class="form-input alias-input alias-input-spacing" placeholder='مثال: "الراجحي"' data-i18n-placeholder="settings.modals.bank.aliases_placeholder_ar">
                            <input type="text" class="form-input alias-input alias-input-spacing" placeholder='مثال: "alrajhi"' data-i18n-placeholder="settings.modals.bank.aliases_placeholder_en">
                            <input type="text" class="form-input alias-input alias-input-spacing" placeholder='مثال: "rajhi"' data-i18n-placeholder="settings.modals.bank.aliases_placeholder_short">
                        </div>
                        <button type="button" onclick="addAliasFieldSettings()" class="btn btn-secondary add-alias-btn" aria-label="إضافة حقل صيغة بديلة" data-i18n-aria-label="settings.modals.bank.add_alias_field_aria_label" data-i18n="settings.modals.bank.add_alias_field">
                            + إضافة صيغة أخرى
                        </button>
                    </div>
                    
                    <div class="form-group"><label class="form-label" data-i18n="settings.modals.bank.department_label">إدارة الضمانات</label><input name="department" class="form-input"></div>
                    <div class="form-group"><label class="form-label" data-i18n="settings.modals.bank.address_label">صندوق البريد</label><input name="address_line1" class="form-input"></div>
                    <div class="form-group"><label class="form-label" data-i18n="settings.modals.bank.email_label">البريد الإلكتروني</label><input type="email" name="contact_email" class="form-input"></div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addBankModal')" data-i18n="settings.common.cancel">إلغاء</button>
                <button type="button" class="btn btn-primary" onclick="document.getElementById('addBankForm').dispatchEvent(new Event('submit'))" data-i18n="settings.common.save">حفظ</button>
            </div>
        </div>
    </div>

    <!-- Add Supplier Modal -->
    <div id="addSupplierModal" class="modal-overlay" aria-hidden="true">
         <div class="modal" role="dialog" aria-modal="true" aria-labelledby="addSupplierModalTitle" tabindex="-1">
            <div class="modal-header">
                <h2 id="addSupplierModalTitle" data-i18n="settings.modals.supplier.title">إضافة مورد جديد</h2>
                <button type="button" class="close-modal" aria-label="إغلاق نافذة إضافة مورد" data-i18n-aria-label="settings.modals.supplier.close_aria_label" onclick="closeModal('addSupplierModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="addSupplierForm" onsubmit="handleAddSupplierSubmit(event)">
                    <div class="form-group"><label class="form-label" data-i18n="settings.modals.supplier.official_name_label">الاسم الرسمي *</label><input required name="official_name" class="form-input"></div>
                    <div class="form-group"><label class="form-label" data-i18n="settings.modals.supplier.english_name_label">الاسم الإنجليزي</label><input name="english_name" class="form-input"></div>
                    <div class="form-group">
                        <label class="form-label" data-i18n="settings.modals.supplier.status_label">الحالة</label>
                        <select name="is_confirmed" class="form-input">
                            <option value="1" data-i18n="settings.common.status_confirmed">مؤكد</option>
                            <option value="0" data-i18n="settings.common.status_unconfirmed">غير مؤكد</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addSupplierModal')" data-i18n="settings.common.cancel">إلغاء</button>
                <button type="button" class="btn btn-primary" onclick="document.getElementById('addSupplierForm').dispatchEvent(new Event('submit'))" data-i18n="settings.common.save">حفظ</button>
            </div>
        </div>
    </div>

    <!-- Add Override Modal -->
    <div id="addOverrideModal" class="modal-overlay" aria-hidden="true">
         <div class="modal" role="dialog" aria-modal="true" aria-labelledby="addOverrideModalTitle" tabindex="-1">
            <div class="modal-header">
                <h2 id="addOverrideModalTitle" data-i18n="settings.modals.override.title">إضافة Matching Override</h2>
                <button type="button" class="close-modal" aria-label="إغلاق نافذة إضافة Override" data-i18n-aria-label="settings.modals.override.close_aria_label" onclick="closeModal('addOverrideModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="addOverrideForm" onsubmit="handleAddOverrideSubmit(event)">
                    <div class="form-group">
                        <label class="form-label" data-i18n="settings.modals.override.raw_name_label">النص الخام (Raw Name) *</label>
                        <input required name="raw_name" class="form-input" placeholder="مثال: مؤسسة الكهرباء" data-i18n-placeholder="settings.modals.override.raw_name_placeholder">
                    </div>
                    <div class="form-group">
                        <label class="form-label" data-i18n="settings.modals.override.supplier_id_label">معرف المورد (Supplier ID) *</label>
                        <input required name="supplier_id" type="number" class="form-input" min="1" placeholder="123" data-i18n-placeholder="settings.modals.override.supplier_id_placeholder">
                    </div>
                    <div class="form-group">
                        <label class="form-label" data-i18n="settings.modals.override.reason_label">السبب (اختياري)</label>
                        <input name="reason" class="form-input" placeholder="سبب إضافة override" data-i18n-placeholder="settings.modals.override.reason_placeholder">
                    </div>
                    <div class="form-group">
                        <label class="form-label" data-i18n="settings.modals.override.status_label">الحالة</label>
                        <select name="is_active" class="form-input">
                            <option value="1" selected data-i18n="settings.common.active">نشط</option>
                            <option value="0" data-i18n="settings.common.inactive">معطل</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addOverrideModal')" data-i18n="settings.common.cancel">إلغاء</button>
                <button type="button" class="btn btn-primary" onclick="document.getElementById('addOverrideForm').dispatchEvent(new Event('submit'))" data-i18n="settings.common.save">حفظ</button>
            </div>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <div id="confirmModal" class="modal-overlay" aria-hidden="true">
        <div class="modal confirm-modal" role="dialog" aria-modal="true" aria-labelledby="confirmModalTitle" tabindex="-1">
            <div class="modal-header">
                <h2 id="confirmModalTitle" data-i18n="settings.modals.confirm.title">تأكيد الإجراء</h2>
                <button type="button" class="close-modal" aria-label="إغلاق نافذة التأكيد" data-i18n-aria-label="settings.modals.confirm.close_aria_label" onclick="closeModal('confirmModal')">&times;</button>
            </div>
            <div class="modal-body">
                <p id="confirmMessage" class="confirm-message" data-i18n="settings.modals.confirm.default_message">هل أنت متأكد؟</p>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('confirmModal')" data-i18n="settings.common.cancel">إلغاء</button>
                    <button id="confirmBtn" class="btn btn-danger" data-i18n="settings.modals.confirm.confirm_action">نعم، تابع</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        const form = document.getElementById('settingsForm');
        const successAlert = document.getElementById('alertSuccess');
        const errorAlert = document.getElementById('alertError');
        const resetBtn = document.getElementById('resetBtn');
        const lazyLoadedTabs = new Set();

        function t(key, fallback, params) {
            if (window.WBGLI18n && typeof window.WBGLI18n.t === 'function') {
                return window.WBGLI18n.t(key, fallback, params);
            }
            return fallback || key;
        }

        function refreshLoadingLabels() {
            document.querySelectorAll('[data-loading-label-key]').forEach((element) => {
                const key = element.getAttribute('data-loading-label-key');
                if (!key) return;
                const fallback = element.getAttribute('data-i18n-fallback') || key;
                element.setAttribute('data-loading-label', t(key, fallback));
            });
        }

        function handleAddBankSubmit(event) {
            if (event && typeof event.preventDefault === 'function') {
                event.preventDefault();
            }
            createBank();
            return false;
        }

        function handleAddSupplierSubmit(event) {
            if (event && typeof event.preventDefault === 'function') {
                event.preventDefault();
            }
            createSupplier();
            return false;
        }

        function handleAddOverrideSubmit(event) {
            if (event && typeof event.preventDefault === 'function') {
                event.preventDefault();
            }
            createOverride();
            return false;
        }
        
        function showAlert(type, message) {
            const alert = type === 'success' ? successAlert : errorAlert;
            alert.textContent = message;
            alert.classList.remove('alert-hidden');
            setTimeout(() => alert.classList.add('alert-hidden'), 5000);
        }
        
        function hideAlerts() {
            successAlert.classList.add('alert-hidden');
            errorAlert.classList.add('alert-hidden');
        }

        function escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .split("'")
                .join('&#039;');
        }

        refreshLoadingLabels();
        document.addEventListener('wbgl:language-changed', refreshLoadingLabels);
        
        // --- Modals ---
        const modalState = {
            activeId: null,
            lastFocused: null
        };

        function getFocusableElements(container) {
            return Array.from(container.querySelectorAll(
                'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
            ));
        }

        function openModal(id) {
            const modal = document.getElementById(id);
            if (!modal) return;

            modalState.lastFocused = document.activeElement;
            modalState.activeId = id;
            modal.style.display = 'flex';
            modal.setAttribute('aria-hidden', 'false');

            const dialog = modal.querySelector('[role="dialog"], .modal');
            const focusTarget = dialog || modal;
            const focusable = getFocusableElements(focusTarget);
            if (focusable.length > 0) {
                focusable[0].focus();
            } else if (typeof focusTarget.focus === 'function') {
                focusTarget.focus();
            }
        }

        function closeModal(id) {
            const modal = document.getElementById(id);
            if (!modal) return;

            modal.style.display = 'none';
            modal.setAttribute('aria-hidden', 'true');
            if (modalState.activeId === id) {
                modalState.activeId = null;
            }
            if (modalState.lastFocused && typeof modalState.lastFocused.focus === 'function') {
                modalState.lastFocused.focus();
                modalState.lastFocused = null;
            }
        }
        
        // Confirm Modal Logic
        let confirmCallback = null;
        function showConfirm(message, callback) {
            document.getElementById('confirmMessage').textContent = message;
            confirmCallback = callback;
            openModal('confirmModal');
        }
        
        document.getElementById('confirmBtn').addEventListener('click', () => {
            if (confirmCallback) confirmCallback();
            closeModal('confirmModal');
        });
        
        // Close modal on outside click
        window.onclick = function(event) {
            if (event.target.classList.contains('modal-overlay')) {
                closeModal(event.target.id);
            }
        }

        // ESC + basic focus trap for active modal
        document.addEventListener('keydown', (event) => {
            if (!modalState.activeId) return;
            const modal = document.getElementById(modalState.activeId);
            if (!modal || modal.style.display === 'none') return;

            if (event.key === 'Escape') {
                closeModal(modalState.activeId);
                return;
            }

            if (event.key !== 'Tab') return;
            const dialog = modal.querySelector('[role="dialog"], .modal');
            if (!dialog) return;
            const focusable = getFocusableElements(dialog);
            if (focusable.length === 0) return;

            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            const active = document.activeElement;
            if (event.shiftKey && active === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && active === last) {
                event.preventDefault();
                first.focus();
            }
        });

        async function createBank() {
            const form = document.getElementById('addBankForm');
            const data = Object.fromEntries(new FormData(form));
            
            // Collect aliases
            const aliasInputs = document.querySelectorAll('#aliases-container-settings .alias-input');
            const aliases = Array.from(aliasInputs)
                .map(input => input.value.trim())
                .filter(val => val !== '');
            
            // Add aliases to data
            data.aliases = aliases;
            
            try {
                const response = await fetch('../api/create-bank.php', {
                    method: 'POST', body: JSON.stringify(data), headers: {'Content-Type': 'application/json'}
                });
                const result = await response.json();
                if(result.success) {
                    showAlert('success', t('settings.js.bank.create_success'));
                    closeModal('addBankModal');
                    form.reset();
                    loadBanks(); // Refresh table
                } else throw new Error(result.error || t('settings.js.common.add_failed'));
            } catch(e) {
                showAlert('error', t('settings.js.common.create_failed') + e.message);
            }
        }
        
        function addAliasFieldSettings() {
            const container = document.getElementById('aliases-container-settings');
            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'form-input alias-input';
            input.placeholder = t('settings.js.bank.alias_placeholder_extra');
            input.style.marginBottom = '10px';
            container.appendChild(input);
        }

        async function createSupplier() {
             const form = document.getElementById('addSupplierForm');
            const data = Object.fromEntries(new FormData(form));
             data.is_confirmed = form.is_confirmed.value == '1';

            try {
                const response = await fetch('../api/create-supplier.php', {
                    method: 'POST', body: JSON.stringify(data), headers: {'Content-Type': 'application/json'}
                });
                const result = await response.json();
                if(result.success) {
                    showAlert('success', t('settings.js.supplier.create_success'));
                    closeModal('addSupplierModal');
                    form.reset();
                    loadSuppliers(); // Refresh table
                } else throw new Error(result.error || t('settings.js.common.add_failed'));
            } catch(e) {
                showAlert('error', t('settings.js.common.create_failed') + e.message);
            }
        }

        async function createOverride() {
            const form = document.getElementById('addOverrideForm');
            const data = Object.fromEntries(new FormData(form));
            data.supplier_id = parseInt(data.supplier_id || '0', 10);
            data.is_active = String(data.is_active || '1') === '1' ? 1 : 0;

            try {
                const response = await fetch('../api/matching-overrides.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                const result = await response.json();
                if (!result.success) {
                    throw new Error(result.error || t('settings.js.common.add_failed'));
                }

                showAlert('success', t('settings.js.override.create_success'));
                closeModal('addOverrideModal');
                form.reset();
                loadMatchingOverrides();
            } catch (e) {
                showAlert('error', t('settings.js.common.create_failed') + e.message);
            }
        }

        // Tab Switching Logic
        function switchTab(tabId) {
            const tabs = ['general', 'banks', 'suppliers', 'overrides', 'learning'];
            tabs.forEach((name) => {
                const panel = document.getElementById(name);
                const tabButton = document.getElementById(`tab-${name}`);
                const isActive = name === tabId;

                if (panel) {
                    panel.classList.toggle('active', isActive);
                    panel.hidden = !isActive;
                }
                if (tabButton) {
                    tabButton.classList.toggle('active', isActive);
                    tabButton.setAttribute('aria-selected', isActive ? 'true' : 'false');
                }
            });

            // Lazy load content
            if (tabId === 'banks' && !lazyLoadedTabs.has('banks')) {
                loadBanks();
                lazyLoadedTabs.add('banks');
            }
            if (tabId === 'suppliers' && !lazyLoadedTabs.has('suppliers')) {
                loadSuppliers();
                lazyLoadedTabs.add('suppliers');
            }
            if (tabId === 'overrides' && !lazyLoadedTabs.has('overrides')) {
                loadMatchingOverrides();
                lazyLoadedTabs.add('overrides');
            }
            if (tabId === 'learning') {
                loadLearningData();
            }
        }

        // Keyboard navigation for tabs (ArrowLeft/ArrowRight/Home/End)
        document.addEventListener('keydown', (event) => {
            const active = document.activeElement;
            if (!active || active.getAttribute('role') !== 'tab') return;

            const ids = ['general', 'banks', 'suppliers', 'overrides', 'learning'];
            const currentId = (active.id || '').replace('tab-', '');
            const currentIndex = ids.indexOf(currentId);
            if (currentIndex < 0) return;

            let nextIndex = currentIndex;
            if (event.key === 'ArrowRight') nextIndex = (currentIndex + 1) % ids.length;
            if (event.key === 'ArrowLeft') nextIndex = (currentIndex - 1 + ids.length) % ids.length;
            if (event.key === 'Home') nextIndex = 0;
            if (event.key === 'End') nextIndex = ids.length - 1;

            if (nextIndex !== currentIndex) {
                event.preventDefault();
                const nextId = ids[nextIndex];
                switchTab(nextId);
                const nextButton = document.getElementById(`tab-${nextId}`);
                if (nextButton) nextButton.focus();
            }
        });


        // Mock Fetch Loaders (Will implement real fetch next)
        async function loadBanks(page = 1) {
            const container = document.getElementById('banksTableContainer');
            if (container.classList.contains('loading')) return; // Prevent double fetch
            
            container.classList.add('loading');
            try {
                const lang = window.WBGLI18n && typeof window.WBGLI18n.getLanguage === 'function'
                    ? window.WBGLI18n.getLanguage()
                    : (document.documentElement.lang || 'ar');
                const res = await fetch(`../api/get_banks.php?page=${page}&lang=${encodeURIComponent(lang)}&t=${Date.now()}`);
                const html = await res.text();
                // Policy: Use outerHTML replacement
                container.outerHTML = html;
            } catch (e) {
                showAlert('error', t('settings.js.bank.load_failed') + e.message);
                container.classList.remove('loading');
            }
        }

        async function loadSuppliers(page = 1) {
            const container = document.getElementById('suppliersTableContainer');
            if (container.classList.contains('loading')) return;

            container.classList.add('loading');
            try {
                const lang = window.WBGLI18n && typeof window.WBGLI18n.getLanguage === 'function'
                    ? window.WBGLI18n.getLanguage()
                    : (document.documentElement.lang || 'ar');
                const res = await fetch(`../api/get_suppliers.php?page=${page}&lang=${encodeURIComponent(lang)}&t=${Date.now()}`);
                const html = await res.text();
                // Policy: Use outerHTML replacement
                container.outerHTML = html;
            } catch (e) {
                showAlert('error', t('settings.js.supplier.load_failed') + e.message);
                container.classList.remove('loading');
            }
        }

        async function loadMatchingOverrides() {
            const container = document.getElementById('overridesTableContainer');
            if (container.classList.contains('loading')) return;

            container.classList.add('loading');
            try {
                const res = await fetch('../api/matching-overrides.php?limit=500');
                const data = await res.json();
                if (!data.success) {
                    throw new Error(data.error || t('settings.js.override.load_failed_short'));
                }
                container.innerHTML = renderOverridesTable(data.items || []);
            } catch (e) {
                showAlert('error', t('settings.js.override.load_failed') + e.message);
                container.innerHTML = `<div class="alert alert-error">${t('settings.js.override.load_failed_short')}</div>`;
            } finally {
                container.classList.remove('loading');
            }
        }

        function renderOverridesTable(items) {
            if (!Array.isArray(items) || items.length === 0) {
                return `<div class="alert">${t('settings.js.override.empty')}</div>`;
            }

            let html = `
            <table class="data-table">
                <thead>
                    <tr>
                        <th>${t('settings.overrides.table.id')}</th>
                        <th>${t('settings.js.override.table.raw_name')}</th>
                        <th>${t('settings.js.override.table.normalized')}</th>
                        <th>${t('settings.js.override.table.supplier_id')}</th>
                        <th>${t('settings.js.override.table.supplier')}</th>
                        <th>${t('settings.js.override.table.reason')}</th>
                        <th>${t('settings.js.override.table.status')}</th>
                        <th>${t('settings.js.override.table.actions')}</th>
                    </tr>
                </thead>
                <tbody>`;

            items.forEach((item) => {
                const id = parseInt(item.id, 10) || 0;
                const isActive = String(item.is_active) === '1';
                const rawName = escapeHtml(item.raw_name);
                const normalizedName = escapeHtml(item.normalized_name);
                const supplierId = parseInt(item.supplier_id, 10) || 0;
                const supplierName = escapeHtml(item.supplier_official_name || '');
                const reason = escapeHtml(item.reason || '');

                html += `
                <tr data-override-id="${id}">
                    <td>${id}</td>
                    <td><input class="row-input" name="raw_name" value="${rawName}"></td>
                    <td><code class="override-code">${normalizedName}</code></td>
                    <td><input class="row-input" name="supplier_id" type="number" min="1" value="${supplierId}"></td>
                    <td>${supplierName}</td>
                    <td><input class="row-input" name="reason" value="${reason}"></td>
                    <td>
                        <select class="row-input" name="is_active">
                            <option value="1" ${isActive ? 'selected' : ''}>${t('settings.js.common.active')}</option>
                            <option value="0" ${!isActive ? 'selected' : ''}>${t('settings.js.common.inactive')}</option>
                        </select>
                    </td>
                    <td>
                        <button class="btn btn-sm table-action-btn table-action-btn-spaced" onclick="updateOverride(${id}, this)">${t('settings.js.common.update')}</button>
                        <button class="btn btn-sm btn-danger table-action-btn" onclick="deleteOverride(${id})">${t('settings.js.common.delete')}</button>
                    </td>
                </tr>`;
            });

            html += '</tbody></table>';
            return html;
        }
        
        async function loadLearningData() {
            const cContainer = document.getElementById('confirmationsTableContainer');
            const rContainer = document.getElementById('rejectionsTableContainer');
            
            try {
                const res = await fetch('../api/learning-data.php');
                const data = await res.json();
                
                if (data.success) {
                    // Update Stats
                    document.getElementById('confirmsCount').textContent = data.confirmations.length;
                    document.getElementById('rejectsCount').textContent = data.rejections.length;
                    
                    // Render Tables
                    cContainer.innerHTML = renderLearningTable(data.confirmations, 'confirm');
                    rContainer.innerHTML = renderLearningTable(data.rejections, 'reject');
                } else {
                    showAlert('error', t('settings.js.learning.load_failed'));
                }
            } catch (e) {
                showAlert('error', t('settings.js.common.network_error_with_reason') + e.message);
            }
        }
        
        function renderLearningTable(items, type) {
            if (items.length === 0) return `<p class="learning-empty">${t('settings.js.learning.empty')}</p>`;
            
            const actionBtnClass = type === 'confirm' ? 'btn-secondary' : 'btn-success';
            const actionBtnText = type === 'confirm'
                ? t('settings.js.learning.action_forget')
                : t('settings.js.learning.action_cancel_penalty');
            
            let html = `
            <table class="data-table">
                <thead>
                    <tr>
                        <th>${t('settings.js.learning.table.pattern')}</th>
                        <th>${t('settings.js.learning.table.supplier')}</th>
                        <th>${t('settings.js.learning.table.count')}</th>
                        <th>${t('settings.js.learning.table.updated_at')}</th>
                        <th>${t('settings.js.learning.table.actions')}</th>
                    </tr>
                </thead>
                <tbody>`;
                
            items.forEach(item => {
                html += `
                <tr>
                    <td>${item.pattern}</td>
                    <td>${item.official_name}</td>
                    <td>${item.count}</td>
                    <td>${item.updated_at}</td>
                    <td>
                        <button class="btn ${actionBtnClass} table-action-btn" onclick="deleteLearningItem(${item.id})">${actionBtnText}</button>
                    </td>
                </tr>`;
            });
            
            html += '</tbody></table>';
            return html;
        }
        
        async function deleteLearningItem(id) {
            showConfirm(t('settings.js.learning.delete_confirm'), async () => {
                try {
                    const response = await fetch('../api/learning-action.php', {
                        method: 'POST',
                        body: JSON.stringify({ id: id, action: 'delete' }),
                        headers: {'Content-Type': 'application/json'}
                    });
                     const result = await response.json();
                     if (result.success) {
                         showAlert('success', t('settings.js.common.delete_success'));
                         loadLearningData(); // Refresh
                     } else {
                         showAlert('error', t('settings.js.common.delete_failed'));
                     }
                } catch (e) {
                     showAlert('error', t('settings.js.common.network_error'));
                }
            });
        }

        /* Existing JS for Settings Form */
        
        function hideAlerts() {
            successAlert.classList.add('alert-hidden');
            errorAlert.classList.add('alert-hidden');
        }
        
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            hideAlerts();
            const formData = new FormData(form);
            const settings = {};
            
            // Collect all input values
            for (let [key, value] of formData.entries()) {
                settings[key] = isNaN(value) ? value : parseFloat(value);
            }
            
            // ✅ FIX: Explicitly handle checkboxes (they don't appear in FormData when unchecked)
            const checkboxes = form.querySelectorAll('[type="checkbox"]');
            checkboxes.forEach(checkbox => {
                // Set to 1 if checked, 0 if unchecked
                settings[checkbox.name] = checkbox.checked ? 1 : 0;
            });
            
            try {
                const response = await fetch('../api/settings.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(settings)
                });
                const data = await response.json();
                if (data.success) {
                    showAlert('success', t('settings.js.settings.save_success'));
                    // Reload page after 1.5 seconds to reflect changes
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    showAlert('error', t('settings.js.common.error_prefix') + (data.errors ? data.errors.join(', ') : data.error));
                }
            } catch (error) {
                showAlert('error', t('settings.js.common.network_error_prefix') + error.message);
            }
        });

        // --- Action Handlers ---
        
        async function updateBank(id, btn) {
            const row = btn.closest('tr');
            if (!row) return;

            const inputs = row.querySelectorAll('.row-input');
            const data = { id: id };
            
            inputs.forEach(input => {
                data[input.name] = input.value;
            });

            // Visual feedback
            const originalText = btn.innerHTML;
            btn.innerHTML = t('settings.js.common.saving');
            btn.disabled = true;

            try {
                const response = await fetch('../api/update_bank.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                
                const result = await response.json();
                
                if (result.success) {
                    btn.innerHTML = t('settings.js.common.saved');
                    btn.classList.add('btn-success');
                    setTimeout(() => {
                        btn.innerHTML = originalText;
                        btn.classList.remove('btn-success');
                        btn.disabled = false;
                    }, 2000);
                } else {
                    showAlert('error', t('settings.js.common.update_failed') + (result.error || t('settings.js.common.unknown_error')));
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                }
            } catch (e) {
                showAlert('error', t('settings.js.common.network_error'));
                console.error(e);
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        }

        async function updateSupplier(id, btn) {
             const row = btn.closest('tr');
            if (!row) return;

            const inputs = row.querySelectorAll('.row-input');
            const data = { id: id };
            
            inputs.forEach(input => {
                data[input.name] = input.value;
            });

            const originalText = btn.innerHTML;
            btn.innerHTML = t('settings.js.common.saving');
            btn.disabled = true;

            try {
                const response = await fetch('../api/update_supplier.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                
                const result = await response.json();
                
                if (result.success) {
                    btn.innerHTML = t('settings.js.common.saved');
                    btn.classList.add('btn-success');
                    setTimeout(() => {
                        btn.innerHTML = originalText;
                        btn.classList.remove('btn-success');
                        btn.disabled = false;
                    }, 2000);
                } else {
                    showAlert('error', t('settings.js.common.update_failed') + (result.error || t('settings.js.common.unknown_error')));
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                }
            } catch (e) {
                showAlert('error', t('settings.js.common.network_error'));
                console.error(e);
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        }

        async function updateOverride(id, btn) {
            const row = btn.closest('tr');
            if (!row) return;

            const rawName = row.querySelector('[name="raw_name"]')?.value?.trim() || '';
            const supplierId = parseInt(row.querySelector('[name="supplier_id"]')?.value || '0', 10);
            const reason = row.querySelector('[name="reason"]')?.value || '';
            const isActive = parseInt(row.querySelector('[name="is_active"]')?.value || '1', 10) === 1 ? 1 : 0;

            if (!rawName || !supplierId) {
                showAlert('error', t('settings.js.override.required_fields'));
                return;
            }

            const originalText = btn.innerHTML;
            btn.innerHTML = t('settings.js.common.saving');
            btn.disabled = true;

            try {
                const response = await fetch('../api/matching-overrides.php', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        id,
                        raw_name: rawName,
                        supplier_id: supplierId,
                        reason,
                        is_active: isActive
                    })
                });
                const result = await response.json();

                if (!result.success) {
                    throw new Error(result.error || t('settings.js.common.update_failed_short'));
                }

                btn.innerHTML = t('settings.js.common.saved');
                btn.classList.add('btn-success');
                setTimeout(() => {
                    btn.innerHTML = originalText;
                    btn.classList.remove('btn-success');
                    btn.disabled = false;
                }, 1500);

                loadMatchingOverrides();
            } catch (e) {
                showAlert('error', t('settings.js.override.update_failed') + e.message);
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        }

        async function deleteOverride(id) {
            showConfirm(t('settings.js.override.delete_confirm'), async () => {
                try {
                    const response = await fetch('../api/matching-overrides.php', {
                        method: 'DELETE',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id })
                    });
                    const result = await response.json();
                    if (!result.success) {
                        throw new Error(result.error || t('settings.js.common.delete_failed'));
                    }
                    showAlert('success', t('settings.js.override.delete_success'));
                    loadMatchingOverrides();
                } catch (e) {
                    showAlert('error', t('settings.js.override.delete_failed') + e.message);
                }
            });
        }
        
        async function deleteBank(id) {
            showConfirm(t('settings.js.bank.delete_confirm'), async () => {
                try {
                    const response = await fetch('../api/delete_bank.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id: id })
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        // Remove row from DOM
                        const row = document.querySelector(`tr[data-id="${id}"]`);
                        if (row) row.remove();
                        showAlert('success', t('settings.js.bank.delete_success'));
                    } else {
                        showAlert('error', t('settings.js.common.delete_failed_prefix') + (result.error || t('settings.js.common.unknown_error')));
                    }
                } catch (e) {
                    showAlert('error', t('settings.js.common.network_error'));
                    console.error(e);
                }
            });
        }
        
        async function deleteSupplier(id) {
            showConfirm(t('settings.js.supplier.delete_confirm'), async () => {
                try {
                    const response = await fetch('../api/delete_supplier.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id: id })
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        // Remove row from DOM
                        const row = document.querySelector(`tr[data-id="${id}"]`);
                        if (row) row.remove();
                        showAlert('success', t('settings.js.supplier.delete_success'));
                    } else {
                        showAlert('error', t('settings.js.common.delete_failed_prefix') + (result.error || t('settings.js.common.unknown_error')));
                    }
                } catch (e) {
                    showAlert('error', t('settings.js.common.network_error'));
                    console.error(e);
                }
            });
        }
        
        resetBtn.addEventListener('click', async () => {
            showConfirm(t('settings.js.settings.reset_confirm'), async () => {
                // Implement Reset logic or fetch
            });
        });

        // --- Export / Import ---
        function exportData(type) {
            let url = '../api/export_suppliers.php';
            if (type === 'banks') {
                url = '../api/export_banks.php';
            } else if (type === 'overrides') {
                url = '../api/export_matching_overrides.php';
            }
            window.location.href = url;
        }

        async function importData(type, input) {
            if (!input.files || input.files.length === 0) return;
            const file = input.files[0];
            const formData = new FormData();
            formData.append('file', file);
            
            let url = '../api/import_suppliers.php';
            if (type === 'banks') {
                url = '../api/import_banks.php';
            } else if (type === 'overrides') {
                url = '../api/import_matching_overrides.php';
            }
            const btn = input.previousElementSibling; // The Import button
            const originalText = btn.innerText;

            btn.innerText = t('settings.js.common.uploading');
            btn.disabled = true;

            try {
                const response = await fetch(url, {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                
                if (result.success) {
                    showAlert('success', result.message);
                    // Refresh Table
                    if (type === 'banks') {
                        loadBanks();
                    } else if (type === 'overrides') {
                        loadMatchingOverrides();
                    } else {
                        loadSuppliers();
                    }
                } else {
                    showAlert('error', t('settings.js.common.import_failed') + result.error);
                }
            } catch (e) {
                showAlert('error', t('settings.js.common.network_error_with_reason') + e.message);
            } finally {
                btn.innerText = originalText;
                btn.disabled = false;
                input.value = ''; // Reset input to allow re-upload same file
            }
        }

        // --- Merge Functions ---
        function openMergeModal(id, name) {
            document.getElementById('sourceSupplierId').value = id;
            document.getElementById('sourceSupplierName').value = name;
            document.getElementById('targetSupplierId').value = '';
            openModal('mergeSupplierModal');
        }

        async function executeMerge() {
            const sourceId = document.getElementById('sourceSupplierId').value;
            const targetId = document.getElementById('targetSupplierId').value;
            const btn = document.getElementById('confirmMergeBtn');

            if (!targetId) {
                showAlert('error', t('settings.js.merge.target_required'));
                return;
            }

            if (sourceId === targetId) {
                showAlert('error', t('settings.js.merge.same_supplier'));
                return;
            }

            btn.disabled = true;
            btn.innerHTML = t('settings.js.merge.executing');

            try {
                const response = await fetch('../api/merge-suppliers.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ source_id: sourceId, target_id: targetId })
                });
                const result = await response.json();
                
                if (result.success) {
                    showAlert('success', t('settings.js.merge.success'));
                    closeModal('mergeSupplierModal');
                    loadSuppliers(); // Refresh list
                } else {
                    showAlert('error', t('settings.js.merge.failed') + result.error);
                }
            } catch (e) {
                showAlert('error', t('settings.js.common.network_error_prefix') + e.message);
            } finally {
                btn.disabled = false;
                btn.innerHTML = t('settings.merge_supplier.execute');
            }
        }
    </script>
</body>
</html>


