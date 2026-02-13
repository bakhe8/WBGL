<?php
/**
 * Batches List Page
 * Shows all batches (active and completed)
 */

require_once __DIR__ . '/../app/Support/autoload.php';
use App\Support\Database;
use App\Support\Settings;

$db = Database::connect();

// Get all batches (hybrid: shows all, uses occurrence counts when available)
$batches = $db->query("
    SELECT 
        g.import_source,
        COALESCE(bm.batch_name, 'دفعة ' || SUBSTR(g.import_source, 1, 25)) as batch_name,
        COALESCE(bm.status, 'active') as status,
        COALESCE(bm.batch_notes, '') as batch_notes,
        COALESCE(occ.count, COUNT(g.id)) as guarantee_count,
        MIN(g.imported_at) as created_at,
        GROUP_CONCAT(DISTINCT g.imported_by) as imported_by
    FROM guarantees g
    LEFT JOIN batch_metadata bm ON bm.import_source = g.import_source
    LEFT JOIN (
        SELECT batch_identifier, COUNT(DISTINCT guarantee_id) as count
        FROM guarantee_occurrences GROUP BY batch_identifier
    ) occ ON occ.batch_identifier = g.import_source
    GROUP BY g.import_source
    ORDER BY MIN(g.imported_at) DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Production Mode: Filter out test batches
$settings = Settings::getInstance();
if ($settings->isProductionMode()) {
    // Filter by import_source prefix AND by checking if batch has non-test guarantees
    $filteredBatches = [];
    foreach ($batches as $batch) {
        // Skip test batches by name
        if (str_starts_with($batch['import_source'], 'test_')) {
            continue;
        }
        
        // Check if this batch has any non-test guarantees
        $stmt = $db->prepare("
            SELECT COUNT(*) as count 
            FROM guarantees 
            WHERE import_source = ? 
            AND (is_test_data = 0 OR is_test_data IS NULL)
        ");
        $stmt->execute([$batch['import_source']]);
        $nonTestCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        // Only include batch if it has non-test guarantees
        if ($nonTestCount > 0) {
            // Update guarantee_count to reflect only non-test guarantees
            $batch['guarantee_count'] = $nonTestCount;
            $filteredBatches[] = $batch;
        }
    }
    $batches = $filteredBatches;
}

// Separate active and completed
$active = array_filter($batches, fn($b) => $b['status'] === 'active');
$completed = array_filter($batches, fn($b) => $b['status'] === 'completed');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>الدفعات - نظام إدارة الضمانات</title>
    
    <!-- Design System CSS -->
    <link rel="stylesheet" href="../public/css/design-system.css">
    <link rel="stylesheet" href="../public/css/components.css">
    <link rel="stylesheet" href="../public/css/layout.css">
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    
    <style>
        /* Page-specific styles */
        .page-container {
            width: 100%;
            padding: var(--space-lg);
        }
        
        .page-title {
            font-size: var(--font-size-2xl);
            font-weight: var(--font-weight-bold);
            color: var(--text-primary);
            margin-bottom: var(--space-xs);
        }
        
        .page-subtitle {
            color: var(--text-secondary);
            margin-bottom: var(--space-xl);
            font-size: var(--font-size-sm);
        }
        
        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: var(--space-md);
        }
        
        .section-title {
            font-size: var(--font-size-xl);
            font-weight: var(--font-weight-bold);
            color: var(--text-primary);
        }
        
        .batch-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: var(--space-md);
            margin-bottom: var(--space-2xl);
            width: 100%;
        }
        
        .batch-card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-primary);
            box-shadow: var(--shadow-sm);
            padding: var(--space-md);
            transition: all var(--transition-base);
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 180px;
        }
        
        .batch-card:hover {
            box-shadow: var(--shadow-lg);
            transform: translateY(-4px);
            border-color: var(--accent-primary);
        }
        
        .batch-card.active {
            border-top: 4px solid var(--accent-success);
            background: linear-gradient(180deg, var(--bg-card) 0%, #f0fdf4 100%);
        }
        
        .batch-card.completed {
            border-top: 4px solid var(--border-neutral);
            background: var(--bg-secondary);
            opacity: 0.85;
        }
        
        .batch-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: var(--space-sm);
        }

        .batch-card-title {
            font-size: var(--font-size-md);
            font-weight: var(--font-weight-bold);
            color: var(--text-primary);
            line-height: var(--line-height-tight);
            margin: 0;
        }
        
        .batch-type-icon {
            font-size: 1.2rem;
            opacity: 0.7;
        }

        .batch-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
            font-size: var(--font-size-sm);
            color: var(--text-secondary);
            margin-bottom: var(--space-md);
        }
        
        .batch-meta-item {
            display: flex;
            align-items: center;
            gap: var(--space-xs);
        }

        .batch-notes {
            font-size: var(--font-size-xs);
            color: var(--text-muted);
            font-style: italic;
            border-top: 1px solid var(--border-light);
            padding-top: var(--space-xs);
            margin-top: var(--space-xs);
        }
        
        .empty-state {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: var(--space-2xl);
            text-align: center;
            color: var(--text-muted);
            box-shadow: var(--shadow-sm);
        }
        
        .back-link {
            display: inline-block;
            margin-top: var(--space-xl);
            color: var(--accent-primary);
            text-decoration: none;
            transition: color var(--transition-base);
        }
        
        .back-link:hover {
            color: var(--accent-primary-hover);
            text-decoration: underline;
        }
    </style>
