<?php
// Prevent caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once __DIR__ . '/../app/Support/autoload.php';
use App\Support\Database;

header('Content-Type: text/html; charset=utf-8');

// Helper functions (Added span for currency symbol styling)
function formatMoney($amount) { return number_format((float)$amount, 2) . ' <span class="text-xs text-muted">ر.س</span>'; }
function formatNumber($num) { return number_format((float)$num); }

$db = Database::connect();

// Initialize ROI variables to prevent undefined warnings
$totalHoursSaved = 0;
$fteMonthsSaved = 0;
$costSaved = 0;

// Production Mode: Test Data Filtering Setup
$settings = \App\Support\Settings::getInstance();
$isProd = $settings->isProductionMode();
// G = with alias 'g', D = direct no alias
$whereG = $isProd ? " WHERE (g.is_test_data = 0 OR g.is_test_data IS NULL) " : " WHERE 1=1 ";
$andG   = $isProd ? " AND (g.is_test_data = 0 OR g.is_test_data IS NULL) " : "";
$whereD = $isProd ? " WHERE (is_test_data = 0 OR is_test_data IS NULL) " : " WHERE 1=1 ";
$andD   = $isProd ? " AND (is_test_data = 0 OR is_test_data IS NULL) " : "";

try {
    // ============================================
    // SECTION 1: GLOBAL METRICS (ASSET vs OCCURRENCE)
    // ============================================
    // For subquery with JOIN, we need proper WHERE clause
    $occurrencesQuery = $isProd 
        ? "SELECT COUNT(*) FROM guarantee_occurrences o JOIN guarantees g ON o.guarantee_id = g.id WHERE (g.is_test_data = 0 OR g.is_test_data IS NULL)"
        : "SELECT COUNT(*) FROM guarantee_occurrences o JOIN guarantees g ON o.guarantee_id = g.id";
    
    $overview = $db->query("
        SELECT 
            (SELECT COUNT(*) FROM guarantees $whereD) as total_assets,
            ($occurrencesQuery) as total_occurrences,
            (SELECT COUNT(*) FROM guarantees WHERE json_extract(raw_data, '\$.expiry_date') >= date('now') $andD) as active_assets,
            (SELECT COUNT(*) FROM batch_metadata WHERE status='active') as active_batches,
            (SELECT SUM(CAST(json_extract(raw_data, '\$.amount') AS REAL)) FROM guarantees $whereD) as total_amount,
            (SELECT AVG(CAST(json_extract(raw_data, '\$.amount') AS REAL)) FROM guarantees $whereD) as avg_amount,
            (SELECT MAX(CAST(json_extract(raw_data, '\$.amount') AS REAL)) FROM guarantees $whereD) as max_amount,
            (SELECT MIN(CAST(json_extract(raw_data, '\$.amount') AS REAL)) FROM guarantees $whereD) as min_amount
    ")->fetch(PDO::FETCH_ASSOC);

    $efficiencyRatio = $overview['total_assets'] > 0 
        ? round($overview['total_occurrences'] / $overview['total_assets'], 2) 
        : 1;

    // ============================================
    // SECTION 2: BATCH OPERATIONS ANALYSIS
    // ============================================
    // Analyze recent batches: Content vs Context
    $batchStats = $db->query("
        SELECT 
            o.batch_identifier,
            COALESCE(MAX(m.batch_name), 'دفعة ' || SUBSTR(o.batch_identifier, 1, 15)) as batch_name,
            MAX(o.occurred_at) as import_date,
            COUNT(o.id) as total_rows,
            SUM(CASE WHEN g.import_source = o.batch_identifier THEN 1 ELSE 0 END) as new_items,
            SUM(CASE WHEN g.import_source != o.batch_identifier THEN 1 ELSE 0 END) as recurring_items
        FROM guarantee_occurrences o
        JOIN guarantees g ON o.guarantee_id = g.id
        LEFT JOIN batch_metadata m ON o.batch_identifier = m.import_source
        $whereG
        GROUP BY o.batch_identifier
        ORDER BY import_date DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Identify Most Frequent Assets (Top Recurring)
    $topRecurring = $db->query("
        SELECT 
            g.guarantee_number,
            COALESCE(s.official_name, 'غير محدد') as supplier,
            COALESCE(b.arabic_name, 'غير محدد') as bank,
            COUNT(o.id) as occurrence_count
        FROM guarantee_occurrences o
        JOIN guarantees g ON o.guarantee_id = g.id
        LEFT JOIN guarantee_decisions d ON g.id = d.guarantee_id
        LEFT JOIN suppliers s ON d.supplier_id = s.id
        LEFT JOIN banks b ON d.bank_id = b.id
        $whereG
        GROUP BY g.id
        ORDER BY occurrence_count DESC, g.imported_at DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    // ============================================
    // SECTION 3: BANKS & SUPPLIERS (Existing)
    // ============================================
    $topSuppliers = $db->query("
        SELECT s.official_name, COUNT(*) as count
        FROM guarantee_decisions d
        JOIN suppliers s ON d.supplier_id = s.id
        JOIN guarantees g ON d.guarantee_id = g.id 
        $whereG
        GROUP BY s.id
        ORDER BY count DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);

    $topBanks = $db->query("
        SELECT 
            b.arabic_name as bank_name,
            COUNT(*) as count,
            SUM(CAST(json_extract(g.raw_data, '$.amount') AS REAL)) as total_amount,
            (SELECT COUNT(*) FROM guarantee_history h 
             JOIN guarantee_decisions d2 ON h.guarantee_id = d2.guarantee_id
             WHERE d2.bank_id = b.id AND h.event_subtype = 'extension') as extensions
        FROM guarantee_decisions d
        JOIN banks b ON d.bank_id = b.id
        JOIN guarantees g ON d.guarantee_id = g.id
        $whereG
        GROUP BY b.id
        ORDER BY count DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);

    $stableSuppliers = $db->query("
        SELECT s.official_name, COUNT(DISTINCT d.guarantee_id) as count
        FROM suppliers s
        JOIN guarantee_decisions d ON s.id = d.supplier_id
        JOIN guarantees g ON d.guarantee_id = g.id
        $whereG
        GROUP BY s.id
        ORDER BY count DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $riskySuppliers = $db->query("
        SELECT 
            s.official_name,
            COUNT(DISTINCT d.guarantee_id) as total,
            COUNT(DISTINCT CASE WHEN h.event_subtype = 'extension' THEN h.guarantee_id END) as extensions,
            COUNT(DISTINCT CASE WHEN h.event_subtype = 'reduction' THEN h.guarantee_id END) as reductions,
            ROUND((CAST(COUNT(DISTINCT CASE WHEN h.event_subtype = 'extension' THEN h.guarantee_id END) AS REAL) * 0.6 + 
                   CAST(COUNT(DISTINCT CASE WHEN h.event_subtype = 'reduction' THEN h.guarantee_id END) AS REAL) * 0.4) / 
                   CAST(COUNT(DISTINCT d.guarantee_id) AS REAL) * 100, 1) as risk_score
        FROM suppliers s
        JOIN guarantee_decisions d ON s.id = d.supplier_id
        JOIN guarantees g ON d.guarantee_id = g.id
        LEFT JOIN guarantee_history h ON d.guarantee_id = h.guarantee_id
        $whereG
        GROUP BY s.id
        HAVING total >= 2
        ORDER BY risk_score DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $challengingSuppliers = $db->query("
        SELECT s.official_name, COUNT(*) as manual_count
        FROM guarantee_decisions d
        JOIN suppliers s ON d.supplier_id = s.id
        JOIN guarantees g ON d.guarantee_id = g.id
        WHERE (d.decision_source = 'manual' OR d.decision_source IS NULL)
        $andG
        GROUP BY s.id
        ORDER BY manual_count DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $uniqueCounts = $db->query("
        SELECT 
            COUNT(DISTINCT d.supplier_id) as suppliers,
            COUNT(DISTINCT d.bank_id) as banks
        FROM guarantee_decisions d
        JOIN guarantees g ON d.guarantee_id = g.id
        $whereG
    ")->fetch(PDO::FETCH_ASSOC);
    
    $bankSupplierPairs = $db->query("
        SELECT 
            b.arabic_name as bank,
            s.official_name as supplier,
            COUNT(*) as count
        FROM guarantee_decisions d
        JOIN banks b ON d.bank_id = b.id
        JOIN suppliers s ON d.supplier_id = s.id
        JOIN guarantees g ON d.guarantee_id = g.id
        $whereG
        GROUP BY b.id, s.id
        ORDER BY count DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $exclusiveSuppliers = $db->query("
        SELECT COUNT(*) FROM (
            SELECT d.supplier_id
            FROM guarantee_decisions d
            JOIN guarantees g ON d.guarantee_id = g.id
            $whereG
            GROUP BY d.supplier_id
            HAVING COUNT(DISTINCT d.bank_id) = 1
        )
    ")->fetchColumn();


    // ============================================
    // SECTION 3: TIME & PERFORMANCE
    // ============================================
    $timing = $db->query("
        SELECT 
            AVG(CAST((julianday(d.decided_at) - julianday(g.imported_at)) * 24 AS REAL)) as avg_hours,
            MIN(CAST((julianday(d.decided_at) - julianday(g.imported_at)) * 24 AS REAL)) as min_hours,
            MAX(CAST((julianday(d.decided_at) - julianday(g.imported_at)) * 24 AS REAL)) as max_hours
        FROM guarantee_decisions d
        JOIN guarantees g ON d.guarantee_id = g.id
        WHERE d.decided_at IS NOT NULL $andG
    ")->fetch(PDO::FETCH_ASSOC);
    
    $peakHour = $db->query("
        SELECT strftime('%H', h.created_at) as hour, COUNT(*) as count
        FROM guarantee_history h
        JOIN guarantees g ON h.guarantee_id = g.id
        $whereG
        GROUP BY hour
        ORDER BY count DESC
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
    
    $qualityMetrics = $db->query("
        SELECT 
            COUNT(DISTINCT g.id) as total,
            COUNT(DISTINCT CASE WHEN h.id IS NULL THEN g.id END) as ftr,
            COUNT(DISTINCT CASE WHEN (SELECT COUNT(*) FROM guarantee_history h2 
                                      WHERE h2.guarantee_id = g.id AND h2.event_type = 'modified') >= 3 
                          THEN g.id END) as complex
        FROM guarantees g
        LEFT JOIN guarantee_history h ON g.id = h.guarantee_id AND h.event_type = 'modified'
        $whereG
    ")->fetch(PDO::FETCH_ASSOC);
    
    $firstTimeRight = $qualityMetrics['total'] > 0 ? round(($qualityMetrics['ftr'] / $qualityMetrics['total']) * 100, 1) : 0;
    $complexGuarantees = $qualityMetrics['complex'];
    
    $busiestDay = $db->query("
        SELECT 
            CASE CAST(strftime('%w', created_at) AS INTEGER)
                WHEN 0 THEN 'الأحد' WHEN 1 THEN 'الإثنين' WHEN 2 THEN 'الثلاثاء'
                WHEN 3 THEN 'الأربعاء' WHEN 4 THEN 'الخميس' WHEN 5 THEN 'الجمعة' WHEN 6 THEN 'السبت'
            END as weekday,
            COUNT(*) as count
        FROM guarantee_history
        GROUP BY strftime('%w', created_at)
        ORDER BY count DESC
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
    
    $weeklyTrend = $db->query("
        SELECT 
            COUNT(CASE WHEN imported_at >= date('now', '-7 days') THEN 1 END) as this_week,
            COUNT(CASE WHEN imported_at >= date('now', '-14 days') AND imported_at < date('now', '-7 days') THEN 1 END) as last_week
        FROM guarantees
        $whereD
    ")->fetch(PDO::FETCH_ASSOC);

    $trendPercent = 0;
    if (($weeklyTrend['last_week'] ?? 0) > 0) {
        $trendPercent = (($weeklyTrend['this_week'] - $weeklyTrend['last_week']) / $weeklyTrend['last_week']) * 100;
    }
    $trendDirection = ($trendPercent >= 0 ? '+' : '') . round($trendPercent, 1) . '%';


    // ============================================
    // SECTION 4: EXPIRATION & ACTIONS (RECONSTRUCTED)
    // ============================================
    
    // 4A: Expiration Pressure
    $expiration = $db->query("
        SELECT 
            COUNT(CASE WHEN json_extract(raw_data, '$.expiry_date') BETWEEN date('now') AND date('now', '+30 days') THEN 1 END) as next_30,
            COUNT(CASE WHEN json_extract(raw_data, '$.expiry_date') BETWEEN date('now') AND date('now', '+90 days') THEN 1 END) as next_90
        FROM guarantees
        $whereD
    ")->fetch(PDO::FETCH_ASSOC);

    // Peak Month
    $peakMonth = $db->query("
        SELECT strftime('%Y-%m', json_extract(raw_data, '$.expiry_date')) as month, COUNT(*) as count
        FROM guarantees
        WHERE json_extract(raw_data, '$.expiry_date') >= date('now')
        GROUP BY month
        ORDER BY count DESC
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
    
    // Expiration next 12 months
    $expirationByMonth = $db->query("
        SELECT strftime('%Y-%m', json_extract(raw_data, '$.expiry_date')) as month, COUNT(*) as count
        FROM guarantees
        WHERE json_extract(raw_data, '$.expiry_date') BETWEEN date('now') AND date('now', '+1 year')
        GROUP BY month
        ORDER BY month ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // 4C: Actions
    $actions = $db->query("
        SELECT
            COUNT(CASE WHEN h.event_subtype = 'extension' THEN 1 END) as extensions,
            COUNT(CASE WHEN h.event_subtype = 'reduction' THEN 1 END) as reductions,
            COUNT(CASE WHEN h.event_type = 'released' THEN 1 END) as releases,
            COUNT(CASE WHEN h.event_type = 'released' AND h.created_at >= date('now', '-7 days') THEN 1 END) as recent_releases
        FROM guarantee_history h
        JOIN guarantees g ON h.guarantee_id = g.id
        $whereG
    ")->fetch(PDO::FETCH_ASSOC);

    $multipleExtensions = $db->query("
        SELECT COUNT(*) FROM (
            SELECT h.guarantee_id
            FROM guarantee_history h
            JOIN guarantees g ON h.guarantee_id = g.id
            WHERE h.event_subtype = 'extension' $andG
            GROUP BY h.guarantee_id
            HAVING COUNT(*) > 1
        )
    ")->fetchColumn();

    $extensionProbability = $db->query("
        SELECT 
            s.official_name,
            ROUND(CAST(COUNT(CASE WHEN h.event_subtype = 'extension' THEN 1 END) AS REAL) / 
                  CAST(COUNT(DISTINCT d.guarantee_id) AS REAL) * 100, 0) as probability
        FROM suppliers s
        JOIN guarantee_decisions d ON s.id = d.supplier_id
        LEFT JOIN guarantee_history h ON d.guarantee_id = h.guarantee_id AND h.event_subtype = 'extension'
        GROUP BY s.id
        HAVING COUNT(DISTINCT d.guarantee_id) >= 3
        ORDER BY probability DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $topEventTypes = $db->query("
        SELECT event_type, COUNT(*) as count
        FROM guarantee_history
        GROUP BY event_type
        ORDER BY count DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);


    // ============================================
    // SECTION 5: AI & MACHINE LEARNING
    // ============================================
    $aiStats = $db->query("
        SELECT
            COUNT(*) as total,
            COUNT(CASE WHEN d.decision_source IN ('auto', 'auto_match', 'ai_match', 'ai_quick', 'direct_match') THEN 1 END) as ai_matches,
            COUNT(CASE WHEN d.decision_source = 'manual' OR d.decision_source IS NULL THEN 1 END) as manual
        FROM guarantee_decisions d
        JOIN guarantees g ON d.guarantee_id = g.id
        $whereG
    ")->fetch(PDO::FETCH_ASSOC);
    
    $aiMatchRate = $aiStats['total'] > 0 ? round(($aiStats['ai_matches'] / $aiStats['total']) * 100, 1) : 0;
    $manualIntervention = $aiStats['total'] > 0 ? round(($aiStats['manual'] / $aiStats['total']) * 100, 1) : 0;
    $automationRate = 100 - $manualIntervention;
    
    $autoMatchEvents = $db->query("
        SELECT COUNT(*) 
        FROM guarantee_history h
        JOIN guarantees g ON h.guarantee_id = g.id
        WHERE h.event_type IN ('auto_matched', 'modified')
        AND h.event_subtype IN ('auto_match', 'bank_match', 'ai_match')
        $andG
    ")->fetchColumn();
    
    // ML from learning_confirmations
    $mlStats = ['confirmations' => 0, 'rejections' => 0, 'total' => 0];
    $confirmedPatterns = [];
    $rejectedPatterns = [];
    $confidenceDistribution = ['high' => 0, 'medium' => 0, 'low' => 0];
    
    try {
        $mlStatsCheck = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='learning_confirmations'")->fetch();
        if ($mlStatsCheck) {
             $mlStats = $db->query("
                SELECT 
                    COUNT(CASE WHEN action = 'confirm' THEN 1 END) as confirmations,
                    COUNT(CASE WHEN action = 'reject' THEN 1 END) as rejections,
                    COUNT(*) as total
                FROM learning_confirmations
            ")->fetch(PDO::FETCH_ASSOC);
            
            $confirmedPatterns = $db->query("
                SELECT lc.raw_supplier_name, s.official_name, lc.count
                FROM learning_confirmations lc
                JOIN suppliers s ON lc.supplier_id = s.id
                WHERE lc.action = 'confirm'
                ORDER BY lc.count DESC
                LIMIT 5
            ")->fetchAll(PDO::FETCH_ASSOC);
            
            $rejectedPatterns = $db->query("
                SELECT lc.raw_supplier_name, s.official_name, lc.count
                FROM learning_confirmations lc
                JOIN suppliers s ON lc.supplier_id = s.id
                WHERE lc.action = 'reject'
                ORDER BY lc.count DESC
                LIMIT 5
            ")->fetchAll(PDO::FETCH_ASSOC);
        }

        $learningPatternsCheck = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='learning_patterns'")->fetch();
        if ($learningPatternsCheck) {
            $confidenceDistribution = $db->query("
                SELECT 
                    COUNT(CASE WHEN confidence >= 80 THEN 1 END) as high,
                    COUNT(CASE WHEN confidence >= 50 AND confidence < 80 THEN 1 END) as medium,
                    COUNT(CASE WHEN confidence < 50 THEN 1 END) as low
                FROM learning_patterns
            ")->fetch(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        // Tables don't exist
    }
    
    $mlAccuracy = $mlStats['total'] > 0 ? round(($mlStats['confirmations'] / $mlStats['total']) * 100, 1) : 0;
    $timeSaved = round(($aiStats['ai_matches'] ?? 0) * 2 / 60, 1); // 2 min per decision
    
    // ✅ Fix: ROI Variables (Moved to top of file)


    // ============================================
    // SECTION 6: FINANCIAL & TYPES
    // ============================================
    // 🔧 REVERTED: Normalization should happen at import time, not here.
    $typeDistribution = $db->query("
        SELECT json_extract(raw_data, '$.type') as type, COUNT(*) as count
        FROM guarantees
        $whereD
        GROUP BY type
        ORDER BY count DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $amountCorrelation = $db->query("
        SELECT 
            CASE 
                WHEN CAST(json_extract(g.raw_data, '$.amount') AS REAL) < 100000 THEN 'صغير (<100K)'
                WHEN CAST(json_extract(g.raw_data, '$.amount') AS REAL) < 500000 THEN 'متوسط (100-500K)'
                ELSE 'كبير (>500K)'
            END as range,
            COUNT(DISTINCT g.id) as total,
            COUNT(DISTINCT h.guarantee_id) as extended,
            ROUND(CAST(COUNT(DISTINCT h.guarantee_id) AS REAL) / CAST(COUNT(DISTINCT g.id) AS REAL) * 100, 1) as ext_rate
        FROM guarantees g
        LEFT JOIN guarantee_history h ON g.id = h.guarantee_id AND h.event_subtype = 'extension'
        $whereG
        GROUP BY range
        ORDER BY ext_rate DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

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
    error_log("Statistics error: " . $errorMessage);
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
    $busiestDay = ['weekday' => 'N/A', 'count' => 0];
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
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>الإحصائيات المتقدمة - BGL3</title>
    
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
        .grid-2, .grid-3, .grid-4 { 
            display: grid; 
            gap: 24px;
            margin-bottom: 24px;
        }
        
        .grid-2 { grid-template-columns: repeat(2, 1fr); }
        .grid-3 { grid-template-columns: repeat(3, 1fr); }
        .grid-4 { grid-template-columns: repeat(4, 1fr); }
        
        @media (max-width: 1024px) {
            .grid-4 { grid-template-columns: repeat(2, 1fr); }
        }
        
        @media (max-width: 768px) {
            .grid-2, .grid-3, .grid-4 { grid-template-columns: 1fr; }
            .stats-container { padding: var(--space-md); }
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
    </style>
</head>
<body>
    
    <!-- Unified Header -->
    <?php include __DIR__ . '/../partials/unified-header.php'; ?>
    
    <div class="stats-container">
        
        <!-- Header -->
        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-2xl font-bold text-primary mb-1">لوحة القيادة والتحليل</h1>
                <p class="text-secondary text-sm">نظرة شمولية على الضمانات، الدفعات، والكفاءة التشغيلية</p>
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
                <div class="roi-label">وقت تم توفيره</div>
                <div class="roi-sub">مقارنة بالعمل اليدوي</div>
            </div>
            <div class="roi-metric">
                <?php if ($fteMonthsSaved > 0): ?>
                    <div class="roi-value text-success"><?= $fteMonthsSaved ?><span class="text-sm font-normal text-muted"> شهر</span></div>
                <?php else: ?>
                    <div class="roi-value text-secondary" style="font-size: 18px;">أقل من شهر</div>
                <?php endif; ?>
                <div class="roi-label">إنتاجية موظف (FTE)</div>
                <div class="roi-sub">بناءً على 160 ساعة/شهر</div>
            </div>
            <div class="roi-metric">
                <div class="roi-value text-info"><?= $automationRate ?>%</div>
                <div class="roi-label">نسبة الأتمتة</div>
                <div class="roi-sub">تدخل يدوي محدود (<?= $manualIntervention ?>%)</div>
            </div>
            <div class="roi-metric">
                <div class="roi-value text-warning"><?= formatNumber($costSaved) ?><span class="text-sm font-normal text-muted"> ر.س</span></div>
                <div class="roi-label">قيمة تشغيلية موفرة</div>
                <div class="roi-sub">تقديري (50 ر.س/ساعة)</div>
            </div>
        </div>

        <!-- 1. Key Metrics Cards -->
        <div class="grid-4 mb-6">
            <div class="card p-4 flex flex-col justify-between" style="border-top: 4px solid var(--accent-primary);">
                <div class="text-secondary text-sm mb-2 font-bold">الأصول الفريدة</div>
                <div class="flex justify-between items-end">
                    <span class="text-3xl font-bold text-primary"><?= formatNumber($overview['total_assets']) ?></span>
                    <i data-lucide="box" class="text-light" style="width: 24px;"></i>
                </div>
            </div>
            <div class="card p-4 flex flex-col justify-between" style="border-top: 4px solid var(--accent-info);">
                <div class="text-secondary text-sm mb-2 font-bold">سجلات الظهور</div>
                <div class="flex justify-between items-end">
                    <span class="text-3xl font-bold text-info"><?= formatNumber($overview['total_occurrences']) ?></span>
                    <i data-lucide="layers" class="text-light" style="width: 24px;"></i>
                </div>
            </div>
            <div class="card p-4 flex flex-col justify-between" style="border-top: 4px solid var(--accent-success);">
                <div class="text-secondary text-sm mb-2 font-bold">معدل الكفاءة</div>
                <div class="flex justify-between items-end">
                    <span class="text-3xl font-bold text-success"><?= $efficiencyRatio ?>x</span>
                    <i data-lucide="trending-up" class="text-light" style="width: 24px;"></i>
                </div>
            </div>
            <div class="card p-4 flex flex-col justify-between" style="border-top: 4px solid var(--accent-warning);">
                <div class="text-secondary text-sm mb-2 font-bold">الدفعات النشطة</div>
                <div class="flex justify-between items-end">
                    <span class="text-3xl font-bold text-warning"><?= formatNumber($overview['active_batches']) ?></span>
                    <i data-lucide="activity" class="text-light" style="width: 24px;"></i>
                </div>
            </div>
        </div>

        <!-- 2. Batch Operations (Wide Table) -->
        <div class="card mb-6">
            <div class="card-header border-bottom">
                <h3 class="card-title text-lg flex items-center gap-2">
                    <i data-lucide="history" style="width: 18px;"></i>
                    تحليل أداء الدفعات الأخيرة
                </h3>
            </div>
            <div class="card-body p-0">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>الدفعة</th>
                            <th>تاريخ الاستيراد</th>
                            <th class="text-center">إجمالي الأسطر</th>
                            <th class="text-center">جديد</th>
                            <th class="text-center">تكرار</th>
                            <th class="text-center">نسبة التكرار</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($batchStats as $batch): 
                            $recurRate = $batch['total_rows'] > 0 
                                ? round(($batch['recurring_items'] / $batch['total_rows']) * 100, 1) 
                                : 0;
                        ?>
                        <tr>
                            <td class="font-bold text-primary"><?= htmlspecialchars($batch['batch_name']) ?></td>
                            <td class="text-secondary text-sm"><?= date('Y-m-d H:i', strtotime($batch['import_date'])) ?></td>
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
                    <h3 class="card-title text-base">الأصول الأكثر نشاطاً</h3>
                </div>
                <div class="card-body p-0">
                     <table class="table">
                        <thead><tr><th>رقم الضمان</th><th>المورد</th><th class="text-center">الظهور</th></tr></thead>
                        <tbody>
                            <?php foreach ($topRecurring as $item): ?>
                            <tr>
                                <td class="font-mono text-primary font-bold dir-ltr text-right"><?= htmlspecialchars($item['guarantee_number']) ?></td>
                                <td class="text-sm text-truncate" style="max-width: 150px;"><?= htmlspecialchars($item['supplier']) ?></td>
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
                    <h3 class="card-title text-base">أهم الموردين</h3>
                </div>
                <div class="card-body p-0">
                    <table class="table">
                        <thead><tr><th>المورد</th><th class="text-center">عدد الضمانات</th></tr></thead>
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
                <h3 class="card-title text-lg">الأداء المالي للبنوك</h3>
            </div>
            <div class="card-body p-0">
                <table class="table">
                    <thead>
                        <tr>
                            <th>البنك</th>
                            <th class="text-center">العدد</th>
                            <th class="text-center">إجمالي المبالغ</th>
                            <th class="text-center">التمديدات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($topBanks, 0, 8) as $bank): ?>
                        <tr>
                            <td class="font-bold"><?= htmlspecialchars($bank['bank_name']) ?></td>
                            <td class="text-center font-bold"><?= formatNumber($bank['count']) ?></td>
                            <td class="text-center dir-ltr"><?= formatMoney($bank['total_amount']) ?></td>
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
                    <h3 class="card-title text-base text-success">الأكثر استقراراً</h3>
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
                    <h3 class="card-title text-base text-danger">الأكثر مخاطرة</h3>
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
                    <h3 class="card-title text-base text-warning">تحديات المطابقة</h3>
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
                <div class="text-xs text-muted">موردين فريدين</div>
            </div>
            <div class="metric-mini-card">
                <div class="text-2xl font-bold text-primary mb-1"><?= formatNumber($uniqueCounts['banks']) ?></div>
                <div class="text-xs text-muted">بنوك فريدة</div>
            </div>
            <div class="metric-mini-card">
                <div class="text-2xl font-bold text-info mb-1"><?= formatNumber($exclusiveSuppliers) ?></div>
                <div class="text-xs text-muted">موردين حصريين (بنك واحد)</div>
            </div>
        </div>
        
        <div class="card mb-6">
            <div class="card-header border-bottom">
                <h3 class="card-title text-base">أقوى التحالفات (بنك-مورد)</h3>
            </div>
            <div class="card-body p-0">
                <table class="table">
                    <thead><tr><th>المورد</th><th class="text-center">العلاقة</th><th>البنك</th><th class="text-center">عدد الضمانات</th></tr></thead>
                    <tbody>
                        <?php foreach ($bankSupplierPairs as $pair): ?>
                        <tr>
                            <td class="text-sm"><?= htmlspecialchars($pair['supplier']) ?></td>
                            <td class="text-center text-muted"><i data-lucide="arrow-left-right" style="width: 14px;"></i></td>
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
                    <h3 class="card-title text-base">مؤشرات الجودة</h3>
                </div>
                <div class="card-body">
                    <div class="flex items-center justify-between mb-4 pb-4 border-bottom border-light">
                        <span class="text-secondary">First Time Right</span>
                        <span class="text-2xl font-bold text-success"><?= $firstTimeRight ?>%</span>
                    </div>
                    <div class="flex items-center justify-between mb-4 pb-4 border-bottom border-light">
                        <span class="text-secondary">التدخل اليدوي</span>
                        <span class="text-2xl font-bold <?= $manualIntervention > 20 ? 'text-danger' : 'text-primary' ?>"><?= $manualIntervention ?>%</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-secondary">متوسط وقت المعالجة</span>
                        <span class="text-2xl font-bold text-primary"><?= round($timing['avg_hours'] ?? 0, 1) ?>h</span>
                    </div>
                </div>
            </div>

            <!-- Expiry Analysis (Redesigned) -->
            <div class="card">
                <div class="card-header border-bottom">
                    <h3 class="card-title text-base">تحليل الانتهاءات القادمة</h3>
                </div>
                <div class="card-body">
                    <div class="grid-2 gap-4 mb-4">
                        <div class="p-3 bg-danger-light bg-opacity-10 rounded text-center border border-danger-light">
                            <div class="text-2xl font-bold text-danger mb-1"><?= formatNumber($expiration['next_30']) ?></div>
                            <div class="text-xs font-bold text-danger">عاجل (30 يوم)</div>
                        </div>
                        <div class="p-3 bg-warning-light bg-opacity-10 rounded text-center border border-warning-light">
                            <div class="text-2xl font-bold text-warning mb-1"><?= formatNumber($expiration['next_90']) ?></div>
                            <div class="text-xs font-bold text-warning">قريب (90 يوم)</div>
                        </div>
                    </div>
                    
                    <?php if (!empty($expirationByMonth)): ?>
                    <div>
                        <h4 class="text-xs font-bold text-secondary mb-3 border-bottom pb-2">توقعات الأشهر القادمة (أشهر الذروة)</h4>
                        <div class="space-y-3">
                            <?php foreach (array_slice($expirationByMonth, 0, 3) as $month): ?>
                            <div class="flex items-center text-sm">
                                <div class="w-24 font-mono text-secondary text-xs"><?= $month['month'] ?></div>
                                <div class="flex-1 h-3 bg-gray-100 rounded-full overflow-hidden mx-2">
                                    <div class="h-full bg-primary" style="width: <?= min(($month['count'] / ($peakMonth['count'] ?: 1)) * 100, 100) ?>%"></div>
                                </div>
                                <span class="badge badge-neutral-light"><?= $month['count'] ?> ضمان</span>
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
                <h3 class="card-title text-lg">أداء الذكاء الاصطناعي والأتمتة</h3>
                <span class="badge badge-primary-light">AI Confidence: <?= $mlAccuracy ?>%</span>
            </div>
            <div class="card-body">
                <div class="grid-4 gap-4 mb-6">
                    <div class="text-center p-3 border rounded bg-secondary-light">
                        <div class="text-3xl font-bold text-primary mb-1"><?= $aiMatchRate ?>%</div>
                        <div class="text-sm text-secondary">نسبة المطابقة الآلية</div>
                    </div>
                    <div class="text-center p-3 border rounded bg-secondary-light">
                        <div class="text-3xl font-bold text-success mb-1"><?= formatNumber($autoMatchEvents) ?></div>
                        <div class="text-sm text-secondary">عمليات دمج تلقائي</div>
                    </div>
                    <div class="text-center p-3 border rounded bg-secondary-light">
                        <div class="text-3xl font-bold text-info mb-1"><?= formatNumber($mlStats['confirmations']) ?></div>
                        <div class="text-sm text-secondary">أنماط تم تعلمها</div>
                    </div>
                    <div class="text-center p-3 border rounded bg-secondary-light">
                        <div class="text-3xl font-bold text-warning mb-1"><?= $timeSaved ?>h</div>
                        <div class="text-sm text-secondary">وقت تم توفيره</div>
                    </div>
                </div>

                <div class="grid-2 gap-6">
                    <!-- Learned Patterns -->
                    <div>
                        <h4 class="font-bold text-sm mb-3 text-secondary">أكثر الأنماط المؤكدة (Verified Patterns)</h4>
                        <table class="table table-sm border">
                            <thead><tr><th>النص الأصلي</th><th>الاسم الرسمي</th><th class="text-center">تكرار</th></tr></thead>
                            <tbody>
                                <?php foreach ($confirmedPatterns as $p): ?>
                                <tr>
                                    <td class="text-xs text-muted truncate" style="max-width: 150px;"><?= htmlspecialchars($p['raw_supplier_name']) ?></td>
                                    <td class="text-xs font-bold"><?= htmlspecialchars($p['official_name']) ?></td>
                                    <td class="text-center"><span class="badge badge-success-light"><?= $p['count'] ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Rejected Patterns -->
                    <div>
                        <h4 class="font-bold text-sm mb-3 text-secondary">أنماط تم رفضها (False Positives)</h4>
                        <table class="table table-sm border">
                            <thead><tr><th>النص الأصلي</th><th>الاقتراح المرفوض</th><th class="text-center">تكرار</th></tr></thead>
                            <tbody>
                                <?php foreach ($rejectedPatterns as $p): ?>
                                <tr>
                                    <td class="text-xs text-muted truncate" style="max-width: 150px;"><?= htmlspecialchars($p['raw_supplier_name']) ?></td>
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
        <div class="grid-3 mb-6">
            <!-- 1. Time Trends -->
            <div class="card">
                <div class="card-header border-bottom">
                    <h3 class="card-title text-base">أنماط الوقت والنشاط</h3>
                </div>
                <div class="card-body">
                    <div class="flex items-center justify-between mb-4 pb-4 border-bottom border-light">
                        <div>
                            <div class="text-sm text-secondary mb-1">ساعة الذروة</div>
                            <div class="text-2xl font-bold text-primary"><?= $peakHour['hour'] ?? '00' ?>:00</div>
                        </div>
                        <div class="text-right">
                            <div class="text-xs text-muted">عدد العمليات</div>
                            <div class="font-bold"><?= formatNumber($peakHour['count'] ?? 0) ?></div>
                        </div>
                    </div>
                    <div class="flex items-center justify-between mb-4 pb-4 border-bottom border-light">
                        <div>
                            <div class="text-sm text-secondary mb-1">اليوم الأكثر نشاطاً</div>
                            <div class="text-2xl font-bold text-primary"><?= $busiestDay['weekday'] ?? '-' ?></div>
                        </div>
                        <div class="text-right">
                            <div class="text-xs text-muted">عدد العمليات</div>
                            <div class="font-bold"><?= formatNumber($busiestDay['count'] ?? 0) ?></div>
                        </div>
                    </div>
                    <div>
                        <div class="text-sm text-secondary mb-2">النمو الأسبوعي</div>
                        <div class="flex items-center gap-2">
                            <span class="text-3xl font-bold <?= $trendPercent >= 0 ? 'text-success' : 'text-danger' ?>">
                                <?= $trendDirection ?>
                            </span>
                            <span class="text-xs text-muted">(مقارنة بالأسبوع الماضي)</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2. Types Distribution (Restored) -->
            <div class="card">
                <div class="card-header border-bottom">
                    <h3 class="card-title text-base">توزيع أنواع الضمانات</h3>
                </div>
                <div class="card-body p-0">
                    <table class="table">
                        <thead><tr><th>النوع</th><th class="text-center">العدد</th></tr></thead>
                        <tbody>
                            <?php foreach ($typeDistribution as $type): ?>
                            <tr>
                                <td class="text-sm"><?= htmlspecialchars($type['type'] ?? 'غير محدد') ?></td>
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
                    <h3 class="card-title text-base">تحليل المبالغ والتمديد</h3>
                </div>
                <div class="card-body p-0">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>الفئة</th>
                                <th class="text-center">العدد</th>
                                <th class="text-center">تمديد</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($amountCorrelation as $row): ?>
                            <tr>
                                <td class="text-sm font-bold truncate"><?= $row['range'] ?></td>
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

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <script>
        lucide.createIcons();
    </script>
</body>
</html>
