<?php
// Prevent caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

/**
 * BGL System v3.0 - Clean Rebuild
 * =====================================
 * 
 * Timeline-First approach with clean, maintainable code
 * Built from scratch following design system principles
 * 
 * @version 3.0.0
 * @date 2025-12-23
 * @author BGL Team
 */

// Load dependencies
require_once __DIR__ . '/app/Support/autoload.php';

use App\Support\Database;
use App\Support\Settings;
use App\Services\Learning\AuthorityFactory;
use App\Repositories\GuaranteeRepository;
use App\Repositories\GuaranteeDecisionRepository;
// LearningService removed - deprecated in Phase 4
use App\Repositories\SupplierRepository;
use App\Repositories\BankRepository;

header('Content-Type: text/html; charset=utf-8');

// Initialize database connection
$db = Database::connect();

// Get filter parameter for status filtering (Defined EARLY)
$statusFilter = $_GET['filter'] ?? 'all'; // all, ready, pending
$searchTerm = isset($_GET['search']) ? trim($_GET['search']) : null;

// Production Mode: Auto-exclude test data
$settings = Settings::getInstance();
if ($settings->isProductionMode()) {
    $_GET['exclude_test'] = '1'; // Force exclude test data
}

$guaranteeRepo = new GuaranteeRepository($db);
$decisionRepo = new GuaranteeDecisionRepository($db);

// ✅ PHASE 4: LearningService removed - using UnifiedLearningAuthority directly where needed
$supplierRepo = new SupplierRepository();

// Load Bank Repository
$bankRepo = new BankRepository();
$allBanks = $bankRepo->allNormalized(); // Get all banks for dropdown

// Get real data from database
$requestedId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$currentRecord = null;

if ($requestedId) {
    // Find the guarantee by ID directly
    $currentRecord = $guaranteeRepo->find($requestedId);
    
    // Production Mode: Skip test data guarantees
    if ($currentRecord && $settings->isProductionMode()) {
        $stmt = $db->prepare("SELECT is_test_data FROM guarantees WHERE id = ?");
        $stmt->execute([$requestedId]);
        $isTestData = $stmt->fetchColumn();
        if ($isTestData) {
            $currentRecord = null; // Treat as not found
        }
    }
}

// If not found or no ID specified, get first record matching the filter
if (!$currentRecord) {
    // Check for Jump To Index
    if (isset($_GET['jump_to_index'])) {
        $jumpIndex = (int)$_GET['jump_to_index'];
        $targetId = \App\Services\NavigationService::getIdByIndex($db, $jumpIndex, $statusFilter, $searchTerm);
        
        // Preserve current filters in redirect
        $queryParams = [];
        if ($statusFilter !== 'all') $queryParams['filter'] = $statusFilter;
        if ($searchTerm) $queryParams['search'] = $searchTerm;
        
        if ($targetId) {
            $queryParams['id'] = $targetId;
        } else {
            // Out of bounds? Fallback to first
        }
        
        $redirectUrl = 'index.php?' . http_build_query($queryParams);
        header("Location: $redirectUrl");
        exit;
    }

    // Build query based on status filter
    // ✅ SEARCH LOGIC: If search parameter exists, we ignore status filters temporarily or combine them
    
    $defaultRecordQuery = '
        SELECT g.id FROM guarantees g
        LEFT JOIN guarantee_decisions d ON d.guarantee_id = g.id
        LEFT JOIN suppliers s ON d.supplier_id = s.id
        WHERE 1=1
    ';
    $defaultRecordParams = [];
    
    // Production Mode: Exclude test data
    if ($settings->isProductionMode()) {
        $defaultRecordQuery .= ' AND (g.is_test_data = 0 OR g.is_test_data IS NULL)';
    }
    
    if ($searchTerm) {
        // Search Mode: Filter by term across multiple fields
        // We use JSON_EXTRACT for raw_data or assume columns exist if migrated
        // Assuming raw_data is a JSON column. If it's a string, we might need LIKE '%...%' on the whole column
        // But for better performance/accuracy, let's search raw_data field content
        
        $searchSafe = stripslashes($searchTerm);
        $searchAny = '%' . $searchSafe . '%';
        $searchSupplier = '%"supplier":"%' . $searchSafe . '%"%';
        $searchBank = '%"bank":"%' . $searchSafe . '%"%';
        $searchContract = '%"contract_number":"%' . $searchSafe . '%"%';
        
        // Search in: Guarantee Number, Supplier, Bank, Contract Number
        // Note: In SQLite/MySQL JSON handling might differ. Using flexible LIKE for now as broadest support
        $defaultRecordQuery .= " AND (
            g.guarantee_number LIKE :search_any OR
            g.raw_data LIKE :search_supplier OR
            g.raw_data LIKE :search_bank OR
            g.raw_data LIKE :search_contract OR
            g.raw_data LIKE :search_any OR
            s.official_name LIKE :search_any
        )";
        
        $defaultRecordParams = [
            'search_any' => $searchAny,
            'search_supplier' => $searchSupplier,
            'search_bank' => $searchBank,
            'search_contract' => $searchContract,
        ];
        
        // If specific status was requested WITH search, we can keep it, but usually search overrides
        // Let's fallback to 'all' behavior within search results unless specifically useful?
        // For simplicity: Search searches EVERYTHING (including released)
        
    } else {
        // Normal Filter Mode (No Search)
    
        // Apply filter conditions
        if ($statusFilter === 'released') {
            // Show only released
            $defaultRecordQuery .= ' AND d.is_locked = 1';
        } else {
            // Exclude released for other filters
            $defaultRecordQuery .= ' AND (d.is_locked IS NULL OR d.is_locked = 0)';
            
            // Apply specific status filter
            if ($statusFilter === 'ready') {
                $defaultRecordQuery .= ' AND d.status = "ready"';
            } elseif ($statusFilter === 'pending') {
                $defaultRecordQuery .= ' AND (d.id IS NULL OR d.status = "pending")';
            }
            // 'all' filter has no additional conditions
        }
    }
    
    $defaultRecordQuery .= ' ORDER BY g.id ASC LIMIT 1';
    
    $stmt = $db->prepare($defaultRecordQuery);
    $stmt->execute($defaultRecordParams);
    $firstId = $stmt->fetchColumn();
    if ($firstId) {
        $currentRecord = $guaranteeRepo->find($firstId);
    }
}

