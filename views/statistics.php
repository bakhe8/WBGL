<?php
// Prevent caching
header('Cache-Control:' . ' ' . implode(',', ['no-store', 'no-cache', 'must-revalidate', 'max-age=0']));
header('Cache-Control:' . ' ' . implode(',', ['post-check=0', 'pre-check=0']), false);
header('Pragma:' . ' ' . 'no-cache');

require_once __DIR__ . '/../app/Support/autoload.php';
use App\Support\AuthService;
use App\Support\Database;
use App\Support\DirectionResolver;
use App\Support\LocaleResolver;
use App\Support\Settings;
use App\Support\TestDataVisibility;
use App\Support\ViewPolicy;
use App\Services\StatisticsDashboardService;

ViewPolicy::guardView('statistics.php');

header('Content-Type:' . ' ' . implode(';', ['text/html', 'charset=utf-8']));

// Helper functions (Added span for currency symbol styling)
function formatMoney($amount, string $currencyLabel) { return number_format((float)$amount, 2) . ' <span class="text-xs text-muted">' . htmlspecialchars($currencyLabel, ENT_QUOTES, 'UTF-8') . '</span>'; }
function formatNumber($num) { return number_format((float)$num); }

$settings = Settings::getInstance();
$localeInfo = LocaleResolver::resolve(
    AuthService::getCurrentUser(),
    $settings,
    $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null
);
$statsLocaleCode = (string)($localeInfo['locale'] ?? 'ar');
$directionInfo = DirectionResolver::resolve(
    $statsLocaleCode,
    AuthService::getCurrentUser()?->preferredDirection ?? 'auto',
    (string)$settings->get('DEFAULT_DIRECTION', 'auto')
);
$statsPageDirection = (string)($directionInfo['direction'] ?? ($statsLocaleCode === 'ar' ? 'rtl' : 'ltr'));
$statsLocalePrimary = [];
$statsLocaleFallback = [];
$statsPrimaryPath = __DIR__ . '/../public/locales/' . $statsLocaleCode . '/statistics.json';
$statsFallbackPath = __DIR__ . '/../public/locales/ar/statistics.json';
if (is_file($statsPrimaryPath)) {
    $decodedLocale = json_decode((string)file_get_contents($statsPrimaryPath), true);
    if (is_array($decodedLocale)) {
        $statsLocalePrimary = $decodedLocale;
    }
}
if (is_file($statsFallbackPath)) {
    $decodedLocale = json_decode((string)file_get_contents($statsFallbackPath), true);
    if (is_array($decodedLocale)) {
        $statsLocaleFallback = $decodedLocale;
    }
}
$statsTodoArPrefix = '__' . 'TODO_AR__';
$statsTodoEnPrefix = '__' . 'TODO_EN__';
$statsIsPlaceholder = static function ($value) use ($statsTodoArPrefix, $statsTodoEnPrefix): bool {
    if (!is_string($value)) {
        return false;
    }
    $trimmed = trim($value);
    return str_starts_with($trimmed, $statsTodoArPrefix) || str_starts_with($trimmed, $statsTodoEnPrefix);
};
$statsT = static function (string $key, array $params = [], ?string $fallback = null) use ($statsLocalePrimary, $statsLocaleFallback, $statsIsPlaceholder): string {
    $value = $statsLocalePrimary[$key] ?? null;
    if (!is_string($value) || $statsIsPlaceholder($value)) {
        $value = $statsLocaleFallback[$key] ?? null;
    }
    if (!is_string($value) || $statsIsPlaceholder($value)) {
        $value = $fallback ?? $key;
    }
    foreach ($params as $token => $replacement) {
        $value = str_replace('{{' . (string)$token . '}}', (string)$replacement, $value);
    }
    return $value;
};
$statsUnknownMarker = '__UNKNOWN__';
$statsBatchPrefixMarker = 'BATCH_';
$statsAmountRangeKeyMap = [
    'RANGE_SMALL' => 'statistics.ui.amount_range.small',
    'RANGE_MEDIUM' => 'statistics.ui.amount_range.medium',
    'RANGE_LARGE' => 'statistics.ui.amount_range.large',
];
$statsWeekdayKeyMap = [
    0 => 'statistics.ui.weekday.0',
    1 => 'statistics.ui.weekday.1',
    2 => 'statistics.ui.weekday.2',
    3 => 'statistics.ui.weekday.3',
    4 => 'statistics.ui.weekday.4',
    5 => 'statistics.ui.weekday.5',
    6 => 'statistics.ui.weekday.6',
];
$statsMonthKeyMap = [
    '01' => 'statistics.ui.month.01',
    '02' => 'statistics.ui.month.02',
    '03' => 'statistics.ui.month.03',
    '04' => 'statistics.ui.month.04',
    '05' => 'statistics.ui.month.05',
    '06' => 'statistics.ui.month.06',
    '07' => 'statistics.ui.month.07',
    '08' => 'statistics.ui.month.08',
    '09' => 'statistics.ui.month.09',
    '10' => 'statistics.ui.month.10',
    '11' => 'statistics.ui.month.11',
    '12' => 'statistics.ui.month.12',
];
$statsCurrencyShort = $statsT('statistics.modal.txt_0272fe37', [], 'SAR');
$statsDateTimeFormat = implode('', ['Y-m-d', ' ', 'H:i']);

$db = Database::connect();
$statsJsonExpiryExpr = "(g.raw_data::jsonb ->> 'expiry_date')";
$statsJsonAmountExpr = "(g.raw_data::jsonb ->> 'amount')";
$statsJsonRawExpiryExpr = "(raw_data::jsonb ->> 'expiry_date')";
$statsJsonRawAmountExpr = "(raw_data::jsonb ->> 'amount')";
$statsJsonRawTypeExpr = "(raw_data::jsonb ->> 'type')";
$statsJsonRawExpiryDateExpr = "(NULLIF({$statsJsonRawExpiryExpr}, '')::date)";
$statsJsonExpiryDateExpr = "(NULLIF({$statsJsonExpiryExpr}, '')::date)";
$statsNowDateExpr = 'CURRENT_DATE';
$statsPlus30DateExpr = "(CURRENT_DATE + INTERVAL '30 days')::date";
$statsPlus90DateExpr = "(CURRENT_DATE + INTERVAL '90 days')::date";
$statsPlusYearDateExpr = "(CURRENT_DATE + INTERVAL '1 year')::date";
$statsMinus7DateExpr = "(CURRENT_DATE - INTERVAL '7 days')::date";
$statsMinus14DateExpr = "(CURRENT_DATE - INTERVAL '14 days')::date";
$statsDurationHoursExpr = "(EXTRACT(EPOCH FROM (CAST(d.decided_at AS timestamp) - CAST(g.imported_at AS timestamp))) / 3600.0)";
$statsMonthExpiryExpr = "to_char({$statsJsonRawExpiryDateExpr}, 'YYYY-MM')";

// Initialize ROI variables to prevent undefined warnings
$totalHoursSaved = 0;
$fteMonthsSaved = 0;
$costSaved = 0;