</head>
<body>
    
    <!-- Unified Header -->
    <?php include __DIR__ . '/../partials/unified-header.php'; ?>
    
    <div class="page-container">
        
        <div class="page-title">الدفعات</div>
        <p class="page-subtitle">إدارة مجموعات الضمانات للعمل الجماعي</p>
        
        <!-- Active Batches -->
        <section class="mb-5">
            <div class="section-header">
                <h2 class="section-title">دفعات مفتوحة</h2>
                <span class="badge badge-success">
                    <?= count($active) ?> دفعة
                </span>
            </div>
            
            <?php if (empty($active)): ?>
                <div class="empty-state">
                    لا توجد دفعات مفتوحة حالياً
                </div>
            <?php else: ?>
                <div class="batch-grid">
                    <?php foreach ($active as $batch): 
                        $isExcel = strpos($batch['import_source'], 'excel_') === 0;
                        $typeIcon = $isExcel ? '📄' : '📝';
                    ?>
                    <div class="batch-card active">
                        <div>
                            <div class="batch-card-header">
                                <h3 class="batch-card-title">
                                    <?= htmlspecialchars($batch['batch_name']) ?>
                                </h3>
                                <span class="batch-type-icon" title="<?= $isExcel ? 'ملف Excel' : 'إدخال يدووي/لصق' ?>">
                                    <?= $typeIcon ?>
                                </span>
                            </div>
                            <div class="batch-info">
                                <div class="batch-meta-item">
                                    <span class="icon-sm">📦</span>
                                    <span><?= $batch['guarantee_count'] ?> ضمان</span>
                                </div>
                                <div class="batch-meta-item">
                                    <span class="icon-sm">📅</span>
                                    <span><?= date('Y-m-d H:i', strtotime($batch['created_at'])) ?></span>
                                </div>
                                <?php if ($batch['batch_notes']): ?>
                                <p class="batch-notes">
                                    <?= htmlspecialchars(substr($batch['batch_notes'], 0, 40)) ?>
                                    <?= strlen($batch['batch_notes']) > 40 ? '...' : '' ?>
                                </p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <a href="/views/batch-detail.php?import_source=<?= urlencode($batch['import_source']) ?>" 
                           class="btn btn-primary btn-sm w-full">
                            فتح الدفعة
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        
        <!-- Completed Batches -->
        <section>
            <div class="section-header">
                <h2 class="section-title">دفعات مغلقة</h2>
                <span class="badge badge-neutral">
                    <?= count($completed) ?> دفعة
                </span>
            </div>
            
            <?php if (empty($completed)): ?>
                <div class="empty-state">
                    لا توجد دفعات مغلقة
                </div>
            <?php else: ?>
                <div class="batch-grid">
                    <?php foreach ($completed as $batch): 
                        $isExcel = strpos($batch['import_source'], 'excel_') === 0;
                        $typeIcon = $isExcel ? '📄' : '📝';
                    ?>
                    <div class="batch-card completed">
                        <div>
                            <div class="batch-card-header">
                                <h3 class="batch-card-title text-muted">
                                    <?= htmlspecialchars($batch['batch_name']) ?>
                                </h3>
                                <span class="batch-type-icon" style="filter: grayscale(1);">
                                    <?= $typeIcon ?>
                                </span>
                            </div>
                            <div class="batch-info">
                                <div class="batch-meta-item">
                                    <span>📦 <?= $batch['guarantee_count'] ?> ضمان</span>
                                </div>
                                <div class="batch-meta-item">
                                    <span>📅 <?= date('Y-m-d', strtotime($batch['created_at'])) ?></span>
                                </div>
                            </div>
                        </div>
                        <a href="/views/batch-detail.php?import_source=<?= urlencode($batch['import_source']) ?>" 
                           class="btn btn-secondary btn-sm w-full">
                            عرض التفاصيل
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        
        <!-- Back to home -->
        <div style="text-align: center;">
            <a href="/index.php" class="back-link">← العودة للصفحة الرئيسية</a>
        </div>
    </div>
</body>
</html>