// Load test data info if available
$testDataInfo = null;
if ($currentRecord) {
    $stmt = $db->prepare("SELECT is_test_data, test_batch_id, test_note FROM guarantees WHERE id = ?");
    $stmt->execute([$currentRecord->id]);
    $testDataInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Production Mode: If this is test data, treat as not found
    if ($settings->isProductionMode() && !empty($testDataInfo['is_test_data'])) {
        $currentRecord = null;
        $testDataInfo = null;
    }
}

// Get navigation information using NavigationService
$navInfo = \App\Services\NavigationService::getNavigationInfo(
    $db,
    $currentRecord ? $currentRecord->id : null,
    $statusFilter,
    $searchTerm // ✅ Hand off search term to navigation
);

$totalRecords = $navInfo['totalRecords'];
$currentIndex = $navInfo['currentIndex'];
$prevId = $navInfo['prevId'];
$nextId = $navInfo['nextId'];

// Get import statistics (ready vs pending vs released)
// Note: Stats always show ALL counts regardless of filter
$importStats = \App\Services\StatsService::getImportStats($db);
// Update total to exclude released for display consistency with filters
$displayTotal = $importStats['ready'] + $importStats['pending'];


// If we have a record, prepare it
if ($currentRecord) {
    $raw = $currentRecord->rawData;
    
    $mockRecord = [
        'id' => $currentRecord->id,
        'session_id' => $raw['session_id'] ?? 0,
        'guarantee_number' => $currentRecord->guaranteeNumber ?? 'N/A',
        'supplier_name' => $raw['supplier'] ?? '',
        'bank_name' => $raw['bank'] ?? '',
        'amount' => is_numeric($raw['amount'] ?? 0) ? floatval($raw['amount'] ?? 0) : 0,
        'expiry_date' => $raw['expiry_date'] ?? '',
        'issue_date' => $raw['issue_date'] ?? '',
        'contract_number' => $raw['contract_number'] ?? '',
        'type' => $raw['type'] ?? null,
        'related_to' => $raw['related_to'] ?? 'contract',
        'status' => 'pending',
        
        // Excel Raw Data (for hints display)
        'excel_supplier' => htmlspecialchars($raw['supplier'] ?? '', ENT_QUOTES),
        'excel_bank' => htmlspecialchars($raw['bank'] ?? '', ENT_QUOTES),
        
        // Decision fields (will be populated if exists)
        'supplier_id' => null,
        'bank_id' => null,
        'decision_source' => null,
        'confidence_score' => null,
        'decided_at' => null,
        'decided_by' => null,
        'is_locked' => false,
        'locked_reason' => null,
        
        // Test data info
        'is_test_data' => $testDataInfo['is_test_data'] ?? 0,
        'test_batch_id' => $testDataInfo['test_batch_id'] ?? null,
        'test_note' => $testDataInfo['test_note'] ?? null
    ];
    
    // Get decision if exists - Load ALL decision data
    $decision = $decisionRepo->findByGuarantee($currentRecord->id);
    if ($decision) {
        // Map decision status to display status
        // Decision status: 'ready' or 'rejected'
        // Display status: 'ready' (has decision) or 'pending' (no decision)
        $mockRecord['status'] = $decision->status; // Respect actual DB status
        $mockRecord['supplier_id'] = $decision->supplierId;
        $mockRecord['bank_id'] = $decision->bankId;
        $mockRecord['decision_source'] = $decision->decisionSource;
        $mockRecord['confidence_score'] = $decision->confidenceScore;
        $mockRecord['decided_at'] = $decision->decidedAt;
        $mockRecord['decided_by'] = $decision->decidedBy;
        $mockRecord['is_locked'] = (bool)$decision->isLocked;
        $mockRecord['locked_reason'] = $decision->lockedReason;
        
        // Phase 4: Active Action State
        $mockRecord['active_action'] = $decision->activeAction;
        $mockRecord['active_action_set_at'] = $decision->activeActionSetAt;
        
        // If supplier_id exists, get the official supplier name
        if ($decision->supplierId) {
            try {
                $supplier = $supplierRepo->find($decision->supplierId);
                if ($supplier) {
                    // ✅ FIX: Properly override the Excel name with official Arabic name
                    $mockRecord['supplier_name'] = $supplier->officialName;
                    $mockRecord['supplier_english_name'] = $supplier->englishName ?? '';
                }
            } catch (\Exception $e) {
                // Keep Excel name if supplier not found
                error_log("Failed to load supplier {$decision->supplierId}: " . $e->getMessage());
                // DEBUG: Last code update - 2026-01-14 03:21
            }
        }
        
        // If bank_id exists, load bank details using Repository
        if ($decision->bankId) {
            $bank = $bankRepo->getBankDetails($decision->bankId);
            if ($bank) {
                $mockRecord['bank_name'] = $bank['official_name'];
                $mockRecord['bank_center'] = $bank['department'];
                $mockRecord['bank_po_box'] = $bank['po_box'];
                $mockRecord['bank_email'] = $bank['email'];
            }
        }
    }
    
    // === UI LOGIC PROJECTION: Status Reasons (Phase 1) ===
    // Get WHY status is what it is for user transparency
    $statusReasons = \App\Services\StatusEvaluator::getReasons(
        $mockRecord['supplier_id'] ?? null,
        $mockRecord['bank_id'] ?? null,
        [] // Conflicts will be added later in Phase 3
    );
    $mockRecord['status_reasons'] = $statusReasons;
    
    // Load timeline/history for this guarantee using TimelineDisplayService
    $mockTimeline = \App\Services\TimelineDisplayService::getEventsForDisplay(
        $db,
        $currentRecord->id,
        $currentRecord->importedAt,
        $currentRecord->importSource,
        $currentRecord->importedBy
    );
    
    
    // Load notes and attachments using GuaranteeDataService
    $relatedData = \App\Services\GuaranteeDataService::getRelatedData($db, $currentRecord->id);
    $mockNotes = $relatedData['notes'];
    $mockAttachments = $relatedData['attachments'];
    
    // ADR-007: Timeline is audit-only, not UI data source
    // active_action (from guarantee_decisions) is the display pointer
    $latestEventSubtype = null; // Removed Timeline read
} else {
    // No data in database - use empty state with no confusing values
    $mockRecord = [
        'id' => 0,
        'session_id' => 0,
        'guarantee_number' => 'لا توجد بيانات',
        'supplier_name' => '—',
        'bank_name' => '—',
        'amount' => 0,
        'expiry_date' => '—',
        'issue_date' => '—',
        'contract_number' => '—',
        'type' => '—',
        'status' => 'pending'
    ];
    
    $mockTimeline = [];
    $statusReasons = []; // Initialize empty array for loop
    $mockRecord['status_reasons'] = [];
}