// Production Mode: Test Data Filtering Setup
$isProd = $settings->isProductionMode();
$includeTestData = TestDataVisibility::includeTestData($settings, $_GET);
$hideTestData = !$includeTestData;
// G = with alias 'g', D = direct no alias
$whereG = $hideTestData ? " WHERE g.is_test_data = 0 " : " WHERE 1=1 ";
$andG   = $hideTestData ? " AND g.is_test_data = 0 " : "";
$whereD = $hideTestData ? " WHERE is_test_data = 0 " : " WHERE 1=1 ";
$andD   = $hideTestData ? " AND is_test_data = 0 " : "";

try {
    // ============================================
    // SECTION 1: GLOBAL METRICS (ASSET vs OCCURRENCE)
    // ============================================
    $overview = StatisticsDashboardService::fetchOverview(
        $db,
        $isProd,
        $whereD,
        $andD,
        $statsJsonRawExpiryDateExpr,
        $statsNowDateExpr,
        $statsJsonRawAmountExpr
    );
    $efficiencyRatio = StatisticsDashboardService::calculateEfficiencyRatio($overview);

    // ============================================
    // SECTION 2 + 3: BATCH OPERATIONS + BANKS/SUPPLIERS
    // ============================================
    $batchAndSupplier = StatisticsDashboardService::fetchBatchAndSupplierBlocks(
        $db,
        $whereG,
        $andG,
        $statsBatchPrefixMarker,
        $statsUnknownMarker,
        $statsJsonAmountExpr
    );
    $batchStats = is_array($batchAndSupplier['batchStats'] ?? null) ? $batchAndSupplier['batchStats'] : [];
    $topRecurring = is_array($batchAndSupplier['topRecurring'] ?? null) ? $batchAndSupplier['topRecurring'] : [];
    $topSuppliers = is_array($batchAndSupplier['topSuppliers'] ?? null) ? $batchAndSupplier['topSuppliers'] : [];
    $topBanks = is_array($batchAndSupplier['topBanks'] ?? null) ? $batchAndSupplier['topBanks'] : [];
    $stableSuppliers = is_array($batchAndSupplier['stableSuppliers'] ?? null) ? $batchAndSupplier['stableSuppliers'] : [];
    $riskySuppliers = is_array($batchAndSupplier['riskySuppliers'] ?? null) ? $batchAndSupplier['riskySuppliers'] : [];
    $challengingSuppliers = is_array($batchAndSupplier['challengingSuppliers'] ?? null) ? $batchAndSupplier['challengingSuppliers'] : [];
    $uniqueCounts = is_array($batchAndSupplier['uniqueCounts'] ?? null) ? $batchAndSupplier['uniqueCounts'] : ['suppliers' => 0, 'banks' => 0];
    $bankSupplierPairs = is_array($batchAndSupplier['bankSupplierPairs'] ?? null) ? $batchAndSupplier['bankSupplierPairs'] : [];
    $exclusiveSuppliers = (int)($batchAndSupplier['exclusiveSuppliers'] ?? 0);


    // ============================================
    // SECTION 3: TIME & PERFORMANCE
    // ============================================
    $timePerformance = StatisticsDashboardService::fetchTimePerformanceBlocks(
        $db,
        $whereG,
        $andG,
        $whereD,
        $statsDurationHoursExpr,
        $statsMinus7DateExpr,
        $statsMinus14DateExpr
    );
    $timing = is_array($timePerformance['timing'] ?? null) ? $timePerformance['timing'] : ['avg_hours' => 0, 'min_hours' => 0, 'max_hours' => 0];
    $peakHour = is_array($timePerformance['peakHour'] ?? null) ? $timePerformance['peakHour'] : ['hour' => 'N/A', 'count' => 0];
    $qualityMetrics = is_array($timePerformance['qualityMetrics'] ?? null) ? $timePerformance['qualityMetrics'] : ['total' => 0, 'ftr' => 0, 'complex' => 0];

    $firstTimeRight = $qualityMetrics['total'] > 0 ? round(($qualityMetrics['ftr'] / $qualityMetrics['total']) * 100, 1) : 0;
    $complexGuarantees = $qualityMetrics['complex'];

    $busiestDay = is_array($timePerformance['busiestDay'] ?? null) ? $timePerformance['busiestDay'] : ['weekday_num' => -1, 'count' => 0];
    $busiestDayKey = $statsWeekdayKeyMap[(int)($busiestDay['weekday_num'] ?? -1)] ?? 'statistics.ui.unknown';
    $busiestDayLabel = $statsT($busiestDayKey, [], $statsT('statistics.ui.unknown', [], '-'));

    $weeklyTrend = is_array($timePerformance['weeklyTrend'] ?? null) ? $timePerformance['weeklyTrend'] : ['this_week' => 0, 'last_week' => 0];

    $trendPercent = 0;
    if (($weeklyTrend['last_week'] ?? 0) > 0) {
        $trendPercent = (($weeklyTrend['this_week'] - $weeklyTrend['last_week']) / $weeklyTrend['last_week']) * 100;
    }
    $trendDirection = ($trendPercent >= 0 ? '+' : '') . round($trendPercent, 1) . '%';


    // ============================================
    // SECTION 4: EXPIRATION & ACTIONS (RECONSTRUCTED)
    // ============================================
    $expirationAction = StatisticsDashboardService::fetchExpirationActionBlocks(
        $db,
        $whereG,
        $andG,
        $andD,
        $statsJsonRawExpiryDateExpr,
        $statsNowDateExpr,
        $statsPlus30DateExpr,
        $statsPlus90DateExpr,
        $statsPlusYearDateExpr,
        $statsMonthExpiryExpr,
        $statsMinus7DateExpr
    );
    $expiration = is_array($expirationAction['expiration'] ?? null) ? $expirationAction['expiration'] : ['next_30' => 0, 'next_90' => 0];
    $peakMonth = is_array($expirationAction['peakMonth'] ?? null) ? $expirationAction['peakMonth'] : ['month' => 'N/A', 'count' => 0];
    $expirationByMonth = is_array($expirationAction['expirationByMonth'] ?? null) ? $expirationAction['expirationByMonth'] : [];
    $actions = is_array($expirationAction['actions'] ?? null) ? $expirationAction['actions'] : ['extensions' => 0, 'reductions' => 0, 'releases' => 0, 'recent_releases' => 0];
    $multipleExtensions = (int)($expirationAction['multipleExtensions'] ?? 0);
    $extensionProbability = is_array($expirationAction['extensionProbability'] ?? null) ? $expirationAction['extensionProbability'] : [];
    $topEventTypes = is_array($expirationAction['topEventTypes'] ?? null) ? $expirationAction['topEventTypes'] : [];


    // ============================================
    // SECTION 5: AI & MACHINE LEARNING
    // ============================================
    $aiLearning = StatisticsDashboardService::fetchAiLearningBlocks(
        $db,
        $whereG,
        $andG
    );
    $aiStats = is_array($aiLearning['aiStats'] ?? null) ? $aiLearning['aiStats'] : ['total' => 0, 'ai_matches' => 0, 'manual' => 0];
    $aiMatchRate = (float)($aiLearning['aiMatchRate'] ?? 0);
    $manualIntervention = (float)($aiLearning['manualIntervention'] ?? 0);
    $automationRate = (float)($aiLearning['automationRate'] ?? 0);
    $autoMatchEvents = (int)($aiLearning['autoMatchEvents'] ?? 0);
    $mlStats = is_array($aiLearning['mlStats'] ?? null) ? $aiLearning['mlStats'] : ['confirmations' => 0, 'rejections' => 0, 'total' => 0];
    $confirmedPatterns = is_array($aiLearning['confirmedPatterns'] ?? null) ? $aiLearning['confirmedPatterns'] : [];
    $rejectedPatterns = is_array($aiLearning['rejectedPatterns'] ?? null) ? $aiLearning['rejectedPatterns'] : [];
    $confidenceDistribution = is_array($aiLearning['confidenceDistribution'] ?? null) ? $aiLearning['confidenceDistribution'] : ['high' => 0, 'medium' => 0, 'low' => 0];
    $mlAccuracy = (float)($aiLearning['mlAccuracy'] ?? 0);
    $timeSaved = (float)($aiLearning['timeSaved'] ?? 0);

    // ============================================
    // SECTION 6: FINANCIAL & TYPES
    // ============================================
    $financialType = StatisticsDashboardService::fetchFinancialTypeBlocks(
        $db,
        $whereD,
        $whereG,
        $statsJsonRawTypeExpr,
        $statsJsonAmountExpr
    );
    $typeDistribution = is_array($financialType['typeDistribution'] ?? null) ? $financialType['typeDistribution'] : [];
    $amountCorrelation = is_array($financialType['amountCorrelation'] ?? null) ? $financialType['amountCorrelation'] : [];

    // Urgent action table (was inline query in view)
    $urgentList = StatisticsDashboardService::fetchUrgentList(
        $db,
        $statsUnknownMarker,
        $statsJsonAmountExpr,
        $statsJsonExpiryExpr,
        $statsJsonExpiryDateExpr,
        $statsNowDateExpr,
        $statsPlus90DateExpr,
        $andG
    );

    // ============================================
    // SECTION 0: ROI & EXECUTIVE VALUE (NEW)
    // ============================================
    // Assumptions for ROI Calculation
    $manualEntryTime = 5; // 5 minutes to manually enter/verify a guarantee
    
    // Total automated actions (AI Decisions + Auto Matches + Recurring Processed)
    $automatedActions = ($aiStats['ai_matches'] ?? 0) + ($autoMatchEvents ?? 0);
    
    // Calculate Time Saved
    $totalMinutesSaved = $automatedActions * $manualEntryTime;
    $totalHoursSaved = round($totalMinutesSaved / 60, 1);
    
    // Full-Time Employee (FTE) Equivalent (Assuming 160h work month)
    $fteMonthsSaved = round($totalHoursSaved / 160, 1);
    
    // Cost Savings (Assuming avg hourly rate of 50 SAR)
    $hourlyRate = 50; 
    $costSaved = $totalHoursSaved * $hourlyRate;

} catch (Exception $e) {
    $errorMessage = $e->getMessage();
    error_log('STATISTICS_ERROR:' . ' ' . $errorMessage);
    // Safe defaults
    $overview = ['total' => 0, 'active' => 0, 'expired' => 0, 'this_month' => 0, 'total_amount' => 0, 'avg_amount' => 0, 'max_amount' => 0, 'min_amount' => 0, 'total_assets' => 0, 'total_occurrences' => 0, 'active_batches' => 0];
    $pending = 0; $ready = 0; $released = 0;
    $batchStats = [];
    $topRecurring = [];
    $topSuppliers = [];
    $topBanks = [];
    $stableSuppliers = [];
    $riskySuppliers = [];
    $challengingSuppliers = [];
    $uniqueCounts = ['suppliers' => 0, 'banks' => 0];
    $exclusiveSuppliers = 0;
    $timing = ['avg_hours' => 0, 'min_hours' => 0, 'max_hours' => 0];
    $peakHour = ['hour' => 'N/A', 'count' => 0];
    $qualityMetrics = ['total' => 0, 'ftr' => 0, 'complex' => 0];
    $firstTimeRight = 0;
    $complexGuarantees = 0;
    $busiestDay = ['weekday_num' => -1, 'count' => 0];
    $busiestDayLabel = $statsT('statistics.ui.unknown', [], '-');
    $weeklyTrend = ['this_week' => 0, 'last_week' => 0];
    $trendPercent = 0;
    $trendDirection = '0%';
    $expiration = ['next_30' => 0, 'next_90' => 0];
    $peakMonth = ['month' => 'N/A', 'count' => 0];
    $expirationByMonth = [];
    $actions = ['extensions' => 0, 'reductions' => 0, 'releases' => 0, 'recent_releases' => 0];
    $multipleExtensions = 0;
    $extensionProbability = [];
    $topEventTypes = [];
    $aiStats = ['total' => 0, 'ai_matches' => 0, 'manual' => 0];
    $aiMatchRate = 0;
    $manualIntervention = 0;
    $automationRate = 0;
    $autoMatchEvents = 0;
    $mlStats = ['confirmations' => 0, 'rejections' => 0, 'total' => 0];
    $confirmedPatterns = [];
    $rejectedPatterns = [];
    $confidenceDistribution = ['high' => 0, 'medium' => 0, 'low' => 0];
    $mlAccuracy = 0;
    $timeSaved = 0;
    $typeDistribution = [];
    $amountCorrelation = [];
    $urgentList = [];
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($statsLocaleCode, ENT_QUOTES, 'UTF-8') ?>" dir="<?= htmlspecialchars($statsPageDirection, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-i18n="statistics.ui.txt_867da321">الإحصائيات المتقدمة - WBGL</title>
    
    <!-- Design System CSS -->
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../public/css/design-system.css">
    <link rel="stylesheet" href="../public/css/components.css">
    <link rel="stylesheet" href="../public/css/layout.css">
    
    <style>
        .stats-container { 
            max-width: 1400px; 
            margin: 0 auto; 
            padding: var(--space-xl);
        }
        
        /* Unified Grid System */
        .grid-2, .grid-3, .grid-4, .grid-6, .grid-12 { 
            display: grid; 
            gap: 20px; /* Reduced gap slightly for compact look */
            margin-bottom: 24px;
        }
        
        .grid-2 { grid-template-columns: repeat(2, 1fr); }
        .grid-3 { grid-template-columns: repeat(3, 1fr); }
        .grid-4 { grid-template-columns: repeat(4, 1fr); }
        .grid-6 { grid-template-columns: repeat(6, 1fr); }
        .grid-12 { grid-template-columns: repeat(12, 1fr); }
        
        @media (max-width: 1200px) {
            .grid-6 { grid-template-columns: repeat(3, 1fr); }
            .grid-12 { grid-template-columns: repeat(4, 1fr); }
        }
        
        @media (max-width: 1024px) {
            .grid-4 { grid-template-columns: repeat(2, 1fr); }
        }
        
        @media (max-width: 768px) {
            .grid-2, .grid-3, .grid-4, .grid-6, .grid-12 { grid-template-columns: 1fr; }
            .stats-container { padding: var(--space-md); }
        }

        /* Monthly Trend Visuals */
        .month-stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border-primary);
            border-radius: var(--radius-md);
            padding: 10px 5px;
            position: relative;
            overflow: hidden;
            transition: all var(--transition-base);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-end;
            min-height: 80px; /* Reduced height */
        }
        .month-stat-card:hover {
            transform: translateY(-3px);
            border-color: var(--accent-primary);
            box-shadow: var(--shadow-md);
        }
        .month-stat-bar {
            width: 100%;
            background: var(--bg-secondary);
            border-radius: 4px;
            position: absolute;
            bottom: 0;
            left: 0;
            z-index: 1;
            transition: height 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .month-stat-content {
            position: relative;
            z-index: 2;
            text-align: center;
        }
        .month-stat-card.is-peak {
            border-color: #fee2e2;
            background: #fffafa;
        }
        .is-peak .month-stat-bar {
            background: linear-gradient(to top, #fee2e2, #fecaca);
        }

        /* Metric Mini Card (Restored) */
        .metric-mini-card {
            background: var(--bg-card);
            border: 1px solid var(--border-primary);
            border-radius: var(--radius-md);
            padding: var(--space-md);
            text-align: center;
            box-shadow: var(--shadow-sm);
        }
        /* Executive ROI Card - Premium Look */
        .roi-card {
            background: linear-gradient(135deg, var(--bg-card) 0%, #f8fafc 100%);
            border: 1px solid var(--border-primary);
            border-top: 5px solid var(--accent-primary);
            border-radius: var(--radius-lg);
            padding: var(--space-xl);
            margin-bottom: var(--space-2xl);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: var(--space-lg);
            position: relative;
            overflow: hidden;
        }
        
        .roi-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 150px;
            height: 150px;
            background: linear-gradient(135deg, transparent 50%, rgba(99, 102, 241, 0.05) 50%);
            pointer-events: none;
        }

        .roi-metric {
            text-align: center;
            position: relative;
            z-index: 1;
        }
        .roi-metric:not(:last-child) { border-left: 1px solid var(--border-light); }
        .roi-value {
            font-size: 32px;
            font-weight: 800;
            color: var(--text-primary);
            margin-bottom: 4px;
            display: block;
        }
        .roi-label {
            font-size: 14px;
            color: var(--text-secondary);
            font-weight: 600;
        }
        .roi-sub {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 4px;
        }
        
        @media (max-width: 768px) {
            .roi-card { grid-template-columns: repeat(2, 1fr); gap: 16px; padding: 16px; }
            .roi-metric:not(:last-child) { border-left: none; }
        }

        /* Interactive Stats Linking */
        .stat-link {
            text-decoration: none;
            color: inherit;
            display: block;
            transition: all var(--transition-base);
            cursor: pointer;
        }
        .stat-link:hover {
            transform: translateY(-2px);
        }
        .stat-link:hover .metric-mini-card,
        .stat-link:hover .card {
            border-color: var(--accent-primary) !important;
            background: var(--bg-hover) !important;
            box-shadow: var(--shadow-md);
        }
        .stat-link:active {
            transform: translateY(0);
        }

        .roi-value-subtle {
            font-size: 18px;
        }

        .stats-top-border-primary { border-top: 4px solid var(--accent-primary); }
        .stats-top-border-info { border-top: 4px solid var(--accent-info); }
        .stats-top-border-success { border-top: 4px solid var(--accent-success); }
        .stats-top-border-warning { border-top: 4px solid var(--accent-warning); }
        .stats-right-border-danger { border-right: 5px solid var(--accent-danger); }
        .stats-top-border-danger-soft { border-top: 3px solid var(--accent-danger); border-radius: 8px; }
        .stats-top-border-warning-soft { border-top: 3px solid var(--accent-warning); border-radius: 8px; }

        .stats-grid-priority {
            grid-template-columns: 350px 1fr;
        }

        .stats-tbody-borderless { border: none; }
        .stats-row-clickable { cursor: pointer; }
        .stats-truncate-150 { max-width: 150px; }

        .icon-14 { width: 14px; }
        .icon-18 { width: 18px; }
        .icon-20 { width: 20px; }
        .icon-24 { width: 24px; }

        .stats-month-load-bar {
            width: var(--bar-width, 0%);
        }

        .stats-month-card {
            padding: 8px 4px;
            min-height: 70px;
        }

        .stats-month-column {
            height: var(--bar-height, 0%);
        }

        .stats-month-label {
            font-size: 9px;
            line-height: 1;
        }

        .stats-month-value {
            font-size: 14px;
        }
    </style>
</head>
<body data-i18n-namespaces="common,statistics,messages">
    
    <!-- Unified Header -->
    <?php include __DIR__ . '/../partials/unified-header.php'; ?>
    
    <div class="stats-container">
        
        <!-- Header -->
        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-2xl font-bold text-primary mb-1" data-i18n="statistics.ui.txt_e39e24e5">لوحة القيادة والتحليل</h1>
                <p class="text-secondary text-sm" data-i18n="statistics.ui.txt_f32be962">نظرة شمولية على الضمانات، الدفعات، والكفاءة التشغيلية</p>
            </div>
            <div class="flex gap-2">
                <span class="badge badge-neutral-light"><?= date('Y-m-d') ?></span>
            </div>
        </div>

        <!-- ============================================ -->
        <!-- EXECUTIVE ROI DASHBOARD (NEW) -->
        <!-- ============================================ -->
        <div class="roi-card">
            <div class="roi-metric">
                <div class="roi-value text-primary"><?= $totalHoursSaved ?>h</div>
                <div class="roi-label" data-i18n="statistics.ui.txt_4aba8222">وقت تم توفيره</div>
                <div class="roi-sub" data-i18n="statistics.ui.txt_bdbd0d46">مقارنة بالعمل اليدوي</div>
            </div>
            <div class="roi-metric">
                <?php if ($fteMonthsSaved > 0): ?>
                    <div class="roi-value text-success"><?= $fteMonthsSaved ?><span class="text-sm font-normal text-muted" data-i18n="statistics.ui.txt_492a5598"> شهر</span></div>
                <?php else: ?>
                    <div class="roi-value text-secondary roi-value-subtle" data-i18n="statistics.ui.txt_352757ca">أقل من شهر</div>
                <?php endif; ?>
                <div class="roi-label" data-i18n="statistics.ui.txt_572bb0a6">إنتاجية موظف (FTE)</div>
                <div class="roi-sub" data-i18n="statistics.ui.txt_ed8ac0dc">بناءً على 160 ساعة/شهر</div>
            </div>
            <div class="roi-metric">
                <div class="roi-value text-info"><?= $automationRate ?>%</div>
                <div class="roi-label" data-i18n="statistics.ui.txt_99999798">نسبة الأتمتة</div>
                <div class="roi-sub"><span data-i18n="statistics.ui.txt_973e0cb4">تدخل يدوي محدود (</span><?= $manualIntervention ?>%)</div>
            </div>
            <div class="roi-metric">
                <div class="roi-value text-warning"><?= formatNumber($costSaved) ?><span class="text-sm font-normal text-muted" data-i18n="statistics.modal.txt_0272fe37"> ر.س</span></div>
                <div class="roi-label" data-i18n="statistics.ui.txt_ccc582ec">قيمة تشغيلية موفرة</div>
                <div class="roi-sub" data-i18n="statistics.ui.txt_2fc128db">تقديري (50 ر.س/ساعة)</div>
            </div>
        </div>

        <!-- 1. Key Metrics Cards -->
        <div class="grid-4 mb-6">
            <div class="card p-4 flex flex-col justify-between stats-top-border-primary">
                <div class="text-secondary text-sm mb-2 font-bold" data-i18n="statistics.ui.txt_db662a2e">الأصول الفريدة</div>
                <div class="flex justify-between items-end">
                    <span class="text-3xl font-bold text-primary"><?= formatNumber($overview['total_assets']) ?></span>
                    <i data-lucide="box" class="text-light icon-24"></i>
                </div>
            </div>
            <div class="card p-4 flex flex-col justify-between stats-top-border-info">
                <div class="text-secondary text-sm mb-2 font-bold" data-i18n="statistics.ui.txt_2b086353">سجلات الظهور</div>
                <div class="flex justify-between items-end">
                    <span class="text-3xl font-bold text-info"><?= formatNumber($overview['total_occurrences']) ?></span>
                    <i data-lucide="layers" class="text-light icon-24"></i>
                </div>
            </div>
            <div class="card p-4 flex flex-col justify-between stats-top-border-success">
                <div class="text-secondary text-sm mb-2 font-bold" data-i18n="statistics.ui.txt_d6c18945">معدل الكفاءة</div>
                <div class="flex justify-between items-end">
                    <span class="text-3xl font-bold text-success"><?= $efficiencyRatio ?>x</span>
                    <i data-lucide="trending-up" class="text-light icon-24"></i>
                </div>
            </div>
            <div class="card p-4 flex flex-col justify-between stats-top-border-warning">
                <div class="text-secondary text-sm mb-2 font-bold" data-i18n="statistics.ui.txt_a1df67d6">الدفعات النشطة</div>
                <div class="flex justify-between items-end">
                    <span class="text-3xl font-bold text-warning"><?= formatNumber($overview['active_batches']) ?></span>
                    <i data-lucide="activity" class="text-light icon-24"></i>
                </div>
            </div>
        </div>

        <!-- ============================================ -->
        <!-- PRIMARY ACTION CENTER (NEW & INTERACTIVE) -->
        <!-- ============================================ -->
        <div class="card mb-6 stats-right-border-danger">
            <div class="card-header border-bottom flex-between bg-light">
                <h3 class="card-title text-lg flex items-center gap-2">
                    <i data-lucide="bell-ring" class="text-danger icon-20"></i>
                    مركز الإجراءات العاجلة
                </h3>
                <span class="text-xs text-muted" data-i18n="statistics.ui.txt_85eb27d6">انقر على الأرقام للتصفية الفورية</span>
            </div>
            <div class="card-body">
                <div class="grid-2 gap-6 stats-grid-priority">
                    <div class="flex flex-col gap-3">
                        <a href="../index.php?filter=expiring_30" class="stat-link">
                            <div class="card p-4 bg-light border-hover stats-top-border-danger-soft">
                                <div class="flex justify-between items-center">
                                    <div>
                                        <div class="text-xs text-muted mb-1" data-i18n="statistics.ui.txt_87d5e976">تنتهي خلال 30 يوم</div>
                                        <div class="text-2xl font-bold text-danger"><?= formatNumber($expiration['next_30']) ?></div>
                                    </div>
                                    <i data-lucide="chevron-left" class="text-muted"></i>
                                </div>
                            </div>
                        </a>
                        <a href="../index.php?filter=expiring_90" class="stat-link">
                            <div class="card p-4 bg-light border-hover stats-top-border-warning-soft">
                                <div class="flex justify-between items-center">
                                    <div>
                                        <div class="text-xs text-muted mb-1" data-i18n="statistics.ui.txt_9f19f345">تنتهي خلال 90 يوم</div>
                                        <div class="text-2xl font-bold text-warning"><?= formatNumber($expiration['next_90']) ?></div>
                                    </div>
                                    <i data-lucide="chevron-left" class="text-muted"></i>
                                </div>
                            </div>
                        </a>
                    </div>

                    <!-- Urgent Table -->
                    <div class="bg-white border rounded overflow-hidden">
                        <div class="p-3 bg-secondary-light text-xs font-bold border-bottom flex justify-between">
                            <span data-i18n="statistics.ui.txt_f519c908">أعلى 5 مبالغ قريبة الانتهاء</span>
                            <span class="text-muted font-normal" data-i18n="statistics.ui.txt_c3fdfe6a">تنبيه عالي الأهمية</span>
                        </div>
                        <table class="table table-sm text-xs">
                            <tbody class="stats-tbody-borderless">
                                <?php if (empty($urgentList)): ?>
                                    <tr><td colspan="3" class="text-center text-muted py-4" data-i18n="statistics.ui.txt_b1767f41">لا توجد ضمانات عاجلة حالياً تنتهي خلال 90 يوم</td></tr>
                                <?php else: ?>
                                    <?php foreach ($urgentList as $u): ?>
                                    <?php $urgentSupplier = ($u['supplier'] ?? '') === $statsUnknownMarker ? $statsT('statistics.ui.unknown') : (string)($u['supplier'] ?? ''); ?>
                                    <tr onclick="window.location='../index.php?id=<?= (int)$u['id'] ?>&search=<?= rawurlencode((string)($u['guarantee_number'] ?? '')) ?>'" class="hover-bg-light stats-row-clickable">
                                        <td class="font-bold text-primary"><?= $u['guarantee_number'] ?></td>
                                        <td><?= htmlspecialchars($urgentSupplier) ?></td>
                                        <td class="text-danger font-bold text-left"><?= number_format($u['amount']) ?> <span data-i18n="statistics.modal.txt_0272fe37">ر.س</span></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. Batch Operations (Wide Table) -->
        <div class="card mb-6">
            <div class="card-header border-bottom">
                <h3 class="card-title text-lg flex items-center gap-2">
                    <i data-lucide="history" class="icon-18"></i>
                    تحليل أداء الدفعات الأخيرة
                </h3>
            </div>
            <div class="card-body p-0">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th data-i18n="statistics.ui.txt_01d3b689">الدفعة</th>
                            <th data-i18n="statistics.ui.txt_ea30108c">تاريخ الاستيراد</th>
                            <th class="text-center" data-i18n="statistics.ui.txt_ffc03537">إجمالي الأسطر</th>
                            <th class="text-center" data-i18n="statistics.ui.txt_89465c43">جديد</th>
                            <th class="text-center" data-i18n="statistics.ui.txt_000838e1">تكرار</th>
                            <th class="text-center" data-i18n="statistics.ui.txt_1cafd4c0">نسبة التكرار</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($batchStats as $batch): 
                            $recurRate = $batch['total_rows'] > 0 
                                ? round(($batch['recurring_items'] / $batch['total_rows']) * 100, 1) 
                                : 0;
                            $batchName = (string)($batch['batch_name'] ?? '');
                            if (str_starts_with($batchName, $statsBatchPrefixMarker)) {
                                $batchName = $statsT('statistics.ui.batch_prefix', [], 'Batch') . ' ' . substr($batchName, strlen($statsBatchPrefixMarker));
                            }
                        ?>
                        <tr>
                            <td class="font-bold text-primary"><?= htmlspecialchars($batchName) ?></td>
                            <td class="text-secondary text-sm"><?= date($statsDateTimeFormat, strtotime((string)$batch['import_date'])) ?></td>
                            <td class="text-center font-bold"><?= formatNumber($batch['total_rows']) ?></td>
                            <td class="text-center"><span class="badge badge-success-light">+<?= formatNumber($batch['new_items']) ?></span></td>
                            <td class="text-center"><span class="badge badge-info-light">↻ <?= formatNumber($batch['recurring_items']) ?></span></td>
                            <td class="text-center">
                                <span class="text-sm font-bold <?= $recurRate > 50 ? 'text-info' : 'text-secondary' ?>"><?= $recurRate ?>%</span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 3. Top Lists Grid (Banks & Suppliers) -->
        <div class="grid-2 mb-6">
            <!-- Top Recurring Assets -->
            <div class="card">
                <div class="card-header border-bottom">
                    <h3 class="card-title text-base" data-i18n="statistics.ui.txt_11b06774">الأصول الأكثر نشاطاً</h3>
                </div>
                <div class="card-body p-0">
                     <table class="table">
                        <thead><tr><th data-i18n="statistics.ui.table.guarantee_number">رقم الضمان</th><th data-i18n="statistics.ui.table.supplier">المورد</th><th class="text-center" data-i18n="statistics.ui.txt_ea30108c">الظهور</th></tr></thead>
                        <tbody>
                            <?php foreach ($topRecurring as $item): ?>
                            <?php $topRecurringSupplier = ($item['supplier'] ?? '') === $statsUnknownMarker ? $statsT('statistics.ui.unknown') : (string)($item['supplier'] ?? ''); ?>
                            <tr>
                                <td class="font-mono text-primary font-bold dir-ltr text-right"><?= htmlspecialchars($item['guarantee_number']) ?></td>
                                <td class="text-sm text-truncate stats-truncate-150"><?= htmlspecialchars($topRecurringSupplier) ?></td>
                                <td class="text-center font-bold"><?= $item['occurrence_count'] ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Top Suppliers -->
            <div class="card">
                <div class="card-header border-bottom">
                    <h3 class="card-title text-base" data-i18n="statistics.ui.txt_00189cf3">أهم الموردين</h3>
                </div>
                <div class="card-body p-0">
                    <table class="table">
                        <thead><tr><th data-i18n="statistics.ui.table.supplier">المورد</th><th class="text-center" data-i18n="statistics.ui.txt_3af03abc">عدد الضمانات</th></tr></thead>
                        <tbody>
                            <?php foreach (array_slice($topSuppliers, 0, 5) as $supplier): ?>
                            <tr>
                                <td class="text-sm"><?= htmlspecialchars($supplier['official_name']) ?></td>
                                <td class="text-center font-bold"><?= formatNumber($supplier['count']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- 4. Bank Performance (Full Width) -->
        <div class="card mb-6">
            <div class="card-header border-bottom">
                <h3 class="card-title text-lg" data-i18n="statistics.ui.txt_201f020c">الأداء المالي للبنوك</h3>
            </div>
            <div class="card-body p-0">
                <table class="table">
                    <thead>
                        <tr>
                            <th data-i18n="statistics.ui.table.bank">البنك</th>
                            <th class="text-center" data-i18n="statistics.ui.txt_a82c1974">العدد</th>
                            <th class="text-center" data-i18n="statistics.ui.txt_f111102c">إجمالي المبالغ</th>
                            <th class="text-center" data-i18n="statistics.ui.txt_87ce526e">التمديدات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($topBanks, 0, 8) as $bank): ?>
                        <tr>
                            <td class="font-bold"><?= htmlspecialchars($bank['bank_name']) ?></td>
                            <td class="text-center font-bold"><?= formatNumber($bank['count']) ?></td>
                            <td class="text-center dir-ltr"><?= formatMoney($bank['total_amount'], $statsCurrencyShort) ?></td>
                            <td class="text-center">
                                <?php if ($bank['extensions'] > 0): ?>
                                <span class="badge badge-warning-light"><?= $bank['extensions'] ?></span>
                                <?php else: ?>
                                <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 5. Advanced Analysis (Grid 3) -->
        <div class="grid-3 mb-6">
            <!-- Stable Suppliers -->
            <div class="card">
                <div class="card-header border-bottom bg-success-light bg-opacity-10">
                    <h3 class="card-title text-base text-success" data-i18n="statistics.ui.txt_1cd203a0">الأكثر استقراراً</h3>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm">
                        <tbody>
                            <?php foreach ($stableSuppliers as $s): ?>
                            <tr>
                                <td class="text-sm"><?= htmlspecialchars($s['official_name']) ?></td>
                                <td class="text-center font-bold text-success"><?= $s['count'] ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Risky Suppliers -->
            <div class="card">
                <div class="card-header border-bottom bg-danger-light bg-opacity-10">
                    <h3 class="card-title text-base text-danger" data-i18n="statistics.ui.txt_e4b16f00">الأكثر مخاطرة</h3>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm">
                        <tbody>
                            <?php foreach (array_slice($riskySuppliers, 0, 5) as $s): ?>
                            <tr>
                                <td class="text-sm"><?= htmlspecialchars($s['official_name']) ?></td>
                                <td class="text-center font-bold text-danger"><?= $s['risk_score'] ?>%</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Challenging Suppliers -->
            <div class="card">
                <div class="card-header border-bottom bg-warning-light bg-opacity-10">
                    <h3 class="card-title text-base text-warning" data-i18n="statistics.ui.txt_e1419cda">تحديات المطابقة</h3>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm">
                        <tbody>
                            <?php foreach ($challengingSuppliers as $s): ?>
                            <tr>
                                <td class="text-sm"><?= htmlspecialchars($s['official_name']) ?></td>
                                <td class="text-center font-bold text-warning"><?= $s['manual_count'] ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>


        <!-- ============================================ -->
        <!-- SECTION: RELATIONSHIPS & NETWORK -->
        <!-- ============================================ -->
        <div class="grid-3 mb-4">
            <div class="metric-mini-card">
                <div class="text-2xl font-bold text-primary mb-1"><?= formatNumber($uniqueCounts['suppliers']) ?></div>
                <div class="text-xs text-muted" data-i18n="statistics.ui.txt_cebe9795">موردين فريدين</div>
            </div>
            <div class="metric-mini-card">
                <div class="text-2xl font-bold text-primary mb-1"><?= formatNumber($uniqueCounts['banks']) ?></div>
                <div class="text-xs text-muted" data-i18n="statistics.ui.txt_82e7dd40">بنوك فريدة</div>
            </div>
            <div class="metric-mini-card">
                <div class="text-2xl font-bold text-info mb-1"><?= formatNumber($exclusiveSuppliers) ?></div>
                <div class="text-xs text-muted" data-i18n="statistics.ui.txt_0bea7126">موردين حصريين (بنك واحد)</div>
            </div>
        </div>
        
        <div class="card mb-6">
            <div class="card-header border-bottom">
                <h3 class="card-title text-base" data-i18n="statistics.ui.txt_3b4f0335">أقوى التحالفات (بنك-مورد)</h3>
            </div>
            <div class="card-body p-0">
                <table class="table">
                    <thead><tr><th data-i18n="statistics.ui.table.supplier">المورد</th><th class="text-center" data-i18n="statistics.ui.txt_299b35a3">العلاقة</th><th data-i18n="statistics.ui.table.bank">البنك</th><th class="text-center" data-i18n="statistics.ui.txt_3af03abc">عدد الضمانات</th></tr></thead>
                    <tbody>
                        <?php foreach ($bankSupplierPairs as $pair): ?>
                        <tr>
                            <td class="text-sm"><?= htmlspecialchars($pair['supplier']) ?></td>
                            <td class="text-center text-muted"><i data-lucide="arrow-left-right" class="icon-14"></i></td>
                            <td class="text-sm"><?= htmlspecialchars($pair['bank']) ?></td>
                            <td class="text-center font-bold"><?= $pair['count'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 6. Quality & Timing -->
        <div class="grid-2 mb-6">
            <!-- Quality Metrics -->
            <div class="card">
                <div class="card-header border-bottom">
                    <h3 class="card-title text-base" data-i18n="statistics.ui.txt_0a0e4ca6">مؤشرات الجودة</h3>
                </div>
                <div class="card-body">
                    <div class="flex items-center justify-between mb-4 pb-4 border-bottom border-light">
                        <span class="text-secondary" data-i18n="statistics.ui.first_time_right">First Time Right</span>
                        <span class="text-2xl font-bold text-success"><?= $firstTimeRight ?>%</span>
                    </div>
                    <div class="flex items-center justify-between mb-4 pb-4 border-bottom border-light">
                        <span class="text-secondary" data-i18n="statistics.ui.txt_94d5de07">التدخل اليدوي</span>
                        <span class="text-2xl font-bold <?= $manualIntervention > 20 ? 'text-danger' : 'text-primary' ?>"><?= $manualIntervention ?>%</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-secondary" data-i18n="statistics.ui.txt_97be717b">متوسط وقت المعالجة</span>
                        <span class="text-2xl font-bold text-primary"><?= round($timing['avg_hours'] ?? 0, 1) ?>h</span>
                    </div>
                </div>
            </div>

            <!-- Expiry Analysis (Redesigned) -->
            <div class="card">
                <div class="card-header border-bottom">
                    <h3 class="card-title text-base" data-i18n="statistics.ui.txt_b5f93ff7">تحليل الانتهاءات القادمة</h3>
                </div>
                <div class="card-body">
                    <div class="grid-2 gap-4 mb-4">
                        <div class="p-3 bg-danger-light bg-opacity-10 rounded text-center border border-danger-light">
                            <div class="text-2xl font-bold text-danger mb-1"><?= formatNumber($expiration['next_30']) ?></div>
                            <div class="text-xs font-bold text-danger" data-i18n="statistics.ui.txt_7b5a2556">عاجل (30 يوم)</div>
                        </div>
                        <div class="p-3 bg-warning-light bg-opacity-10 rounded text-center border border-warning-light">
                            <div class="text-2xl font-bold text-warning mb-1"><?= formatNumber($expiration['next_90']) ?></div>
                            <div class="text-xs font-bold text-warning" data-i18n="statistics.ui.txt_c6856c99">قريب (90 يوم)</div>
                        </div>
                    </div>
                    
                    <?php if (!empty($expirationByMonth)): ?>
                    <div>
                        <h4 class="text-xs font-bold text-secondary mb-3 border-bottom pb-2" data-i18n="statistics.ui.txt_a4a1e3ce">توقعات الأشهر القادمة (أشهر الذروة)</h4>
                        <div class="space-y-3">
                            <?php foreach (array_slice($expirationByMonth, 0, 3) as $month): ?>
                            <div class="flex items-center text-sm">
                                <div class="w-24 font-mono text-secondary text-xs"><?= $month['month'] ?></div>
                                <div class="flex-1 h-3 bg-gray-100 rounded-full overflow-hidden mx-2">
                                    <div class="h-full bg-primary stats-month-load-bar" data-bar-width="<?= min(($month['count'] / ($peakMonth['count'] ?: 1)) * 100, 100) ?>"></div>
                                </div>
                                <span class="badge badge-neutral-light"><?= $month['count'] ?> <span data-i18n="statistics.ui.table.guarantee">ضمان</span></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>


        <!-- ============================================ -->
        <!-- SECTION 7: AI & AUTOMATION PERFORMANCE -->
        <!-- ============================================ -->
        <div class="card mb-6">
            <div class="card-header border-bottom flex-between">
                <h3 class="card-title text-lg" data-i18n="statistics.ui.txt_d8a6505d">أداء الذكاء الاصطناعي والأتمتة</h3>
                <span class="badge badge-primary-light"><span data-i18n="statistics.ui.ai_confidence">AI Confidence:</span> <?= $mlAccuracy ?>%</span>
            </div>
            <div class="card-body">
                <div class="grid-4 gap-4 mb-6">
                    <div class="text-center p-3 border rounded bg-secondary-light">
                        <div class="text-3xl font-bold text-primary mb-1"><?= $aiMatchRate ?>%</div>
                        <div class="text-sm text-secondary" data-i18n="statistics.ui.txt_3ab37258">نسبة المطابقة الآلية</div>
                    </div>
                    <div class="text-center p-3 border rounded bg-secondary-light">
                        <div class="text-3xl font-bold text-success mb-1"><?= formatNumber($autoMatchEvents) ?></div>
                        <div class="text-sm text-secondary" data-i18n="statistics.ui.txt_1c9063d3">عمليات دمج تلقائي</div>
                    </div>
                    <div class="text-center p-3 border rounded bg-secondary-light">
                        <div class="text-3xl font-bold text-info mb-1"><?= formatNumber($mlStats['confirmations']) ?></div>
                        <div class="text-sm text-secondary" data-i18n="statistics.ui.txt_683cbfbc">أنماط تم تعلمها</div>
                    </div>
                    <div class="text-center p-3 border rounded bg-secondary-light">
                        <div class="text-3xl font-bold text-warning mb-1"><?= $timeSaved ?>h</div>
                        <div class="text-sm text-secondary" data-i18n="statistics.ui.txt_4aba8222">وقت تم توفيره</div>
                    </div>
                </div>

                <div class="grid-2 gap-6">
                    <!-- Learned Patterns -->
                    <div>
                        <h4 class="font-bold text-sm mb-3 text-secondary" data-i18n="statistics.ui.txt_f67daced">أكثر الأنماط المؤكدة (Verified Patterns)</h4>
                        <table class="table table-sm border">
                            <thead><tr><th data-i18n="statistics.ui.txt_503e56ad">النص الأصلي</th><th data-i18n="statistics.ui.txt_5ced8856">الاسم الرسمي</th><th class="text-center" data-i18n="statistics.ui.txt_000838e1">تكرار</th></tr></thead>
                            <tbody>
                                <?php foreach ($confirmedPatterns as $p): ?>
                                <tr>
                                    <td class="text-xs text-muted truncate stats-truncate-150"><?= htmlspecialchars($p['raw_supplier_name']) ?></td>
                                    <td class="text-xs font-bold"><?= htmlspecialchars($p['official_name']) ?></td>
                                    <td class="text-center"><span class="badge badge-success-light"><?= $p['count'] ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Rejected Patterns -->
                    <div>
                        <h4 class="font-bold text-sm mb-3 text-secondary" data-i18n="statistics.ui.txt_a5334b5c">أنماط تم رفضها (False Positives)</h4>
                        <table class="table table-sm border">
                            <thead><tr><th data-i18n="statistics.ui.txt_503e56ad">النص الأصلي</th><th data-i18n="statistics.ui.txt_bb78450f">الاقتراح المرفوض</th><th class="text-center" data-i18n="statistics.ui.txt_000838e1">تكرار</th></tr></thead>
                            <tbody>
                                <?php foreach ($rejectedPatterns as $p): ?>
                                <tr>
                                    <td class="text-xs text-muted truncate stats-truncate-150"><?= htmlspecialchars($p['raw_supplier_name']) ?></td>
                                    <td class="text-xs text-danger"><?= htmlspecialchars($p['official_name']) ?></td>
                                    <td class="text-center"><span class="badge badge-danger-light"><?= $p['count'] ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================ -->
        <!-- SECTION 8: TRENDS & FINANCIALS -->
        <!-- ============================================ -->
        <!-- 5. Time & Performance Grid -->
        <!-- Expiration Trends By Month -->
        <div class="card mb-6">
            <div class="card-header border-bottom">
                <h3 class="card-title text-lg flex items-center gap-2">
                    <i data-lucide="calendar-range" class="icon-18"></i>
                    <span data-i18n="statistics.ui.section.expiry_pressure_12m">ضغط الانتهاء المتوقع (12 شهر القادمة)</span>
                </h3>
            </div>
            <div class="card-body">
                <div class="grid-12 gap-2">
                    <?php 
                    $maxCount = 1;
                    foreach ($expirationByMonth as $m) $maxCount = max($maxCount, $m['count']);

                    foreach ($expirationByMonth as $month): 
                        $isPeak = ($peakMonth['month'] ?? '') === $month['month'];
                        $percentage = ($month['count'] / $maxCount) * 100;
                        $mPart = substr($month['month'], 5, 2);
                        $yPart = substr($month['month'], 2, 2);
                        $monthLabelKey = $statsMonthKeyMap[$mPart] ?? '';
                        $monthLabel = $monthLabelKey !== '' ? $statsT($monthLabelKey, [], $mPart) : $mPart;
                        $mDisplay = $monthLabel . ' ' . $yPart;
                    ?>
                    <div class="month-stat-card stats-month-card <?= $isPeak ? 'is-peak' : '' ?>">
                        <div class="month-stat-bar stats-month-column" data-bar-height="<?= $percentage ?>"></div>
                        <div class="month-stat-content">
                            <div class="text-xs text-muted mb-1 stats-month-label"><?= $mDisplay ?></div>
                            <div class="text-base font-bold stats-month-value <?= $isPeak ? 'text-danger' : 'text-primary' ?>"><?= $month['count'] ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="grid-3 mb-6">
            <!-- 1. Time Trends -->
            <div class="card">
                <div class="card-header border-bottom">
                    <h3 class="card-title text-base" data-i18n="statistics.ui.txt_6347effc">أنماط الوقت والنشاط</h3>
                </div>
                <div class="card-body">
                    <div class="flex items-center justify-between mb-4 pb-4 border-bottom border-light">
                        <div>
                            <div class="text-sm text-secondary mb-1" data-i18n="statistics.ui.txt_639a60dc">ساعة الذروة</div>
                            <div class="text-2xl font-bold text-primary"><?= $peakHour['hour'] ?? '00' ?>:00</div>
                        </div>
                        <div class="text-right">
                            <div class="text-xs text-muted" data-i18n="statistics.ui.txt_2cbbc5ba">عدد العمليات</div>
                            <div class="font-bold"><?= formatNumber($peakHour['count'] ?? 0) ?></div>
                        </div>
                    </div>
                    <div class="flex items-center justify-between mb-4 pb-4 border-bottom border-light">
                        <div>
                            <div class="text-sm text-secondary mb-1" data-i18n="statistics.ui.txt_d6372313">اليوم الأكثر نشاطاً</div>
                            <div class="text-2xl font-bold text-primary"><?= htmlspecialchars($busiestDayLabel) ?></div>
                        </div>
                        <div class="text-right">
                            <div class="text-xs text-muted" data-i18n="statistics.ui.txt_2cbbc5ba">عدد العمليات</div>
                            <div class="font-bold"><?= formatNumber($busiestDay['count'] ?? 0) ?></div>
                        </div>
                    </div>
                    <div>
                        <div class="text-sm text-secondary mb-2" data-i18n="statistics.ui.txt_cc1a11a4">النمو الأسبوعي</div>
                        <div class="flex items-center gap-2">
                            <span class="text-3xl font-bold <?= $trendPercent >= 0 ? 'text-success' : 'text-danger' ?>">
                                <?= $trendDirection ?>
                            </span>
                            <span class="text-xs text-muted" data-i18n="statistics.ui.txt_075b9ecc">(مقارنة بالأسبوع الماضي)</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2. Types Distribution (Restored) -->
            <div class="card">
                <div class="card-header border-bottom">
                    <h3 class="card-title text-base" data-i18n="statistics.ui.txt_88b1d3a1">توزيع أنواع الضمانات</h3>
                </div>
                <div class="card-body p-0">
                    <table class="table">
                        <thead><tr><th data-i18n="statistics.ui.table.type">النوع</th><th class="text-center" data-i18n="statistics.ui.txt_a82c1974">العدد</th></tr></thead>
                        <tbody>
                            <?php foreach ($typeDistribution as $type): ?>
                            <?php $typeLabel = isset($type['type']) && $type['type'] !== '' ? (string)$type['type'] : $statsT('statistics.ui.unknown'); ?>
                            <tr>
                                <td class="text-sm"><?= htmlspecialchars($typeLabel) ?></td>
                                <td class="text-center font-bold"><?= formatNumber($type['count']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 3. Financial Correlation -->
            <div class="card">
                <div class="card-header border-bottom">
                    <h3 class="card-title text-base" data-i18n="statistics.ui.txt_7e11865b">تحليل المبالغ والتمديد</h3>
                </div>
                <div class="card-body p-0">
                    <table class="table">
                        <thead>
                            <tr>
                                <th data-i18n="statistics.ui.txt_59de6a8f">الفئة</th>
                                <th class="text-center" data-i18n="statistics.ui.txt_a82c1974">العدد</th>
                                <th class="text-center" data-i18n="statistics.ui.table.extend">تمديد</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($amountCorrelation as $row): ?>
                            <?php
                            $rangeKey = $statsAmountRangeKeyMap[(string)($row['range'] ?? '')] ?? null;
                            $rangeLabel = $rangeKey !== null ? $statsT($rangeKey, [], (string)($row['range'] ?? '')) : (string)($row['range'] ?? '');
                            ?>
                            <tr>
                                <td class="text-sm font-bold truncate"><?= htmlspecialchars($rangeLabel) ?></td>
                                <td class="text-center text-secondary"><?= formatNumber($row['total']) ?></td>
                                <td class="text-center">
                                    <span class="badge <?= $row['ext_rate'] > 50 ? 'badge-warning-light' : 'badge-neutral' ?>">
                                        <?= $row['ext_rate'] ?>%
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

    <!-- Lucide Icons (local, CSP-safe) -->
    <script src="../public/js/vendor/lucide.min.js"></script>
    <script>
        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
        }

        document.querySelectorAll('[data-bar-width]').forEach((el) => {
            const width = Number(el.dataset.barWidth || 0);
            el.style.setProperty('--bar-width', `${Math.max(0, Math.min(100, width))}%`);
        });

        document.querySelectorAll('[data-bar-height]').forEach((el) => {
            const height = Number(el.dataset.barHeight || 0);
            el.style.setProperty('--bar-height', `${Math.max(0, Math.min(100, height))}%`);
        });
    </script>
</body>
</html>