// Get initial suggestions for the current record
$initialSupplierSuggestions = [];
if ($mockRecord['supplier_name']) {
    // ✅ PHASE 4: Using UnifiedLearningAuthority
    $authority = AuthorityFactory::create();
    $suggestionDTOs = $authority->getSuggestions($mockRecord['supplier_name']);
    
    // Convert DTOs to legacy format for compatibility
    $initialSupplierSuggestions = array_map(function($dto) {
        return [
            'id' => $dto->supplier_id,
            'official_name' => $dto->official_name,
            'score' => $dto->confidence,
            'usage_count' => $dto->usage_count
        ];
    }, $suggestionDTOs);
}

// Map suggestions to frontend format
$formattedSuppliers = array_map(function($s) {
    return [
        'id' => $s['id'],
        'name' => $s['official_name'],
        'score' => $s['score'],
        'usage_count' => $s['usage_count'] ?? 0 
    ];
}, $initialSupplierSuggestions);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BGL System v3.0</title>
    
    <!-- ✅ COMPLIANCE: Server-Driven Partials (Hidden) -->
    <?php include __DIR__ . '/partials/confirm-modal.php'; ?>
    
    <div id="preview-no-action-template" style="display:none">
        <?php include __DIR__ . '/partials/preview-placeholder.php'; ?>
    </div>

    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Letter Preview Styles (Classic Theme) -->
    <link rel="stylesheet" href="assets/css/letter.css">
    
    <!-- Pure Vanilla JavaScript - No External Dependencies -->
    <script src="public/js/convert-to-real.js"></script>
    
    <!-- Main Application Styles -->
    <link rel="stylesheet" href="public/css/index-main.css">
    <!-- Header Override to match unified header styling (same as batches page) -->
    <style>
        :root { --height-top-bar: 64px; }
        .top-bar {
            height: var(--height-top-bar);
            background: var(--bg-card);
            border-bottom: 1px solid var(--border-primary);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 var(--space-lg);
            box-shadow: var(--shadow-sm);
            flex-shrink: 0;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: var(--font-weight-black);
            font-size: var(--font-size-xl);
            color: var(--text-primary);
        }
        .brand-icon {
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, var(--accent-primary), #8b5cf6);
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 16px;
        }
        .global-actions {
            display: flex;
            gap: var(--space-sm);
        }
        .btn-global {
            display: inline-flex;
            align-items: center;
            gap: var(--space-xs);
            padding: var(--space-sm) var(--space-md);
            font-size: var(--font-size-sm);
            font-weight: var(--font-weight-medium);
            color: var(--text-secondary);
            background: transparent;
            border: 1px solid transparent;
            border-radius: var(--radius-md);
            text-decoration: none;
            transition: all var(--transition-base);
        }
        .btn-global:hover {
            background: var(--bg-hover);
            color: var(--text-primary);
        }
        .btn-global.active {
            background: var(--accent-primary-light);
            color: var(--accent-primary);
            border-color: var(--accent-primary);
            font-weight: var(--font-weight-semibold);
        }
    </style>

</head>
<body>
    
    <!-- Hidden File Input for Excel Import -->
    <input type="file" id="hiddenFileInput" accept=".xlsx,.xls" style="display: none;">
    
    <!-- Unified Header -->
    <?php include __DIR__ . '/partials/unified-header.php'; ?>

    <!-- Main Container -->
    <div class="app-container">
        
        <!-- Center Section -->
        <div class="center-section">
            
            <!-- Record Header -->
            <header class="record-header">
                <div class="record-title">
                    <h1>ضمان رقم <span id="guarantee-number-display"><?= htmlspecialchars($mockRecord['guarantee_number']) ?></span></h1>
                    <?php if ($currentRecord): ?>
                        <?php
                            // Display status badge based on actual status
                            if ($mockRecord['status'] === 'released') {
                                $statusClass = 'badge-released';
                                $statusText = 'مُفرج عنه';
                            } elseif ($mockRecord['status'] === 'ready') {
                                $statusClass = 'badge-approved';
                                $statusText = 'جاهز';
                            } else {
                                $statusClass = 'badge-pending';
                                $statusText = 'يحتاج قرار';
                            }
                        ?>
                        <span class="badge <?= $statusClass ?>"><?= $statusText ?></span>
                    <?php endif; ?>
                </div>
                
                <!-- Navigation Controls -->
                <div class="navigation-controls" style="display: flex; align-items: center; gap: 16px;">
                    <button class="btn btn-ghost btn-sm" 
                            onclick="window.location.href='?id=<?= $prevId ?? '' ?>&filter=<?= $statusFilter ?>&search=<?= urlencode($searchTerm ?? '') ?>'"
                            <?= !$prevId ? 'disabled style="opacity:0.3;cursor:not-allowed;"' : '' ?>>
                        ← السابق
                    </button>
                    
                    <div class="record-position" style="display: flex; align-items: center; gap: 6px; font-size: 14px; font-weight: 600; color: var(--text-secondary); white-space: nowrap;">
                        <form action="index.php" method="GET" style="display: inline-flex; align-items: center; margin: 0;">
                            <?php if($statusFilter !== 'all'): ?><input type="hidden" name="filter" value="<?= $statusFilter ?>"><?php endif; ?>
                            <?php if($searchTerm): ?><input type="hidden" name="search" value="<?= htmlspecialchars($searchTerm) ?>"><?php endif; ?>
                            <input type="number" 
                                   name="jump_to_index" 
                                   value="<?= $currentIndex ?>" 
                                   min="1" 
                                   max="<?= $totalRecords ?>"
                                   style="width: 45px; text-align: center; border: 1px solid #d1d5db; border-radius: 4px; padding: 2px 0; font-weight: bold; font-family: inherit; -moz-appearance: textfield; appearance: textfield;"
                                   onfocus="this.select()"
                            >
                        </form>
                        <span>/ <?= $totalRecords ?></span>
                    </div>
                    
                    <button class="btn btn-ghost btn-sm" 
                            onclick="window.location.href='?id=<?= $nextId ?? '' ?>&filter=<?= $statusFilter ?>&search=<?= urlencode($searchTerm ?? '') ?>'"
                            <?= !$nextId ? 'disabled style="opacity:0.3;cursor:not-allowed;"' : '' ?>>
                        التالي →
                    </button>
                </div>
                


                    









            </header>

            <!-- Content Wrapper -->
            <div class="content-wrapper">
                
                <!-- Timeline Panel - Using Partial -->
                <?php 
                $timeline = $mockTimeline;
                require __DIR__ . '/partials/timeline-section.php'; 
                ?>

                <!-- Main Content -->
                <main class="main-content">
                    <!-- ✅ HISTORICAL BANNER: Server-driven partial (Hidden by default) -->
        <div id="historical-banner-container" style="display:none">
            <?php include __DIR__ . '/partials/historical-banner.php'; ?>
        </div>

        <!-- Test Data Banner (Hidden in Production Mode) -->
        <?php if (!empty($mockRecord['is_test_data']) && !$settings->isProductionMode()): ?>
        <div style="display: flex; align-items: center; gap: 12px; background: #fef3c7; border: 2px solid #f59e0b; border-radius: 8px; padding: 14px 18px; margin-bottom: 16px;">
            <div style="font-size: 24px;">🧪</div>
            <div style="flex: 1;">
                <div style="font-weight: 700; color: #92400e; font-size: 15px;">ضمان تجريبي - لأغراض الاختبار فقط</div>
                <div style="font-size: 13px; color: #78350f; margin-top: 4px;">
                    هذه البيانات لن تؤثر على الإحصائيات أو نظام التعلم
                    <?php if (!empty($mockRecord['test_batch_id'])): ?>
                        • الدفعة: <strong><?= htmlspecialchars($mockRecord['test_batch_id']) ?></strong>
                    <?php endif; ?>
                    <?php if (!empty($mockRecord['test_note'])): ?>
                        <br><?= htmlspecialchars($mockRecord['test_note']) ?>
                    <?php endif; ?>
                </div>
            </div>
            <a href="#" onclick="convertToReal(<?= $mockRecord['id'] ?>); return false;" 
               style="padding: 6px 12px; background: white; border: 1px solid #f59e0b; border-radius: 6px; font-size: 13px; color: #92400e; text-decoration: none; white-space: nowrap; font-weight: 600;"
               onmouseover="this.style.background='#fffbeb'"
               onmouseout="this.style.background='white'">
                تحويل إلى حقيقي
            </a>
        </div>
        <?php endif; ?>

        <!-- Decision Cards -->
                    <div class="decision-card">
                        
                        <?php
                        // Prepare data for record-form partial
                        $record = $mockRecord;
                        $guarantee = $currentRecord; // For rawData access
                        $supplierMatch = [
                            'suggestions' => $formattedSuppliers,
                            'score' => !empty($formattedSuppliers) ? $formattedSuppliers[0]['score'] : 0
                        ];
                        
                        // Load banks - now using real data!
                        $banks = $allBanks;
                        
                        // Try to find matching bank using intelligent detection
                        $bankMatch = [];
                        if (!empty($mockRecord['bank_id'])) {
                            // If decision has bank_id, use it
                            foreach ($allBanks as $bank) {
                                if ($bank['id'] == $mockRecord['bank_id']) {
                                    $bankMatch = [
                                        'id' => $bank['id'],
                                        'name' => $bank['official_name'],
                                        'score' => 100
                                    ];
                                    break;
                                }
                            }
                        } else {
                            // Use direct bank matching with BankNormalizer
                            $excelBank = trim($mockRecord['excel_bank'] ?? '');
                            if ($excelBank) {
                                try {
                                    $normalized = \App\Support\BankNormalizer::normalize($excelBank);
                                    $stmt = $db->prepare("
                                        SELECT b.id, b.arabic_name as name
                                        FROM banks b
                                        JOIN bank_alternative_names a ON b.id = a.bank_id
                                        WHERE a.normalized_name = ?
                                        LIMIT 1
                                    ");
                                    $stmt->execute([$normalized]);
                                    $bank = $stmt->fetch(PDO::FETCH_ASSOC);
                                    
                                    if ($bank) {
                                        $bankMatch = [
                                            'id' => $bank['id'],
                                            'name' => $bank['name'],
                                            'score' => 100 // Perfect match - hide other banks
                                        ];
                                    }
                                } catch (\Exception $e) {
                                    // Fallback if matching fails
                                    error_log("Bank matching error: " . $e->getMessage());
                                }
                            }
                        }
                        
                        $isHistorical = false;
                        
                        // Include the Alpine-free record form partial
                        require __DIR__ . '/partials/record-form.php';
                        ?>
                    </div>

                    <!-- Preview Section - Show for ready and released guarantees -->
                    <?php if ($mockRecord['status'] === 'ready' || $mockRecord['status'] === 'released'): ?>
                        <div id="preview-section">
                            <?php 
                            $showPlaceholder = true;
                            require __DIR__ . '/partials/letter-renderer.php'; 
                            ?>
                        </div>
                    <?php endif; ?>

                </main>

            </div>
        </div>

        <!-- Sidebar (Left) -->
        <aside class="sidebar">
            
            <!-- Input Actions (New Proposal) -->
            <div class="input-toolbar">
                <!-- Import Stats (Interactive Filter) -->
                <?php if (isset($importStats) && ($importStats['total'] > 0)): ?>
                <div style="font-size: 11px; margin-bottom: 10px; display: flex; gap: 16px; align-items: center;">
                    <a href="/?filter=all" 
                       style="display: flex; align-items: center; gap: 4px; padding: 4px 8px; border-radius: 4px; text-decoration: none; transition: all 0.2s; <?= $statusFilter === 'all' ? 'background: #e0e7ff; font-weight: 600;' : '' ?>"
                       onmouseover="if('<?= $statusFilter ?>' !== 'all') this.style.background='#f1f5f9'"
                       onmouseout="if('<?= $statusFilter ?>' !== 'all') this.style.background='transparent'">
                        <span style="color: #334155;">📊 <?= $displayTotal ?? $importStats['total'] ?></span>
                    </a>
                    <a href="/?filter=ready" 
                       style="display: flex; align-items: center; gap: 4px; padding: 4px 8px; border-radius: 4px; text-decoration: none; transition: all 0.2s; <?= $statusFilter === 'ready' ? 'background: #dcfce7; font-weight: 600;' : '' ?>"
                       onmouseover="if('<?= $statusFilter ?>' !== 'ready') this.style.background='#f1f5f9'"
                       onmouseout="if('<?= $statusFilter ?>' !== 'ready') this.style.background='transparent'">
                        <span style="color: #059669;">✅ <?= $importStats['ready'] ?? 0 ?></span>
                    </a>
                    <a href="/?filter=pending" 
                       style="display: flex; align-items: center; gap: 4px; padding: 4px 8px; border-radius: 4px; text-decoration: none; transition: all 0.2s; <?= $statusFilter === 'pending' ? 'background: #fef3c7; font-weight: 600;' : '' ?>"
                       onmouseover="if('<?= $statusFilter ?>' !== 'pending') this.style.background='#f1f5f9'"
                       onmouseout="if('<?= $statusFilter ?>' !== 'pending') this.style.background='transparent'">
                        <span style="color: #d97706;">⚠️ <?= $importStats['pending'] ?? 0 ?></span>
                    </a>
                    <a href="/?filter=released" 
                       style="display: flex; align-items: center; gap: 4px; padding: 4px 8px; border-radius: 4px; text-decoration: none; transition: all 0.2s; <?= $statusFilter === 'released' ? 'background: #fee2e2; font-weight: 600;' : '' ?>"
                       onmouseover="if('<?= $statusFilter ?>' !== 'released') this.style.background='#f1f5f9'"
                       onmouseout="if('<?= $statusFilter ?>' !== 'released') this.style.background='transparent'">
                        <span style="color: #dc2626;">🔓 <?= $importStats['released'] ?? 0 ?></span>
                    </a>
                </div>
                
                <!-- ✅ NEW: Test Data Filter Toggle (Phase 1) -->
                <?php 
                $settings = \App\Support\Settings::getInstance();
                if (!$settings->isProductionMode()): 
                ?>
                <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #e5e7eb;">
                    <div style="font-size: 11px; font-weight: 600; color: #6b7280; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px;">بيانات الاختبار</div>
                    <a href="<?php 
                        $currentParams = $_GET;
                        if (isset($currentParams['include_test_data'])) {
                            unset($currentParams['include_test_data']);
                            echo '/?' . http_build_query($currentParams);
                        } else {
                            $currentParams['include_test_data'] = '1';
                            echo '/?' . http_build_query($currentParams);
                        }
                    ?>" 
                       style="display: flex; align-items: center; gap: 8px; padding: 6px 8px; border-radius: 4px; text-decoration: none; transition: all 0.2s; <?= isset($_GET['include_test_data']) ? 'background: #fef3c7; font-weight: 600;' : '' ?>"
                       onmouseover="if(!<?= isset($_GET['include_test_data']) ? 'true' : 'false' ?>) this.style.background='#f1f5f9'"
                       onmouseout="if(!<?= isset($_GET['include_test_data']) ? 'true' : 'false' ?>) this.style.background='transparent'">
                        <span style="font-size: 16px;"><?= isset($_GET['include_test_data']) ? '✅' : '🧪' ?></span>
                        <span style="flex: 1; font-size: 13px; color: <?= isset($_GET['include_test_data']) ? '#92400e' : '#6b7280' ?>;">
                            <?= isset($_GET['include_test_data']) ? 'إخفاء التجريبية' : 'عرض التجريبية' ?>
                        </span>
                    </a>
                </div>
                <?php endif; ?>
                
                <?php else: ?>
                <div class="toolbar-label">إدخال جديد</div>
                <?php endif; ?>
                <div class="toolbar-actions">
                    <button class="btn-input" title="إدخال يدوي" data-action="showManualInput">
                        <span>&#x270D;</span>
                        <span>يدوي</span>
                    </button>
                    <button class="btn-input" title="رفع ملف Excel" data-action="showImportModal">
                        <span>&#x1F4CA;</span>
                        <span>ملف</span>
                    </button>
                    <button class="btn-input" title="لصق بيانات" data-action="showPasteModal">
                        <span>&#x1F4CB;</span>
                        <span>لصق</span>
                    </button>
                </div>
                <!-- Hidden Input for Import -->
                <input type="file" id="hiddenFileInput" style="display: none;" accept=".xlsx,.xls,.csv" />
            </div>

            <!-- Progress -->
            <div class="progress-container">
                <div class="progress-bar">
                    <div class="progress-fill" :style="`width: ${progress}%`"></div>
                </div>
                <div class="progress-text">
                    <span>سجل <span x-text="currentIndex"></span> من <span x-text="totalRecords"></span></span>
                    <span class="progress-percent" x-text="`${progress}%`"></span>
                </div>
            </div>
            
            <!-- Sidebar Body -->
            <div class="sidebar-body">
                <!-- Notes Section -->
                <div class="sidebar-section" id="notesSection">
                    <div class="sidebar-section-title">
                        📝 الملاحظات
                    </div>
                    
                    <!-- Notes List -->
                    <div id="notesList">
                        <?php if (empty($mockNotes)): ?>
                            <div id="emptyNotesMessage" style="text-align: center; color: var(--text-light); font-size: var(--font-size-sm); padding: 16px 0;">
                                لا توجد ملاحظات
                            </div>
                        <?php else: ?>
                            <?php foreach ($mockNotes as $note): ?>
                                <div class="note-item">
                                    <div class="note-header">
                                        <span class="note-author"><?= htmlspecialchars($note['created_by'] ?? 'مستخدم') ?></span>
                                        <span class="note-time"><?= substr($note['created_at'] ?? '', 0, 16) ?></span>
                                    </div>
                                    <div class="note-content"><?= htmlspecialchars($note['content'] ?? '') ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Note Input Box -->
                    <div id="noteInputBox" class="note-input-box" style="display: none;">
                        <textarea id="noteTextarea" placeholder="أضف ملاحظة..."></textarea>
                        <div class="note-input-actions">
                            <button onclick="cancelNote()" class="note-cancel-btn">
                                إلغاء
                            </button>
                            <button onclick="saveNote()" class="note-save-btn">
                                حفظ
                            </button>
                        </div>
                    </div>
                    
                    <!-- Add Note Button -->
                    <button id="addNoteBtn" onclick="showNoteInput()" class="add-note-btn">
                        + إضافة ملاحظة
                    </button>
                </div>
                
                <!-- Attachments Section -->
                <div class="sidebar-section" style="margin-top: 24px;">
                    <div class="sidebar-section-title">
                        📎 المرفقات
                    </div>
                    
                    <!-- Upload Button -->
                    <label class="add-note-btn" style="cursor: pointer; display: inline-block; width: 100%; text-align: center;">
                        <input type="file" id="fileInput" style="display: none;" onchange="uploadFile(event)">
                        + رفع ملف
                    </label>
                    
                    <!-- Attachments List -->
                    <div id="attachmentsList">
                        <?php if (empty($mockAttachments)): ?>
                            <div id="emptyAttachmentsMessage" style="text-align: center; color: var(--text-light); font-size: var(--font-size-sm); padding: 16px 0;">
                                لا توجد مرفقات
                            </div>
                        <?php else: ?>
                            <?php foreach ($mockAttachments as $file): ?>
                                <div class="note-item" style="display: flex; align-items: center; gap: 12px;">
                                    <div style="font-size: 24px;">📄</div>
                                    <div style="flex: 1; min-width: 0;">
                                        <div class="note-content" style="margin: 0; font-weight: 500;"><?= htmlspecialchars($file['file_name'] ?? 'ملف') ?></div>
                                        <div class="note-time"><?= substr($file['created_at'] ?? '', 0, 10) ?></div>
                                    </div>
                                    <a href="/V3/storage/<?= htmlspecialchars($file['file_path'] ?? '') ?>" 
                                       target="_blank" 
                                       style="color: var(--text-light); text-decoration: none; font-size: 18px; padding: 4px;"
                                       title="تحميل">
                                        ⬇️
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </aside>

    </div>

    <!-- Modals - Using existing partials -->
    <?php require __DIR__ . '/partials/manual-entry-modal.php'; ?>
    <?php require __DIR__ . '/partials/paste-modal.php'; ?>
    <?php require __DIR__ . '/partials/excel-import-modal.php'; ?>

    <?php if (!empty($mockRecord['is_locked'])): ?>
    <!-- Released Guarantee: Read-Only Mode -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Show released banner
            const banner = document.createElement('div');
            banner.id = 'released-banner';
            banner.innerHTML = `
                <div style="display: flex; align-items: center; justify-content: space-between; 
                            background: #fee2e2; border: 2px solid #ef4444; border-radius: 8px; 
                            padding: 12px 16px; margin-bottom: 16px;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span style="font-size: 20px;">🔒</span>
                        <div>
                            <div style="font-weight: 600; color: #991b1b;">ضمان مُفرج عنه</div>
                            <div style="font-size: 12px; color: #7f1d1d;">هذا الضمان خارج التدفق التشغيلي - للعرض فقط</div>
                        </div>
                    </div>
                </div>
            `;
            
            const recordForm = document.querySelector('.decision-card, .card');
            if (recordForm && recordForm.parentNode) {
                recordForm.parentNode.insertBefore(banner, recordForm);
            }
            
            // Disable all inputs
            const inputs = document.querySelectorAll('#supplierInput, #bankNameInput, #bankSelect');
            inputs.forEach(input => {
                input.disabled = true;
                input.style.opacity = '0.7';
                input.style.cursor = 'not-allowed';
            });
            
            // Disable action buttons
            const buttons = document.querySelectorAll('[data-action="extend"], [data-action="reduce"], [data-action="release"], [data-action="save-next"], [data-action="saveAndNext"]');
            buttons.forEach(btn => {
                btn.disabled = true;
                btn.style.opacity = '0.5';
                btn.style.cursor = 'not-allowed';
            });
            
            // Hide suggestions
            const suggestions = document.getElementById('supplier-suggestions');
            if (suggestions) suggestions.style.display = 'none';
            
            BglLogger.debug('🔒 Released guarantee - Read-only mode enabled');
        });
    </script>
    <?php endif; ?>

    <script>
        // Toast notification system
        function showToast(message, type = 'info') {
            const toast = document.createElement('div');
            toast.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: ${type === 'error' ? '#dc2626' : type === 'success' ? '#16a34a' : '#3b82f6'};
                color: white;
                padding: 16px 24px;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                z-index: 10000;
                font-family: 'Tajawal', sans-serif;
                font-size: 14px;
                max-width: 400px;
                animation: slideIn 0.3s ease;
            `;
            toast.textContent = message;
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.style.animation = 'slideOut 0.3s ease';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }
        
        // Notes functionality - Vanilla JS
        function showNoteInput() {
            document.getElementById('noteInputBox').style.display = 'block';
            document.getElementById('addNoteBtn').style.display = 'none';
            document.getElementById('noteTextarea').focus();
        }
        
        function cancelNote() {
            document.getElementById('noteInputBox').style.display = 'none';
            document.getElementById('addNoteBtn').style.display = 'block';
            document.getElementById('noteTextarea').value = '';
        }
        
        async function saveNote() {
            const content = document.getElementById('noteTextarea').value.trim();
            if (!content) return;
            
            try {
                const res = await fetch('api/save-note.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        guarantee_id: <?= $mockRecord['id'] ?? 0 ?>,
                        content: content
                    })
                });
                const data = await res.json();
                if (data.success) {
                    showToast('تم حفظ الملاحظة بنجاح', 'success');
                    // Reload page to show new note
                    setTimeout(() => location.reload(), 500);
                } else {
                    showToast('فشل حفظ الملاحظة: ' + (data.error || 'خطأ غير معروف'), 'error');
                }
            } catch(e) { 
                console.error('Error saving note:', e);
                showToast('حدث خطأ أثناء حفظ الملاحظة', 'error');
            }
        }
        
        // Attachments functionality
        async function uploadFile(event) {
            const file = event.target.files[0];
            if (!file) return;
            
            const formData = new FormData();
            formData.append('file', file);
            formData.append('guarantee_id', <?= $mockRecord['id'] ?? 0 ?>);
            
            try {
                const res = await fetch('api/upload-attachment.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();
                if (data.success) {
                    showToast('تم رفع الملف بنجاح', 'success');
                    // Reload page to show new attachment
                    setTimeout(() => location.reload(), 500);
                } else {
                    showToast('فشل رفع الملف: ' + (data.error || 'خطأ غير معروف'), 'error');
                }
            } catch(err) {
                console.error('Error uploading file:', err);
                showToast('حدث خطأ أثناء رفع الملف', 'error');
            }
            event.target.value = ''; // Reset input
        }
        
        // Add CSS animations
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideIn {
                from { transform: translateX(400px); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
            @keyframes slideOut {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(400px); opacity: 0; }
            }
        `;
        document.head.appendChild(style);
        
        
        // ========================
        // Preview Formatting moved to preview-formatter.js
        // ========================
        
        // Apply formatting on load - Preview is always visible
        document.addEventListener('DOMContentLoaded', function() {
            const previewSection = document.getElementById('preview-section');
            if (previewSection) {
                // Permanently add letter-preview class for styling
                previewSection.classList.add('letter-preview');

                // Execute conversions using centralized PreviewFormatter
                const runConversions = () => {
                    if (window.PreviewFormatter) {
                        window.PreviewFormatter.applyFormatting();
                    }
                };
                
                runConversions();
                // Run again after a slight delay to catch any dynamic updates
                setTimeout(runConversions, 500);
            }
        });
            

    </script>

    
    <script src="/public/js/pilot-auto-load.js?v=<?= time() ?>"></script>

    
    <!-- ✅ UX UNIFICATION: Old Level B handler and modal removed -->
    <!-- Level B handler disabled by UX_UNIFICATION_ENABLED flag -->
    <!-- Modal no longer needed - Selection IS the confirmation -->
    
    <script src="/public/js/preview-formatter.js?v=<?= time() ?>"></script>
    <script src="/public/js/main.js?v=<?= time() ?>"></script>
    <script src="/public/js/input-modals.controller.js?v=<?= time() ?>"></script>
    <script src="/public/js/timeline.controller.js?v=<?= time() ?>"></script>
    <script src="/public/js/records.controller.js?v=<?= time() ?>"></script>
</body>
</html>
