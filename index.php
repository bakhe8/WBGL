<?php
require_once __DIR__ . '/app/Support/autoload.php';

use App\Support\AuthService;

// ✅ STRICT AUTH: Redirect to login if not authenticated
if (!AuthService::isLoggedIn()) {
    header('Location: /views/login.php');
    exit;
}

// Prevent caching
header('Cache-Control:' . ' ' . implode(',', ['no-store', 'no-cache', 'must-revalidate', 'max-age=0']));
header('Cache-Control:' . ' ' . implode(',', ['post-check=0', 'pre-check=0']), false);
header('Pragma:' . ' ' . 'no-cache');

/**
 * WBGL System v3.0 - Clean Rebuild
 * =====================================
 *
 * Timeline-First approach with clean, maintainable code
 * Built from scratch following design system principles
 *
 * @version 3.0.0
 * @date 2025-12-23
 * @author WBGL Team
 */

// Load dependencies
require_once __DIR__ . '/app/Support/autoload.php';

use App\Support\Database;
use App\Support\DirectionResolver;
use App\Support\Guard;
use App\Support\LocaleResolver;
use App\Support\Settings;
use App\Support\TestDataVisibility;
use App\Services\ActionabilityPolicyService;
use App\Services\NotificationService;
use App\Services\UiSurfacePolicyService;
use App\Services\Learning\AuthorityFactory;
use App\Repositories\GuaranteeRepository;
use App\Repositories\GuaranteeDecisionRepository;
// LearningService removed - deprecated in Phase 4
use App\Repositories\SupplierRepository;
use App\Repositories\BankRepository;
use App\Repositories\RoleRepository;

header('Content-Type:' . ' ' . implode(';', ['text/html', 'charset=utf-8']));

// Initialize database connection
$db = Database::connect();

// Effective permissions and policy defaults.
$effectivePermissions = Guard::permissions();
$permissionCanViewTimeline = Guard::has('timeline_view');
$permissionCanViewNotes = Guard::has('notes_view');
$permissionCanCreateNotes = Guard::has('notes_create');
$permissionCanViewAttachments = Guard::has('attachments_view');
$permissionCanUploadAttachments = Guard::has('attachments_upload');
$permissionCanReopenGuarantee = Guard::has('reopen_guarantee');

$recordPolicy = [
    'visible' => false,
    'actionable' => false,
    'executable' => false,
    'reasons' => ['NO_RECORD'],
];
$recordSurface = [
    'can_view_record' => false,
    'can_view_identity' => false,
    'can_view_timeline' => false,
    'can_view_notes' => false,
    'can_create_notes' => false,
    'can_view_attachments' => false,
    'can_upload_attachments' => false,
    'can_execute_actions' => false,
    'can_view_preview' => false,
];
$canViewTimeline = false;
$canViewNotes = false;
$canCreateNotes = false;
$canViewAttachments = false;
$canUploadAttachments = false;
$notesPermissionHintKey = 'index.notes.no_permission_add';
$attachmentsPermissionHintKey = 'index.attachments.no_permission_upload';

// Get filter parameter for status filtering (Defined EARLY)
$requestedStatusFilter = isset($_GET['filter']) ? trim((string)$_GET['filter']) : '';
$statusFilter = $requestedStatusFilter !== '' ? $requestedStatusFilter : 'all'; // all, ready, pending
$stageFilter = $_GET['stage'] ?? null;    // specific workflow stage
$searchTerm = isset($_GET['search']) ? trim($_GET['search']) : null;
$currentUserRoleSlug = '';
$isTaskOnlyUser = false;
$showFiltersForCurrentUser = false;
$hasFullFilterException = Guard::has('ui_full_filters_view');

// Default landing behavior:
// Only data_entry and developer can use broad filters.
// All other roles are task-only (actionable scope).
{
    $currentUserForDefault = AuthService::getCurrentUser();
    if ($currentUserForDefault && $currentUserForDefault->roleId !== null) {
        try {
            $roleRepo = new RoleRepository($db);
            $role = $roleRepo->find((int)$currentUserForDefault->roleId);
            $currentUserRoleSlug = trim((string)($role->slug ?? ''));
        } catch (\Throwable) {
            $currentUserRoleSlug = '';
        }
    }

    $showFiltersForCurrentUser = in_array($currentUserRoleSlug, ['data_entry', 'developer'], true)
        || $hasFullFilterException;
    $isTaskOnlyUser = !$showFiltersForCurrentUser;
    if ($isTaskOnlyUser) {
        // Enforced at UI level too: task-only roles are always actionable.
        $statusFilter = 'actionable';
    } elseif ($requestedStatusFilter === '') {
        $statusFilter = 'all';
    }
}

// Production Mode: Auto-exclude test data
$settings = Settings::getInstance();
$notificationUiLimit = max(10, min(200, (int)$settings->get('NOTIFICATION_UI_MAX_ITEMS', 40)));
$includeTestData = TestDataVisibility::includeTestData($settings, $_GET);
$localeInfo = LocaleResolver::resolve(
    AuthService::getCurrentUser(),
    $settings,
    $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null
);
$indexLocaleCode = (string)($localeInfo['locale'] ?? 'ar');
$directionInfo = DirectionResolver::resolve(
    $indexLocaleCode,
    AuthService::getCurrentUser()?->preferredDirection ?? 'auto',
    (string)$settings->get('DEFAULT_DIRECTION', 'auto')
);
$indexPageDirection = (string)($directionInfo['direction'] ?? ($indexLocaleCode === 'ar' ? 'rtl' : 'ltr'));
$indexLocalePrimary = [];
$indexLocaleFallback = [];
$indexPrimaryPath = __DIR__ . '/public/locales/' . $indexLocaleCode . '/index.json';
$indexFallbackPath = __DIR__ . '/public/locales/ar/index.json';
if (is_file($indexPrimaryPath)) {
    $decodedLocale = json_decode((string)file_get_contents($indexPrimaryPath), true);
    if (is_array($decodedLocale)) {
        $indexLocalePrimary = $decodedLocale;
    }
}
if (is_file($indexFallbackPath)) {
    $decodedLocale = json_decode((string)file_get_contents($indexFallbackPath), true);
    if (is_array($decodedLocale)) {
        $indexLocaleFallback = $decodedLocale;
    }
}
$indexTodoArPrefix = '__' . 'TODO_AR__';
$indexTodoEnPrefix = '__' . 'TODO_EN__';
$indexIsPlaceholder = static function ($value) use ($indexTodoArPrefix, $indexTodoEnPrefix): bool {
    if (!is_string($value)) {
        return false;
    }
    $trimmed = trim($value);
    return str_starts_with($trimmed, $indexTodoArPrefix) || str_starts_with($trimmed, $indexTodoEnPrefix);
};
$indexT = static function (string $key, ?string $fallback = null) use ($indexLocalePrimary, $indexLocaleFallback, $indexIsPlaceholder): string {
    $value = $indexLocalePrimary[$key] ?? null;
    if (!is_string($value) || $indexIsPlaceholder($value)) {
        $value = $indexLocaleFallback[$key] ?? null;
    }
    if (!is_string($value) || $indexIsPlaceholder($value)) {
        $value = $fallback ?? $key;
    }
    return $value;
};
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
$mockNotes = [];
$mockAttachments = [];

if ($requestedId) {
    // Minimal-load gate: validate forced id belongs to current scope first.
    $idMatchesScope = \App\Services\NavigationService::isIdInFilter(
        $db,
        $requestedId,
        $statusFilter,
        $searchTerm,
        $stageFilter,
        $includeTestData
    );

    if ($idMatchesScope) {
        // Load full record only after scope check passes.
        $currentRecord = $guaranteeRepo->find($requestedId);
    }
}

// If not found or no ID specified, get first record matching the filter
if (!$currentRecord) {
    // Check for Jump To Index
    if (isset($_GET['jump_to_index'])) {
        $jumpIndex = (int)$_GET['jump_to_index'];
        $targetId = \App\Services\NavigationService::getIdByIndex($db, $jumpIndex, $statusFilter, $searchTerm, $stageFilter, $includeTestData);

        // Preserve current filters in redirect
        $queryParams = [];
        if ($statusFilter !== 'all') $queryParams['filter'] = $statusFilter;
        if ($stageFilter) $queryParams['stage'] = $stageFilter;
        if ($searchTerm) $queryParams['search'] = $searchTerm;
        $queryParams = TestDataVisibility::withQueryFlag($queryParams, $includeTestData);

        if ($targetId) {
            $queryParams['id'] = $targetId;
        } else {
            // Out of bounds? Fallback to first
        }

        $redirectUrl = 'index.php?' . http_build_query($queryParams);
        header("Location: $redirectUrl");
        exit;
    }

    // Single source of truth: pick first record from the same scoped predicate
    // used by count/list/navigation.
    $firstId = \App\Services\NavigationService::getIdByIndex(
        $db,
        1,
        $statusFilter,
        $searchTerm,
        $stageFilter,
        $includeTestData
    );
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
    $searchTerm, // ✅ Hand off search term to navigation
    $stageFilter,
    $includeTestData
);

$totalRecords = $navInfo['totalRecords'];
$currentIndex = $navInfo['currentIndex'];
$prevId = $navInfo['prevId'];
$nextId = $navInfo['nextId'];

if ($totalRecords === 0) {
    $currentIndex = 0;
    $prevId = null;
    $nextId = null;
    // Anti-leak: never keep stale record payload when current scoped list is empty.
    $currentRecord = null;
}

$buildIndexHref = static function (array $params): string {
    $query = http_build_query($params);
    return $query === '' ? '/index.php' : '/index.php?' . $query;
};
$baseNavParams = [];
if ($statusFilter !== 'all') {
    $baseNavParams['filter'] = $statusFilter;
}
if ($stageFilter !== null && $stageFilter !== '') {
    $baseNavParams['stage'] = $stageFilter;
}
if ($searchTerm !== null && $searchTerm !== '') {
    $baseNavParams['search'] = $searchTerm;
}
$baseNavParams = TestDataVisibility::withQueryFlag($baseNavParams, $includeTestData);
$prevNavHref = $prevId ? $buildIndexHref(array_merge($baseNavParams, ['id' => $prevId])) : '';
$nextNavHref = $nextId ? $buildIndexHref(array_merge($baseNavParams, ['id' => $nextId])) : '';
$buildFilterHref = static function (string $filter, ?string $stage = null) use ($buildIndexHref, $searchTerm, $includeTestData): string {
    $params = ['filter' => $filter];
    if ($stage !== null && $stage !== '') {
        $params['stage'] = $stage;
    }
    if ($searchTerm !== null && $searchTerm !== '') {
        $params['search'] = $searchTerm;
    }
    $params = TestDataVisibility::withQueryFlag($params, $includeTestData);
    return $buildIndexHref($params);
};

// Get import statistics (ready vs pending vs released)
// Note: Stats always show ALL counts regardless of filter
$importStats = \App\Services\StatsService::getImportStats($db, $includeTestData);
$workflowStats = \App\Services\StatsService::getWorkflowStats($db, $includeTestData);

// ✅ PHASE 11: Task Guidance - Calculate personally actionable tasks
$currentUser = \App\Support\AuthService::getCurrentUser();
$personalTaskCount = 0;
$unreadNotifications = 0;

if ($currentUser) {
    // Fetch real-time count from centralized service
    $personalTaskCount = \App\Services\StatsService::getPersonalTaskCount($db, $includeTestData);
    $unreadNotifications = NotificationService::countUnreadForCurrentUser();
}

// Keep actionable counter aligned with user-scoped actionable decision.
$importStats['actionable'] = $personalTaskCount;

// Update total to exclude released for display consistency with filters
$displayTotal = $importStats['ready'] + $importStats['pending'];

// Sidebar progress must reflect user tasks (actionable scope), not global records.
$taskTotalRecords = (int)$personalTaskCount;
$taskCurrentIndex = 0;
if ($taskTotalRecords > 0 && $currentRecord) {
    $currentRecordId = (int)$currentRecord->id;
    $recordInActionableScope = \App\Services\NavigationService::isIdInFilter(
        $db,
        $currentRecordId,
        'actionable',
        null,
        null,
        $includeTestData
    );
    if ($recordInActionableScope) {
        $taskNavInfo = \App\Services\NavigationService::getNavigationInfo(
            $db,
            $currentRecordId,
            'actionable',
            null,
            null,
            $includeTestData
        );
        $taskCurrentIndex = (int)($taskNavInfo['currentIndex'] ?? 0);
    }
}
$taskCurrentIndex = max(0, min($taskCurrentIndex, $taskTotalRecords));
$taskProgressPercent = $taskTotalRecords > 0
    ? (int)round(($taskCurrentIndex / $taskTotalRecords) * 100)
    : 0;



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
        'active_action' => null,
        // Phase 3: Workflow
        'workflow_step' => 'draft',
        'signatures_received' => 0,

        // Test data info
        'is_test_data' => $testDataInfo['is_test_data'] ?? 0,
        'test_batch_id' => $testDataInfo['test_batch_id'] ?? null,
        'test_note' => $testDataInfo['test_note'] ?? null
    ];

    // Get decision if exists - Load ALL decision data
    $decision = $decisionRepo->findByGuarantee($currentRecord->id);
    $decisionPolicyRow = [
        'status' => 'pending',
        'workflow_step' => null,
        'is_locked' => false,
        'active_action' => null,
    ];
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
        $mockRecord['active_action'] = $decision->activeAction;
        // Phase 3: Workflow
        $mockRecord['workflow_step'] = $decision->workflowStep;
        $mockRecord['signatures_received'] = $decision->signaturesReceived;
        $mockRecord['active_action_set_at'] = $decision->activeActionSetAt;

        $decisionPolicyRow = [
            'status' => (string)$decision->status,
            'workflow_step' => $decision->workflowStep,
            'is_locked' => (bool)$decision->isLocked,
            'active_action' => $decision->activeAction,
        ];

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

    $recordPolicy = ActionabilityPolicyService::evaluate(
        $decisionPolicyRow,
        true,
        $effectivePermissions
    )->toArray();
    $recordSurface = UiSurfacePolicyService::forGuarantee(
        $recordPolicy,
        $effectivePermissions,
        (string)($mockRecord['status'] ?? 'pending')
    );
    $canViewTimeline = $permissionCanViewTimeline && $recordSurface['can_view_timeline'];
    $canViewNotes = $permissionCanViewNotes && $recordSurface['can_view_notes'];
    $canCreateNotes = $permissionCanCreateNotes && $recordSurface['can_create_notes'];
    $canViewAttachments = $permissionCanViewAttachments && $recordSurface['can_view_attachments'];
    $canUploadAttachments = $permissionCanUploadAttachments && $recordSurface['can_upload_attachments'];
    $notesPermissionHintKey = ($permissionCanCreateNotes && !$recordSurface['can_create_notes'])
        ? 'index.notes.read_only_add'
        : 'index.notes.no_permission_add';
    $attachmentsPermissionHintKey = ($permissionCanUploadAttachments && !$recordSurface['can_upload_attachments'])
        ? 'index.attachments.read_only_upload'
        : 'index.attachments.no_permission_upload';

    // === UI LOGIC PROJECTION: Status Reasons (Phase 1) ===
    // Get WHY status is what it is for user transparency
    $statusReasons = \App\Services\StatusEvaluator::getReasons(
        $mockRecord['supplier_id'] ?? null,
        $mockRecord['bank_id'] ?? null,
        [] // Conflicts will be added later in Phase 3
    );
    $mockRecord['status_reasons'] = $statusReasons;

    // Load timeline/history only when viewer is allowed.
    if ($canViewTimeline) {
        $mockTimeline = \App\Services\TimelineDisplayService::getEventsForDisplay(
            $db,
            $currentRecord->id,
            $currentRecord->importedAt,
            $currentRecord->importSource,
            $currentRecord->importedBy
        );
    } else {
        $mockTimeline = [];
    }

    // Load notes/attachments only for authorized sections.
    if ($canViewNotes || $canViewAttachments) {
        $relatedData = \App\Services\GuaranteeDataService::getRelatedData($db, $currentRecord->id);
        if ($canViewNotes) {
            $mockNotes = $relatedData['notes'];
        }
        if ($canViewAttachments) {
            $mockAttachments = $relatedData['attachments'];
        }
    }

    // ADR-007: Timeline is audit-only, not UI data source
    // active_action (from guarantee_decisions) is the display pointer
    $latestEventSubtype = null; // Removed Timeline read
} else {
    // No data in database - use empty state with no confusing values
    $mockRecord = [
        'id' => 0,
        'session_id' => 0,
        'guarantee_number' => $indexT('index.ui.txt_4741fe02', '—'),
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
$displayGuaranteeNumber = ($recordSurface['can_view_identity'] ?? false)
    ? (string)($mockRecord['guarantee_number'] ?? '—')
    : '—';

// Get initial suggestions for the current record
$initialSupplierSuggestions = [];
$normalizedDecisionStatus = strtolower(trim((string)($mockRecord['status'] ?? 'pending')));
$isSupplierDecisionFinalized = in_array($normalizedDecisionStatus, ['ready', 'issued', 'approved', 'released', 'signed'], true);
if (!$isSupplierDecisionFinalized && !empty($mockRecord['supplier_name'])) {
    // ✅ PHASE 4: Using UnifiedLearningAuthority
    $authority = AuthorityFactory::create();
    $suggestionDTOs = $authority->getSuggestions($mockRecord['supplier_name']);

    // Convert DTOs to canonical UI format
    $initialSupplierSuggestions = array_map(function ($dto) {
        $confidence = (int)($dto->confidence ?? 0);
        return [
            'supplier_id' => $dto->supplier_id,
            'official_name' => $dto->official_name,
            'confidence' => $confidence,
            'usage_count' => $dto->usage_count,
            // Backward compatibility keys
            'id' => $dto->supplier_id,
            'name' => $dto->official_name,
            'score' => $confidence
        ];
    }, $suggestionDTOs);
}

// Map suggestions to frontend format
$formattedSuppliers = array_map(function ($s) {
    $supplierId = $s['supplier_id'] ?? ($s['id'] ?? 0);
    $officialName = $s['official_name'] ?? ($s['name'] ?? '');
    $confidence = (int)($s['confidence'] ?? ($s['score'] ?? 0));
    return [
        'supplier_id' => $supplierId,
        'official_name' => $officialName,
        'confidence' => $confidence,
        'usage_count' => $s['usage_count'] ?? 0,
        // Backward compatibility keys
        'id' => $supplierId,
        'name' => $officialName,
        'score' => $confidence
    ];
}, $initialSupplierSuggestions);
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($indexLocaleCode, ENT_QUOTES, 'UTF-8') ?>" dir="<?= htmlspecialchars($indexPageDirection, ENT_QUOTES, 'UTF-8') ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-i18n="index.meta.title">WBGL System v3.0</title>

    <!-- ✅ COMPLIANCE: Server-Driven Partials (Hidden) -->
    <?php include __DIR__ . '/partials/confirm-modal.php'; ?>

    <div id="preview-no-action-template" class="u-hidden">
        <?php include __DIR__ . '/partials/preview-placeholder.php'; ?>
    </div>

    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Letter Preview Styles (Classic Theme) -->
    <link rel="stylesheet" href="assets/css/letter.css">

    <!-- Pure Vanilla JavaScript - No External Dependencies -->
    <script src="public/js/convert-to-real.js"></script>

    <!-- Main Application Styles -->
    <link rel="stylesheet" href="public/css/a11y.css">
    <link rel="stylesheet" href="public/css/design-system.css">
    <link rel="stylesheet" href="public/css/themes.css">
    <link rel="stylesheet" href="public/css/index-main.css">
    <link rel="stylesheet" href="public/css/mobile.css?v=<?= time() + 7 ?>"> <!-- Mobile Retrofit (Cache Busted V8) -->

    <!-- Mobile Logic -->
    <script src="public/js/mobile.js"></script>

    <!-- Header Override to match unified header styling (same as batches page) -->
    <style>
        :root {
            --height-top-bar: 64px;
        }

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

        .u-hidden {
            display: none !important;
        }

        .btn-nav-disabled,
        .btn[disabled] {
            opacity: 0.3;
            cursor: not-allowed;
        }

        .nav-controls {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .record-position {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 14px;
            font-weight: 600;
            color: var(--text-secondary);
            white-space: nowrap;
        }

        .record-position-form {
            display: inline-flex;
            align-items: center;
            margin: 0;
        }

        .record-index-input {
            width: 45px;
            text-align: center;
            border: 1px solid var(--border-neutral);
            border-radius: 4px;
            padding: 2px 0;
            font-weight: bold;
            font-family: inherit;
            -moz-appearance: textfield;
            appearance: textfield;
        }

        .record-edit-btn {
            padding: 2px 6px;
            font-size: 14px;
            margin-right: 8px;
        }

        .test-data-banner {
            display: flex;
            align-items: center;
            gap: 12px;
            background: var(--theme-warning-surface);
            border: 2px solid var(--theme-warning-border);
            border-radius: 8px;
            padding: 14px 18px;
            margin-bottom: 16px;
        }

        .test-data-banner-icon {
            font-size: 24px;
        }

        .test-data-banner-content {
            flex: 1;
        }

        .test-data-banner-title {
            font-weight: 700;
            color: var(--theme-warning-text);
            font-size: 15px;
        }

        .test-data-banner-subtitle {
            font-size: 13px;
            color: var(--theme-warning-text-muted);
            margin-top: 4px;
        }

        .test-data-banner-action {
            padding: 6px 12px;
            background: var(--bg-card);
            border: 1px solid var(--theme-warning-border);
            border-radius: 6px;
            font-size: 13px;
            color: var(--theme-warning-text);
            text-decoration: none;
            white-space: nowrap;
            font-weight: 600;
        }

        .test-data-banner-action:hover {
            background: var(--theme-warning-surface-soft);
        }

        .personal-tasks-section {
            margin-bottom: 14px;
            padding-bottom: 14px;
            border-bottom: 1px solid var(--border-primary);
        }

        .personal-task-link {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--bg-card);
            padding: 10px 14px;
            border-radius: 10px;
            text-decoration: none;
            color: var(--text-primary);
            margin-bottom: 8px;
            border: 1px solid var(--border-primary);
            box-shadow: var(--shadow-sm);
            transition: all 0.2s;
        }

        .personal-task-link.is-active {
            background: var(--accent-primary);
            color: var(--btn-primary-text);
            border-color: var(--accent-primary);
            box-shadow: var(--shadow-md);
        }

        .personal-task-label {
            font-size: 13px;
            font-weight: 600;
        }

        .personal-task-count {
            font-size: 12px;
            font-weight: 800;
            background: var(--bg-secondary);
            color: var(--accent-primary);
            min-width: 24px;
            height: 24px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 6px;
        }

        .personal-task-count.is-active {
            background: var(--theme-overlay-medium);
            color: var(--btn-primary-text);
        }

        .personal-tasks-section+.input-toolbar {
            margin-top: 10px;
        }

        .import-stats-row {
            font-size: 11px;
            margin-bottom: 10px;
            display: flex;
            gap: 16px;
            align-items: center;
        }

        .status-filter-link {
            display: flex;
            align-items: center;
            gap: 4px;
            padding: 4px 8px;
            border-radius: 4px;
            text-decoration: none;
            transition: all 0.2s;
        }

        .status-filter-link:hover {
            background: var(--bg-hover);
        }

        .status-filter-link.is-active {
            font-weight: 600;
        }

        .status-filter-link.is-active.status-filter-link--all,
        .status-filter-link.is-active.status-filter-link--actionable {
            background: var(--accent-primary-light);
        }

        .status-filter-link.is-active.status-filter-link--ready {
            background: var(--accent-success-light);
        }

        .status-filter-link.is-active.status-filter-link--pending {
            background: var(--accent-warning-light);
        }

        .status-filter-link.is-active.status-filter-link--released {
            background: var(--accent-danger-light);
        }

        .status-filter-value {
            color: var(--text-primary);
        }

        .status-filter-value--ready {
            color: var(--accent-success);
        }

        .status-filter-value--actionable {
            color: var(--accent-primary);
        }

        .status-filter-value--pending {
            color: var(--accent-warning);
        }

        .status-filter-value--released {
            color: var(--accent-danger);
        }

        .test-data-toggle-section {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid var(--border-primary);
        }

        .test-data-toggle-title {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .test-data-toggle-link {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 8px;
            border-radius: 4px;
            text-decoration: none;
            transition: all 0.2s;
        }

        .test-data-toggle-link:hover {
            background: var(--bg-hover);
        }

        .test-data-toggle-link.is-active {
            background: var(--accent-warning-light);
            font-weight: 600;
        }

        .test-data-toggle-icon {
            font-size: 16px;
        }

        .test-data-toggle-text {
            flex: 1;
            font-size: 13px;
            color: var(--text-muted);
        }

        .test-data-toggle-text.is-active {
            color: var(--accent-warning-hover);
        }

        #notificationsSection.notifications-overlay {
            position: fixed;
            bottom: 16px;
            width: min(390px, calc(100vw - 32px));
            z-index: 1500;
            padding: 0;
            display: block;
            pointer-events: none;
            overflow: visible;
            isolation: isolate;
        }

        #notificationsSection.notifications-overlay[dir="rtl"] {
            right: 350px;
            left: auto;
        }

        #notificationsSection.notifications-overlay[dir="ltr"] {
            left: 350px;
            right: auto;
        }

        #notificationsSection.notifications-overlay.u-hidden {
            display: none !important;
        }

        .notifications-stack {
            position: relative;
            margin-top: 2px;
            min-height: 170px;
            direction: var(--notifications-dir, rtl);
            --notification-card-height: 156px;
            --stack-cap: 8;
            --stack-step: 14px;
            --stack-hover-lift: 3px;
            --stack-x-step: 0px;
            --stack-tilt-factor: 0;
            --expanded-card-gap: 8px;
            height: calc(var(--notification-card-height) + (var(--stack-step) * min(var(--cards-visible, 1), var(--stack-cap))));
            max-height: 286px;
            padding: 0;
            overflow: visible;
            pointer-events: auto;
        }

        .notifications-stack.is-expanded {
            --stack-step: 0;
            --stack-hover-lift: 0;
            --stack-tilt-factor: 0;
            --stack-x-step: 0px;
            height: auto;
            /* 3.5 cards visible in hover/expanded mode */
            max-height: min(64vh, 574px);
            overflow-y: auto;
            overflow-x: visible;
            padding-inline-end: 4px;
        }

        .notifications-stack.is-expanded .notification-card {
            position: relative;
            left: auto;
            right: auto;
            top: auto;
            transform: none !important;
            margin-bottom: var(--expanded-card-gap);
            min-height: var(--notification-card-height);
            z-index: auto;
        }

        .notifications-stack.is-expanded .notification-card:last-child {
            margin-bottom: 0;
        }

        .notifications-stack.is-expanded .notification-card:hover,
        .notifications-stack.is-expanded .notification-card.is-focused {
            transform: none !important;
            box-shadow: 0 8px 18px rgba(0, 0, 0, 0.16);
        }

        .notification-card {
            position: absolute;
            left: 0;
            right: 0;
            top: 0;
            direction: inherit;
            text-align: start;
            unicode-bidi: plaintext;
            background: linear-gradient(180deg, color-mix(in srgb, var(--bg-card) 96%, white) 0%, var(--bg-card) 100%);
            border: 1px solid var(--border-primary);
            border-radius: 13px;
            padding: 10px;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.11);
            transition: transform 0.16s ease, box-shadow 0.16s ease;
            transform: translate3d(
                calc(min(var(--stack-index, 0), var(--stack-cap, 8)) * var(--stack-x-step, 0px)),
                calc(min(var(--stack-index, 0), var(--stack-cap, 8)) * var(--stack-step, 14px)),
                0
            ) rotate(calc(var(--stack-tilt, 0) * var(--stack-tilt-factor, 1) * 1deg));
            z-index: calc(120 - var(--stack-index, 0));
            min-height: var(--notification-card-height);
            pointer-events: auto;
            cursor: default;
        }

        .notification-card:hover {
            transform: translate3d(
                calc(min(var(--stack-index, 0), var(--stack-cap, 8)) * var(--stack-x-step, 0px)),
                calc(min(var(--stack-index, 0), var(--stack-cap, 8)) * var(--stack-step, 14px) - var(--stack-hover-lift, 3px)),
                0
            ) rotate(calc(var(--stack-tilt, 0) * var(--stack-tilt-factor, 1) * 1deg));
            box-shadow: 0 8px 18px rgba(0, 0, 0, 0.16);
            z-index: 200;
        }

        .notification-card.is-focused {
            transform: translate3d(
                calc(min(var(--stack-index, 0), var(--stack-cap, 8)) * var(--stack-x-step, 0px)),
                calc(min(var(--stack-index, 0), var(--stack-cap, 8)) * var(--stack-step, 14px) - 6px),
                0
            ) rotate(0deg);
            box-shadow: 0 10px 22px rgba(0, 0, 0, 0.2);
            z-index: 260;
        }

        .notification-card--warning {
            border-inline-start: 4px solid var(--accent-warning);
        }

        .notification-card--error {
            border-inline-start: 4px solid var(--accent-danger);
        }

        .notification-card--success {
            border-inline-start: 4px solid var(--accent-success);
        }

        .notification-card--info {
            border-inline-start: 4px solid var(--accent-primary);
        }

        .notification-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 6px;
            text-align: start;
        }

        .notification-pin {
            font-size: 12px;
            opacity: 0.75;
            margin-inline-end: 4px;
        }

        .notification-type-badge {
            display: inline-flex;
            align-items: center;
            padding: 2px 6px;
            border-radius: 999px;
            background: var(--bg-secondary);
            color: var(--text-secondary);
            border: 1px solid var(--border-primary);
            font-size: 10px;
            font-weight: 700;
            white-space: nowrap;
        }

        .notification-time {
            font-size: 10px;
            color: var(--text-light);
            white-space: nowrap;
            text-align: start;
            direction: ltr;
            unicode-bidi: plaintext;
        }

        .notification-title {
            font-size: 12px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 4px;
            text-align: start;
            direction: inherit;
            unicode-bidi: plaintext;
        }

        .notification-category {
            font-size: 10px;
            color: var(--text-muted);
            margin-bottom: 4px;
            text-align: start;
            direction: inherit;
            unicode-bidi: plaintext;
        }

        .notification-message {
            font-size: 12px;
            color: var(--text-secondary);
            line-height: 1.5;
            margin-bottom: 8px;
            word-break: break-word;
            text-align: start;
            direction: inherit;
            unicode-bidi: plaintext;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
            min-height: 54px;
        }

        .notification-actions {
            display: flex;
            gap: 6px;
            justify-content: flex-start;
            direction: inherit;
        }

        #notificationsSection.notifications-overlay[dir="rtl"] .notification-actions {
            justify-content: flex-end;
            flex-direction: row-reverse;
        }

        #notificationsSection.notifications-overlay[dir="ltr"] .notification-actions {
            justify-content: flex-start;
            flex-direction: row;
        }

        #notificationsSection.notifications-overlay[dir="rtl"] .notification-title,
        #notificationsSection.notifications-overlay[dir="rtl"] .notification-category,
        #notificationsSection.notifications-overlay[dir="rtl"] .notification-message {
            text-align: right;
        }

        #notificationsSection.notifications-overlay[dir="ltr"] .notification-title,
        #notificationsSection.notifications-overlay[dir="ltr"] .notification-category,
        #notificationsSection.notifications-overlay[dir="ltr"] .notification-message {
            text-align: left;
        }

        #notificationsSection.notifications-overlay[dir="rtl"] .notification-card-header {
            direction: rtl;
        }

        #notificationsSection.notifications-overlay[dir="ltr"] .notification-card-header {
            direction: ltr;
        }

        .notification-btn {
            border: 1px solid var(--border-primary);
            background: var(--bg-secondary);
            color: var(--text-secondary);
            border-radius: 8px;
            font-size: 11px;
            padding: 3px 8px;
            cursor: pointer;
        }

        @media (max-width: 1399px) {
            #notificationsSection.notifications-overlay[dir="rtl"] {
                right: 16px;
                left: auto;
                bottom: 16px;
                width: min(390px, calc(100vw - 32px));
            }

            #notificationsSection.notifications-overlay[dir="ltr"] {
                left: 16px;
                right: auto;
                bottom: 16px;
                width: min(390px, calc(100vw - 32px));
            }
        }

        @media print {
            #notificationsSection.notifications-overlay {
                display: none !important;
            }
        }

        .notification-btn:hover {
            background: var(--bg-hover);
            color: var(--text-primary);
        }

        .notification-btn--read {
            color: var(--accent-success);
            border-color: var(--accent-success);
        }

        .notification-btn--hide {
            color: var(--accent-warning-hover);
            border-color: var(--accent-warning);
        }

        .empty-state-message { display: none; }

        .sidebar-permission-hint {
            margin: 8px 0 12px;
            text-align: center;
            color: var(--text-light);
            font-size: 12px;
        }

        .sidebar-permission-hint.note-hint {
            margin-top: 10px;
            margin-bottom: 0;
        }

        .sidebar-section-spaced {
            margin-top: 24px;
        }

        .upload-label {
            cursor: pointer;
            display: inline-block;
            width: 100%;
            text-align: center;
        }

        .attachment-item {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .attachment-icon {
            font-size: 24px;
        }

        .attachment-meta {
            flex: 1;
            min-width: 0;
        }

        .attachment-name {
            margin: 0;
            font-weight: 500;
        }

        .attachment-download-link {
            color: var(--text-light);
            text-decoration: none;
            font-size: 18px;
            padding: 4px;
        }

        .released-banner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--theme-danger-surface);
            border: 2px solid var(--theme-danger-border);
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 16px;
        }

        .released-banner-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .released-banner-icon {
            font-size: 20px;
        }

        .released-banner-title {
            font-weight: 600;
            color: var(--theme-danger-text);
        }

        .released-banner-subtitle {
            font-size: 12px;
            color: var(--theme-danger-text-muted);
        }
    </style>

</head>

<body data-i18n-namespaces="common,index,timeline,modals,messages,batch_detail">

    <!-- Hidden File Input for Excel Import -->
    <input type="file" id="hiddenFileInput" accept=".xlsx,.xls" class="u-hidden">

    <!-- Unified Header -->
    <?php include __DIR__ . '/partials/unified-header.php'; ?>

    <!-- Main Container -->
    <div class="app-container">

        <!-- Center Section -->
        <div class="center-section">

            <!-- Record Header -->
            <header class="record-header">
                <div class="record-title">
                    <h1><span data-i18n="index.ui.txt_ba344e00">ضمان رقم</span> <span id="guarantee-number-display"><?= htmlspecialchars($displayGuaranteeNumber) ?></span></h1>
                    <?php if ($currentRecord && ($recordSurface['can_view_identity'] ?? false)): ?>
                        <?php
                        $statusRaw = strtolower(trim((string)($mockRecord['status'] ?? 'pending')));
                        $statusNormalizedForUi = $statusRaw === 'approved' ? 'ready' : $statusRaw;

                        // Display status badge based on actual status
                        if ($statusNormalizedForUi === 'released') {
                            $statusClass = 'badge-released';
                            $statusI18nKey = 'index.ui.txt_0319c78f';
                        } elseif (in_array($statusNormalizedForUi, ['ready', 'issued', 'signed'], true)) {
                            $statusClass = 'badge-approved';
                            $statusI18nKey = 'index.status.ready';
                        } else {
                            $statusClass = 'badge-pending';
                            $statusI18nKey = 'index.status.pending_decision';
                        }
                        $statusText = $indexT($statusI18nKey);
                        ?>
                        <span class="badge <?= $statusClass ?>" data-i18n="<?= htmlspecialchars($statusI18nKey) ?>"><?= $statusText ?></span>
                        <?php if (
                            $currentUserRoleSlug === 'data_entry'
                            && $permissionCanReopenGuarantee
                            && in_array($statusNormalizedForUi, ['ready', 'issued', 'released', 'signed'], true)
                        ): ?>
                            <button class="btn btn-ghost btn-xs record-edit-btn"
                                title=""
                                data-i18n-title="index.ui.txt_29a85387"
                                data-action="reopenRecord"
                                data-authorize-resource="guarantee"
                                data-authorize-action="reopen"
                                data-authorize-mode="hide"
                                >
                                ✏️
                            </button>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- Navigation Controls -->
                <div class="navigation-controls nav-controls">
                    <button class="btn btn-ghost btn-sm"
                        onclick="window.location.href='<?= htmlspecialchars($prevNavHref) ?>'"
                        <?= !$prevId ? 'disabled' : '' ?>>
                        <span data-i18n="index.nav.previous">← السابق</span>
                    </button>

                    <div class="record-position">
                        <form action="index.php" method="GET" class="record-position-form">
                            <?php if ($statusFilter !== 'all'): ?><input type="hidden" name="filter" value="<?= $statusFilter ?>"><?php endif; ?>
                            <?php if ($stageFilter): ?><input type="hidden" name="stage" value="<?= htmlspecialchars($stageFilter) ?>"><?php endif; ?>
                            <?php if ($searchTerm): ?><input type="hidden" name="search" value="<?= htmlspecialchars($searchTerm) ?>"><?php endif; ?>
                            <input type="number"
                                name="jump_to_index"
                                value="<?= $currentIndex ?>"
                                min="<?= $totalRecords > 0 ? 1 : 0 ?>"
                                max="<?= $totalRecords > 0 ? $totalRecords : 0 ?>"
                                class="record-index-input"
                                <?= $totalRecords === 0 ? 'disabled' : '' ?>
                                onfocus="this.select()">
                        </form>
                        <span>/ <?= $totalRecords ?></span>
                    </div>

                    <button class="btn btn-ghost btn-sm"
                        onclick="window.location.href='<?= htmlspecialchars($nextNavHref) ?>'"
                        <?= !$nextId ? 'disabled' : '' ?>>
                        <span data-i18n="index.nav.next">التالي →</span>
                    </button>
                </div>













            </header>

            <!-- Content Wrapper -->
            <div class="content-wrapper">

                <!-- Timeline Panel (permission-gated) -->
                <?php if ($canViewTimeline): ?>
                    <?php
                    $timeline = $mockTimeline;
                    require __DIR__ . '/partials/timeline-section.php';
                    ?>
                <?php endif; ?>

                <!-- Main Content -->
                <main class="main-content">
                    <!-- ✅ HISTORICAL BANNER: Server-driven partial (Hidden by default) -->
                    <div id="historical-banner-container" hidden>
                        <?php include __DIR__ . '/partials/historical-banner.php'; ?>
                    </div>

                    <!-- Test Data Banner (Hidden in Production Mode) -->
                    <?php if (!empty($mockRecord['is_test_data']) && !$settings->isProductionMode()): ?>
                        <div class="test-data-banner">
                            <div class="test-data-banner-icon">🧪</div>
                            <div class="test-data-banner-content">
                                <div class="test-data-banner-title" data-i18n="index.ui.txt_4555afcd">ضمان تجريبي - لأغراض الاختبار فقط</div>
                                <div class="test-data-banner-subtitle">
                                    <span data-i18n="index.test_data.subtitle">هذه البيانات لن تؤثر على الإحصائيات أو نظام التعلم</span>
                                    <?php if (!empty($mockRecord['test_batch_id'])): ?>
                                        • <span data-i18n="index.test_data.batch_label">الدفعة:</span> <strong><?= htmlspecialchars($mockRecord['test_batch_id']) ?></strong>
                                    <?php endif; ?>
                                    <?php if (!empty($mockRecord['test_note'])): ?>
                                        <br><?= htmlspecialchars($mockRecord['test_note']) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <a href="#" onclick="convertToReal(<?= $mockRecord['id'] ?>); return false;" class="test-data-banner-action">
                                <span data-i18n="index.test_data.convert_to_real">تحويل إلى حقيقي</span>
                            </a>
                        </div>
                    <?php endif; ?>

                    <!-- Decision Cards -->
                    <?php if ($recordSurface['can_view_record'] ?? false): ?>
                    <div class="decision-card"
                        id="record-form-section"
                        data-policy-visible="<?= ($recordPolicy['visible'] ?? false) ? '1' : '0' ?>"
                        data-policy-actionable="<?= ($recordPolicy['actionable'] ?? false) ? '1' : '0' ?>"
                        data-policy-executable="<?= ($recordPolicy['executable'] ?? false) ? '1' : '0' ?>">

                        <?php
                        // Prepare data for record-form partial
                        $record = $mockRecord;
                        $recordCanExecuteActions = (bool)($recordSurface['can_execute_actions'] ?? false);
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
                                    error_log('BANK_MATCH_ERROR:' . ' ' . $e->getMessage());
                                }
                            }
                        }

                        $isHistorical = false;

                        // Include the Alpine-free record form partial
                        require __DIR__ . '/partials/record-form.php';
                        ?>
                    </div>
                    <?php else: ?>
                        <div class="decision-card decision-card-empty-state">
                            <div class="card-body">
                                <div class="empty-state-message" data-i18n="index.empty.no_record_in_scope">
                                    لا توجد سجلات ضمن نطاق العرض الحالي
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Preview Section - Show for ready and released guarantees -->
                    <?php
                    $statusForPreview = strtolower(trim((string)($mockRecord['status'] ?? 'pending')));
                    $statusForPreview = $statusForPreview === 'approved' ? 'ready' : $statusForPreview;
                    $workflowForPreview = strtolower(trim((string)($mockRecord['workflow_step'] ?? 'draft')));
                    $showPrintButton = (
                        $currentUserRoleSlug === 'data_entry'
                        &&
                        $statusForPreview === 'ready'
                        && $workflowForPreview === 'signed'
                        && (bool)($recordSurface['can_execute_actions'] ?? false)
                    );
                    ?>
                    <?php if (($recordSurface['can_view_preview'] ?? false) && in_array($statusForPreview, ['ready', 'issued', 'released', 'signed'], true)): ?>
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

            <!-- ✅ PHASE 12: Granular Task Breakdown -->
            <?php
            $fullPersonalTasksUsernames = ['admin'];
            $currentUsername = strtolower(trim((string)($currentUser?->username ?? '')));
            $showFullPersonalTasks = in_array($currentUsername, $fullPersonalTasksUsernames, true);
            $personalTaskBreakdown = $showFullPersonalTasks
                ? \App\Services\StatsService::getPersonalTaskBreakdown($db, $includeTestData, true)
                : [];
            if ($showFullPersonalTasks && count($personalTaskBreakdown) > 0):
            ?>
                <div class="personal-tasks-section">
                    <?php foreach ($personalTaskBreakdown as $bucket): ?>
                        <?php
                        $bucketFilter = (string)($bucket['filter'] ?? 'actionable');
                        $bucketStage = isset($bucket['stage']) && $bucket['stage'] !== null
                            ? (string)$bucket['stage']
                            : '';
                        $isActive = ($statusFilter === $bucketFilter)
                            && (
                                ($bucketStage === '' && ($stageFilter === null || $stageFilter === ''))
                                || ($bucketStage !== '' && $stageFilter === $bucketStage)
                            );
                        $bucketStageParam = $bucketStage !== '' ? $bucketStage : null;
                        ?>
                        <a href="<?= htmlspecialchars($buildFilterHref($bucketFilter, $bucketStageParam)) ?>"
                            class="personal-task-link <?= $isActive ? 'is-active' : '' ?>">
                            <span class="personal-task-label"
                                <?= !empty($bucket['label_key']) ? 'data-i18n="' . htmlspecialchars((string)$bucket['label_key'], ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
                                <?= htmlspecialchars((string)($bucket['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                            <span class="personal-task-count <?= $isActive ? 'is-active' : '' ?>">
                                <?= $bucket['count'] ?>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Input Actions (New Proposal) -->
            <div class="input-toolbar">
                <!-- Import Stats (Interactive Filter) -->
                <?php if (isset($importStats) && ($importStats['total'] > 0)): ?>
                    <div class="import-stats-row">
                        <?php if ($showFiltersForCurrentUser): ?>
                            <a href="<?= htmlspecialchars($buildFilterHref('all')) ?>"
                                title=""
                                data-i18n-title="index.ui.open_workflow_only_title"
                                class="status-filter-link status-filter-link--all <?= $statusFilter === 'all' ? 'is-active' : '' ?>">
                                <span class="status-filter-value">📊 <?= $displayTotal ?? $importStats['total'] ?></span>
                            </a>
                            <a href="<?= htmlspecialchars($buildFilterHref('ready')) ?>"
                                class="status-filter-link status-filter-link--ready <?= $statusFilter === 'ready' ? 'is-active' : '' ?>">
                                <span class="status-filter-value status-filter-value--ready">✅ <?= $importStats['ready'] ?? 0 ?></span>
                            </a>
                            <a href="<?= htmlspecialchars($buildFilterHref('actionable')) ?>"
                                title=""
                                data-i18n-title="index.ui.txt_9d784327"
                                class="status-filter-link status-filter-link--actionable <?= $statusFilter === 'actionable' ? 'is-active' : '' ?>">
                                <span class="status-filter-value status-filter-value--actionable">⏳ <?= $importStats['actionable'] ?? 0 ?></span>
                            </a>
                            <a href="<?= htmlspecialchars($buildFilterHref('pending')) ?>"
                                class="status-filter-link status-filter-link--pending <?= $statusFilter === 'pending' ? 'is-active' : '' ?>">
                                <span class="status-filter-value status-filter-value--pending">⚠️ <?= $importStats['pending'] ?? 0 ?></span>
                            </a>
                            <a href="<?= htmlspecialchars($buildFilterHref('released')) ?>"
                                class="status-filter-link status-filter-link--released <?= $statusFilter === 'released' ? 'is-active' : '' ?>">
                                <span class="status-filter-value status-filter-value--released">🔓 <?= $importStats['released'] ?? 0 ?></span>
                            </a>
                        <?php else: ?>
                            <div class="status-filter-value status-filter-value--actionable">
                                ⏳ <?= $importStats['actionable'] ?? 0 ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <!-- ✅ NEW: Test Data Filter Toggle (Phase 1) -->
                    <?php if ($showFiltersForCurrentUser && !$settings->isProductionMode()): ?>
                        <div class="test-data-toggle-section">
                            <div class="test-data-toggle-title" data-i18n="index.ui.txt_44cda22f">بيانات الاختبار</div>
                            <a href="<?php
                                        $currentParams = $_GET;
                                        if ($includeTestData) {
                                            $currentParams['include_test_data'] = '0';
                                        } else {
                                            $currentParams['include_test_data'] = '1';
                                        }
                                        echo '/index.php?' . http_build_query($currentParams);
                                        ?>"
                                class="test-data-toggle-link <?= $includeTestData ? 'is-active' : '' ?>">
                                <span class="test-data-toggle-icon"><?= $includeTestData ? '✅' : '🧪' ?></span>
                                <span class="test-data-toggle-text <?= $includeTestData ? 'is-active' : '' ?>">
                                    <?= $includeTestData ? '<span data-i18n="index.ui.txt_ac952de4">إخفاء التجريبية</span>' : '<span data-i18n="index.ui.txt_8016dd3f">عرض التجريبية</span>' ?>
                                </span>
                            </a>
                        </div>
                    <?php endif; ?>

                <?php else: ?>
                    <div class="toolbar-label" data-i18n="index.ui.txt_030dc745">إدخال جديد</div>
                <?php endif; ?>
                <div class="toolbar-actions">
                    <button class="btn-input"
                        title=""
                        data-i18n-title="index.ui.txt_3fcbc349"
                        data-action="showManualInput"
                        data-authorize-resource="guarantee"
                        data-authorize-action="manual-entry"
                        data-authorize-mode="hide">
                        <span>&#x270D;</span>
                        <span data-i18n="index.ui.txt_ff83aaa1">يدوي</span>
                    </button>
                    <button class="btn-input"
                        title=""
                        data-i18n-title="index.ui.txt_d50dad78"
                        data-action="showImportModal"
                        data-authorize-resource="imports"
                        data-authorize-action="create"
                        data-authorize-mode="hide">
                        <span>&#x1F4CA;</span>
                        <span data-i18n="index.ui.txt_ae56c3a5">ملف</span>
                    </button>
                    <button class="btn-input"
                        title=""
                        data-i18n-title="index.ui.txt_9ecebdbc"
                        data-action="showPasteModal"
                        data-authorize-resource="imports"
                        data-authorize-action="create"
                        data-authorize-mode="hide">
                        <span>&#x1F4CB;</span>
                        <span data-i18n="index.ui.txt_22f2c949">لصق</span>
                    </button>
                </div>
                <!-- Hidden Input for Import -->
                <input type="file" id="hiddenFileInput" class="u-hidden" accept=".xlsx,.xls,.csv" />
            </div>

            <!-- Progress -->
            <div class="progress-container">
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?= $taskProgressPercent ?>%;"></div>
                    </div>
                <div class="progress-text">
                    <span><span data-i18n="index.tasks.progress_label">مهام</span> <span><?= $taskCurrentIndex ?></span> <span data-i18n="index.ui.txt_aa7099e2">من</span> <span><?= $taskTotalRecords ?></span></span>
                    <span class="progress-percent"><?= $taskProgressPercent ?>%</span>
                </div>
            </div>

            <!-- Sidebar Body -->
            <div class="sidebar-body">
                <?php if ($canViewNotes): ?>
                    <!-- Notes Section -->
                    <div class="sidebar-section sidebar-section-spaced" id="notesSection">
                        <div class="sidebar-section-title">
                            📝 <span data-i18n="index.notes.title">الملاحظات</span>
                        </div>

                        <!-- Notes List -->
                        <div id="notesList">
                            <?php if (empty($mockNotes)): ?>
                                <div id="emptyNotesMessage" class="empty-state-message">
                                    <span data-i18n="index.notes.empty">لا توجد ملاحظات</span>
                                </div>
                            <?php else: ?>
                                <?php foreach ($mockNotes as $note): ?>
                                    <div class="note-item">
                                        <div class="note-header">
                                            <span class="note-author"><?= htmlspecialchars($note['created_by'] ?? '—') ?></span>
                                            <span class="note-time"><?= substr($note['created_at'] ?? '', 0, 16) ?></span>
                                        </div>
                                        <div class="note-content"><?= htmlspecialchars($note['content'] ?? '') ?></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <?php if ($canCreateNotes): ?>
                            <!-- Note Input Box -->
                            <div id="noteInputBox" class="note-input-box u-hidden">
                                <textarea id="noteTextarea" placeholder="" data-i18n-placeholder="index.ui.txt_74ee2741"></textarea>
                                <div class="note-input-actions">
                                    <button onclick="cancelNote()" class="note-cancel-btn">
                                        <span data-i18n="index.notes.cancel">إلغاء</span>
                                    </button>
                                    <button onclick="saveNote()" class="note-save-btn">
                                        <span data-i18n="index.notes.save">حفظ</span>
                                    </button>
                                </div>
                            </div>

                            <!-- Add Note Button -->
                            <button id="addNoteBtn" onclick="showNoteInput()" class="add-note-btn">
                                + <span data-i18n="index.notes.add">إضافة ملاحظة</span>
                            </button>
                        <?php else: ?>
                            <div class="sidebar-permission-hint note-hint">
                                <span data-i18n="<?= htmlspecialchars($notesPermissionHintKey, ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($indexT($notesPermissionHintKey, 'ليس لديك صلاحية إضافة الملاحظات'), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($canViewAttachments): ?>
                    <!-- Attachments Section -->
                    <div class="sidebar-section sidebar-section-spaced">
                        <div class="sidebar-section-title">
                            📎 <span data-i18n="index.attachments.title">المرفقات</span>
                        </div>

                        <?php if ($canUploadAttachments): ?>
                            <!-- Upload Button -->
                            <label class="add-note-btn upload-label">
                                <input type="file" id="fileInput" class="u-hidden" onchange="uploadFile(event)">
                                + <span data-i18n="index.attachments.upload">رفع ملف</span>
                            </label>
                        <?php else: ?>
                            <div class="sidebar-permission-hint">
                                <span data-i18n="<?= htmlspecialchars($attachmentsPermissionHintKey, ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($indexT($attachmentsPermissionHintKey, 'لا تملك صلاحية رفع المرفقات'), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </div>
                        <?php endif; ?>

                        <!-- Attachments List -->
                        <div id="attachmentsList">
                            <?php if (empty($mockAttachments)): ?>
                                <div id="emptyAttachmentsMessage" class="empty-state-message">
                                    <span data-i18n="index.attachments.empty">لا توجد مرفقات</span>
                                </div>
                            <?php else: ?>
                                <?php foreach ($mockAttachments as $file): ?>
                                    <div class="note-item attachment-item">
                                        <div class="attachment-icon">📄</div>
                                        <div class="attachment-meta">
                                            <div class="note-content attachment-name"><?= htmlspecialchars($file['file_name'] ?? $indexT('index.ui.txt_ae56c3a5', '—')) ?></div>
                                            <div class="note-time"><?= substr($file['created_at'] ?? '', 0, 10) ?></div>
                                        </div>
                                        <a href="/api/attachment-file.php?id=<?= (int)($file['id'] ?? 0) ?>&amp;inline=1"
                                            target="_blank"
                                            class="attachment-download-link"
                                            title=""
                                            data-i18n-title="index.ui.txt_20f73a4e">
                                            ⬇️
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </aside>

	    </div>

    <!-- Floating Notifications Overlay (Detached from sidebar layout) -->
    <div class="notifications-overlay u-hidden" id="notificationsSection" aria-live="polite">
        <div id="notificationsList" class="notifications-stack"></div>
    </div>

	    <!-- Modals - Using existing partials -->
	    <?php require __DIR__ . '/partials/manual-entry-modal.php'; ?>
    <?php require __DIR__ . '/partials/paste-modal.php'; ?>
    <?php require __DIR__ . '/partials/excel-import-modal.php'; ?>

    <?php if (!empty($mockRecord['is_locked'])): ?>
        <!-- Released Guarantee: Read-Only Mode -->
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const wbglT = (key, fallback, params) => (window.WBGLI18n && typeof window.WBGLI18n.t === 'function')
                    ? window.WBGLI18n.t(key, fallback, params || {})
                    : fallback;
                // Show released banner
                const banner = document.createElement('div');
                banner.id = 'released-banner';
                banner.innerHTML = `
                <div class="released-banner">
                    <div class="released-banner-info">
                        <span class="released-banner-icon">🔒</span>
                        <div>
                            <div class="released-banner-title" data-i18n="index.ui.txt_0319c78f" data-i18n-fallback="ضمان مُفرج عنه">${wbglT('index.ui.txt_0319c78f', 'ضمان مُفرج عنه')}</div>
                            <div class="released-banner-subtitle" data-i18n="index.ui.txt_c4467a4b" data-i18n-fallback="هذا الضمان خارج التدفق التشغيلي - للعرض فقط">${wbglT('index.ui.txt_c4467a4b', 'هذا الضمان خارج التدفق التشغيلي - للعرض فقط')}</div>
                        </div>
                    </div>
                </div>
            `;

                const recordForm = document.querySelector('.decision-card, .card');
                if (recordForm && recordForm.parentNode) {
                    recordForm.parentNode.insertBefore(banner, recordForm);
                }
                if (window.WBGLI18n && typeof window.WBGLI18n.applyTranslations === 'function') {
                    window.WBGLI18n.applyTranslations();
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
                if (suggestions) suggestions.hidden = true;

                BglLogger.debug(wbglT('index.ui.released_guarantee_read_only_mode', ''));
            });
        </script>
    <?php endif; ?>

    <script>
        window.WBGLTranslate = window.WBGLTranslate || function(key, fallback, params) {
            return (window.WBGLI18n && typeof window.WBGLI18n.t === 'function')
                ? window.WBGLI18n.t(key, fallback, params || {})
                : fallback;
        };

        const wbglNotificationsState = {
            items: [],
            focusedNotificationId: null,
            interactionsBound: false
        };
        const wbglNotificationUiLimit = <?= (int)$notificationUiLimit ?>;
        const wbglMaxStickyNotifications = 6;
        const wbglAutoDismissTimers = new Map();
        const wbglNotificationSessionHiddenIds = new Set();
        const wbglNotificationSessionStorageKey = 'wbgl_notifications_hidden_session:'
            + <?= json_encode((string)session_id() . ':' . (string)($currentUser?->username ?? 'guest'), JSON_UNESCAPED_UNICODE) ?>;

        function wbglLoadSessionHiddenNotifications() {
            try {
                const raw = window.sessionStorage.getItem(wbglNotificationSessionStorageKey);
                if (!raw) return;
                const parsed = JSON.parse(raw);
                if (!Array.isArray(parsed)) return;
                parsed.forEach((id) => {
                    const numericId = Number(id || 0);
                    if (numericId > 0) {
                        wbglNotificationSessionHiddenIds.add(numericId);
                    }
                });
            } catch (err) {
                console.warn('NOTIFICATION_SESSION_HIDDEN_LOAD_ERROR', err);
            }
        }

        function wbglPersistSessionHiddenNotifications() {
            try {
                const ids = Array.from(wbglNotificationSessionHiddenIds.values())
                    .filter((id) => Number(id) > 0)
                    .map((id) => Number(id));
                window.sessionStorage.setItem(wbglNotificationSessionStorageKey, JSON.stringify(ids));
            } catch (err) {
                console.warn('NOTIFICATION_SESSION_HIDDEN_SAVE_ERROR', err);
            }
        }

        function wbglHideNotificationForSession(notificationId) {
            const id = Number(notificationId || 0);
            if (!id) return;
            wbglNotificationSessionHiddenIds.add(id);
            wbglPersistSessionHiddenNotifications();
            wbglNotificationsState.items = wbglNotificationsState.items.filter((item) => Number(item?.id || 0) !== id);
            wbglRenderNotifications();
        }

        function wbglEscapeHtml(value) {
            const str = String(value ?? '');
            return str
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function wbglNotificationTranslate(key, fallback, params) {
            return window.WBGLTranslate
                ? window.WBGLTranslate(key, fallback, params || {})
                : fallback;
        }

        function wbglNotificationLocale() {
            const language = wbglNotificationLanguage();
            return language === 'en' ? 'en-US' : 'ar-SA';
        }

        function wbglNotificationLanguage() {
            const raw = (window.WBGLI18n && typeof window.WBGLI18n.getLanguage === 'function')
                ? String(window.WBGLI18n.getLanguage() || '').trim().toLowerCase()
                : String(document.documentElement.getAttribute('lang') || '').trim().toLowerCase();
            return raw === 'en' ? 'en' : 'ar';
        }

        function wbglNotificationDirection() {
            const explicit = (window.WBGLDirection && typeof window.WBGLDirection.getDirection === 'function')
                ? String(window.WBGLDirection.getDirection() || '').trim().toLowerCase()
                : '';
            if (explicit === 'rtl' || explicit === 'ltr') {
                return explicit;
            }

            const htmlDir = String(document.documentElement.getAttribute('dir') || '').trim().toLowerCase();
            if (htmlDir === 'rtl' || htmlDir === 'ltr') {
                return htmlDir;
            }

            const language = wbglNotificationLanguage();
            return language === 'en' ? 'ltr' : 'rtl';
        }

        function wbglDefaultNotificationI18nByType(item) {
            const type = String(item?.type || '').trim().toLowerCase();
            const data = (item?.data && typeof item.data === 'object') ? item.data : {};
            const guaranteeId = String(data?.guarantee_id ?? '');
            const guaranteeNumber = String(data?.guarantee_number ?? guaranteeId);
            const expiryDate = String(data?.expiry_date ?? '');
            const daysRemaining = String(data?.days_remaining ?? '');
            const requestId = String(data?.request_id ?? '');
            const jobName = String(data?.job_name ?? '');
            const attempts = String(data?.attempts ?? '');
            const actionName = String(data?.action_name ?? '');
            const targetId = String(data?.target_id ?? '');
            const fileName = String(data?.file_name ?? '');

            if (type === 'workflow_reject') {
                return {
                    title_key: 'index.notifications.content.workflow_reject.title',
                    title_fallback: 'تم رفض مسار الضمان',
                    title_params: {},
                    message_key: 'index.notifications.content.workflow_reject.message',
                    message_fallback: 'تمت إعادة السجل إلى مدخل البيانات مع إزالة الإجراء النشط.',
                    message_params: { guarantee_id: guaranteeId },
                };
            }
            if (type === 'break_glass_override_used') {
                return {
                    title_key: 'index.notifications.content.break_glass_override_used.title',
                    title_fallback: 'تم تفعيل تجاوز طارئ (Break Glass)',
                    title_params: {},
                    message_key: 'index.notifications.content.break_glass_override_used.message',
                    message_fallback: 'تم استخدام مسار الطوارئ لتنفيذ إجراء عالي الحساسية.',
                    message_params: { action_name: actionName, target_id: targetId },
                };
            }
            if (type === 'import_failure') {
                return {
                    title_key: 'index.notifications.content.import_failure.title',
                    title_fallback: 'فشل عملية الاستيراد',
                    title_params: {},
                    message_key: 'index.notifications.content.import_failure.message',
                    message_fallback: 'تعذّر إكمال استيراد الملف.',
                    message_params: { file_name: fileName },
                };
            }
            if (type === 'scheduler_failure') {
                return {
                    title_key: 'index.notifications.content.scheduler_failure.title',
                    title_fallback: 'فشل مهمة مجدولة',
                    title_params: {},
                    message_key: 'index.notifications.content.scheduler_failure.message',
                    message_fallback: 'فشلت مهمة مجدولة بعد عدة محاولات.',
                    message_params: { job_name: jobName, attempts: attempts },
                };
            }
            if (type === 'expiry_warning') {
                return {
                    title_key: 'index.notifications.content.expiry_warning.title',
                    title_fallback: 'تنبيه انتهاء ضمان',
                    title_params: {},
                    message_key: 'index.notifications.content.expiry_warning.message',
                    message_fallback: 'الضمان رقم {{guarantee_number}} سينتهي خلال {{days_remaining}} يوم (تاريخ الانتهاء: {{expiry_date}}).',
                    message_params: {
                        guarantee_number: guaranteeNumber,
                        days_remaining: daysRemaining,
                        expiry_date: expiryDate,
                    },
                };
            }
            if (type === 'undo_request_submitted') {
                return {
                    title_key: 'index.notifications.content.undo_request_submitted.title',
                    title_fallback: 'طلب إعادة فتح جديد',
                    title_params: {},
                    message_key: 'index.notifications.content.undo_request_submitted.message',
                    message_fallback: 'تم إنشاء طلب إعادة فتح جديد.',
                    message_params: { request_id: requestId, guarantee_id: guaranteeId },
                };
            }
            if (type === 'undo_request_approved') {
                return {
                    title_key: 'index.notifications.content.undo_request_approved.title',
                    title_fallback: 'اعتماد طلب إعادة فتح',
                    title_params: {},
                    message_key: 'index.notifications.content.undo_request_approved.message',
                    message_fallback: 'تم اعتماد طلب إعادة الفتح.',
                    message_params: { request_id: requestId },
                };
            }
            if (type === 'undo_request_rejected') {
                return {
                    title_key: 'index.notifications.content.undo_request_rejected.title',
                    title_fallback: 'رفض طلب إعادة فتح',
                    title_params: {},
                    message_key: 'index.notifications.content.undo_request_rejected.message',
                    message_fallback: 'تم رفض طلب إعادة الفتح.',
                    message_params: { request_id: requestId },
                };
            }
            if (type === 'undo_request_executed') {
                return {
                    title_key: 'index.notifications.content.undo_request_executed.title',
                    title_fallback: 'تنفيذ طلب إعادة فتح',
                    title_params: {},
                    message_key: 'index.notifications.content.undo_request_executed.message',
                    message_fallback: 'تم تنفيذ طلب إعادة الفتح.',
                    message_params: { request_id: requestId },
                };
            }
            return null;
        }

        function wbglResolveNotificationText(item) {
            const data = (item?.data && typeof item.data === 'object') ? item.data : {};
            const explicit = (data?.notification_i18n && typeof data.notification_i18n === 'object')
                ? data.notification_i18n
                : null;
            const i18nPayload = explicit || wbglDefaultNotificationI18nByType(item);

            let titleText = String(item?.title || '').trim();
            let messageText = String(item?.message || '').trim();
            if (i18nPayload && typeof i18nPayload === 'object') {
                const titleKey = String(i18nPayload.title_key || '').trim();
                const titleFallback = String(i18nPayload.title_fallback || titleText || wbglNotificationTranslate('index.notifications.default_title', 'إشعار'));
                const titleParams = (i18nPayload.title_params && typeof i18nPayload.title_params === 'object') ? i18nPayload.title_params : {};
                if (titleKey !== '') {
                    titleText = wbglNotificationTranslate(titleKey, titleFallback, titleParams);
                } else {
                    titleText = titleFallback;
                }

                const messageKey = String(i18nPayload.message_key || '').trim();
                const messageFallback = String(i18nPayload.message_fallback || messageText || '');
                const messageParams = (i18nPayload.message_params && typeof i18nPayload.message_params === 'object') ? i18nPayload.message_params : {};
                if (messageKey !== '') {
                    messageText = wbglNotificationTranslate(messageKey, messageFallback, messageParams);
                } else {
                    messageText = messageFallback;
                }
            }

            return {
                title: titleText || wbglNotificationTranslate('index.notifications.default_title', 'إشعار'),
                message: messageText || '',
            };
        }

        function wbglFormatNotificationTime(raw) {
            if (!raw) return '';
            const parsed = new Date(String(raw).replace(' ', 'T'));
            if (Number.isNaN(parsed.getTime())) return String(raw);
            return parsed.toLocaleString(wbglNotificationLocale(), {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
            });
        }

        function wbglParseDateMs(raw) {
            if (!raw) return null;
            const parsed = new Date(String(raw).replace(' ', 'T'));
            const ms = parsed.getTime();
            return Number.isNaN(ms) ? null : ms;
        }

        function wbglGetNotificationWindow(item) {
            const meta = (item && item.data && typeof item.data === 'object' && item.data.notification_meta && typeof item.data.notification_meta === 'object')
                ? item.data.notification_meta
                : {};

            const displayFrom = meta.display_from ?? meta.starts_at ?? meta.show_from ?? null;
            const displayUntil = meta.display_until ?? meta.expires_at ?? meta.ends_at ?? null;
            const durationSeconds = Number(meta.display_duration_seconds ?? meta.ttl_seconds ?? 0);

            const startMs = wbglParseDateMs(displayFrom);
            let endMs = wbglParseDateMs(displayUntil);
            if (!endMs && Number.isFinite(durationSeconds) && durationSeconds > 0) {
                const createdMs = wbglParseDateMs(item?.created_at);
                if (createdMs) {
                    endMs = createdMs + (durationSeconds * 1000);
                }
            }

            return {
                startMs: startMs,
                endMs: endMs,
            };
        }

        function wbglResolveNotificationVisibility(item, nowMs) {
            const window = wbglGetNotificationWindow(item);
            if (window.startMs && nowMs < window.startMs) {
                return {
                    visible: false,
                    expired: false,
                };
            }
            return {
                visible: true,
                expired: false,
            };
        }

        function wbglClearAutoDismissTimers(activeIdsSet = null) {
            wbglAutoDismissTimers.forEach((timer, id) => {
                if (activeIdsSet && activeIdsSet.has(id)) {
                    return;
                }
                clearTimeout(timer);
                wbglAutoDismissTimers.delete(id);
            });
        }

        function wbglScheduleAutoDismiss(activeItems) {
            // Explicit user action only:
            // notifications must NOT auto-hide.
            wbglClearAutoDismissTimers();
        }

        function wbglNotificationTypeMeta(item) {
            const normalizedType = String(item?.type || '').toLowerCase();
            const rawMeta = item?.data?.notification_meta;
            const structuredMeta = rawMeta && typeof rawMeta === 'object' ? rawMeta : {};
            const severity = String(structuredMeta.severity || '').toLowerCase();
            const category = String(structuredMeta.category || '').toLowerCase();

            let css = 'info';
            let label = wbglNotificationTranslate('index.notifications.type.info', 'معلومة');
            if (severity === 'error' || normalizedType.includes('failure') || normalizedType.includes('error') || normalizedType.includes('reject')) {
                css = 'error';
                label = wbglNotificationTranslate('index.notifications.type.error', 'خطأ');
            } else if (severity === 'warning' || normalizedType.includes('warning') || normalizedType.includes('expiry') || normalizedType.includes('undo')) {
                css = 'warning';
                label = wbglNotificationTranslate('index.notifications.type.warning', 'تنبيه');
            } else if (severity === 'success' || normalizedType.includes('success') || normalizedType.includes('approved') || normalizedType.includes('executed')) {
                css = 'success';
                label = wbglNotificationTranslate('index.notifications.type.success', 'نجاح');
            }

            const categoryLabels = {
                workflow: wbglNotificationTranslate('index.notifications.category.workflow', 'سير العمل'),
                governance: wbglNotificationTranslate('index.notifications.category.governance', 'الحوكمة'),
                operations: wbglNotificationTranslate('index.notifications.category.operations', 'التشغيل'),
                security: wbglNotificationTranslate('index.notifications.category.security', 'الأمن'),
                data_quality: wbglNotificationTranslate('index.notifications.category.data_quality', 'جودة البيانات'),
                system: wbglNotificationTranslate('index.notifications.category.system', 'النظام'),
            };

            return {
                css: css,
                label: label,
                categoryLabel: categoryLabels[category] || wbglNotificationTranslate('index.notifications.category.general', 'عام'),
            };
        }

        function wbglUpdateUnreadBadge(count) {
            const badge = document.getElementById('taskUnreadBadge');
            const countEl = document.getElementById('taskUnreadCount');
            if (!badge || !countEl) return;

            countEl.textContent = String(Math.max(0, Number(count || 0)));
            if (count > 0) {
                badge.classList.remove('u-hidden');
            } else {
                badge.classList.add('u-hidden');
            }
        }

        function wbglGetOrderedNotificationCards(list = null) {
            const listEl = list || document.getElementById('notificationsList');
            if (!listEl) return [];
            return Array.from(listEl.querySelectorAll('.notification-card')).sort((a, b) => {
                const aIdx = Number(a.getAttribute('data-stack-index') || 0);
                const bIdx = Number(b.getAttribute('data-stack-index') || 0);
                return aIdx - bIdx;
            });
        }

        function wbglSetFocusedNotification(notificationId) {
            const list = document.getElementById('notificationsList');
            if (!list) return;

            const id = Number(notificationId || 0);
            wbglNotificationsState.focusedNotificationId = id > 0 ? id : null;

            list.querySelectorAll('.notification-card').forEach((card) => {
                const cardId = Number(card.getAttribute('data-notification-id') || 0);
                const isFocused = wbglNotificationsState.focusedNotificationId !== null
                    && cardId === wbglNotificationsState.focusedNotificationId;
                card.classList.toggle('is-focused', isFocused);
            });
        }

        function wbglToggleNotificationStackExpanded(expanded) {
            const list = document.getElementById('notificationsList');
            if (!list) return;

            const shouldExpand = Boolean(expanded);
            list.classList.toggle('is-expanded', shouldExpand);
            if (!shouldExpand) {
                wbglSetFocusedNotification(null);
                return;
            }

            const cards = wbglGetOrderedNotificationCards(list);
            if (cards.length === 0) return;

            const focusedId = Number(wbglNotificationsState.focusedNotificationId || 0);
            const hasFocused = cards.some((card) => Number(card.getAttribute('data-notification-id') || 0) === focusedId);
            if (!hasFocused) {
                const topCard = cards[0];
                wbglSetFocusedNotification(Number(topCard.getAttribute('data-notification-id') || 0));
            }
        }

        function wbglCycleFocusedNotification(direction) {
            const list = document.getElementById('notificationsList');
            if (!list) return;

            const cards = wbglGetOrderedNotificationCards(list);
            if (cards.length === 0) return;

            const ids = cards
                .map((card) => Number(card.getAttribute('data-notification-id') || 0))
                .filter((id) => id > 0);
            if (ids.length === 0) return;

            const currentId = Number(wbglNotificationsState.focusedNotificationId || 0);
            let currentIndex = ids.indexOf(currentId);
            if (currentIndex < 0) currentIndex = 0;

            const step = direction >= 0 ? 1 : -1;
            const nextIndex = (currentIndex + step + ids.length) % ids.length;
            wbglSetFocusedNotification(ids[nextIndex]);

            const target = cards[nextIndex];
            if (target && typeof target.focus === 'function') {
                target.focus({
                    preventScroll: true
                });
            }
        }

        function wbglBindNotificationInteractions() {
            if (wbglNotificationsState.interactionsBound) return;

            const list = document.getElementById('notificationsList');
            if (!list) return;

            wbglNotificationsState.interactionsBound = true;

            list.addEventListener('mouseenter', () => {
                wbglToggleNotificationStackExpanded(true);
            });

            list.addEventListener('mouseleave', () => {
                wbglToggleNotificationStackExpanded(false);
            });

            list.addEventListener('mouseover', (event) => {
                const card = event.target.closest('.notification-card');
                if (!card || !list.contains(card)) return;
                const id = Number(card.getAttribute('data-notification-id') || 0);
                if (id > 0) {
                    wbglSetFocusedNotification(id);
                }
            });

            list.addEventListener('focusin', (event) => {
                const card = event.target.closest('.notification-card');
                if (!card || !list.contains(card)) return;
                wbglToggleNotificationStackExpanded(true);
                const id = Number(card.getAttribute('data-notification-id') || 0);
                if (id > 0) {
                    wbglSetFocusedNotification(id);
                }
            });

            list.addEventListener('focusout', (event) => {
                const nextTarget = event.relatedTarget;
                if (!nextTarget || !list.contains(nextTarget)) {
                    wbglToggleNotificationStackExpanded(false);
                }
            });

            list.addEventListener('wheel', (event) => {
                // Keep native mouse wheel scroll in expanded list mode.
                if (list.classList.contains('is-expanded')) {
                    return;
                }
                if (!list.querySelector('.notification-card')) return;
                wbglToggleNotificationStackExpanded(true);
            });
        }

        function wbglRenderNotifications() {
            const section = document.getElementById('notificationsSection');
            const list = document.getElementById('notificationsList');
            if (!section || !list) return;
            wbglBindNotificationInteractions();
            const currentDir = wbglNotificationDirection();
            section.setAttribute('dir', currentDir);
            list.setAttribute('dir', currentDir);
            list.style.setProperty('--notifications-dir', currentDir);

            const nowMs = Date.now();
            const sortedItems = [...wbglNotificationsState.items].sort((a, b) => {
                const t1 = new Date(String(a.created_at || '').replace(' ', 'T')).getTime();
                const t2 = new Date(String(b.created_at || '').replace(' ', 'T')).getTime();
                return t1 - t2; // oldest -> newest
            });

            const activeItems = [];
            sortedItems.forEach((item) => {
                const itemId = Number(item?.id || 0);
                if (itemId > 0 && wbglNotificationSessionHiddenIds.has(itemId)) {
                    return;
                }
                const visibility = wbglResolveNotificationVisibility(item, nowMs);
                if (visibility.visible) {
                    activeItems.push(item);
                }
            });

            if (activeItems.length === 0) {
                wbglClearAutoDismissTimers();
                wbglNotificationsState.focusedNotificationId = null;
                list.innerHTML = '';
                list.classList.remove('is-expanded');
                section.classList.add('u-hidden');
                wbglUpdateUnreadBadge(0);
                return;
            }

            const renderItems = activeItems;
            const renderIds = new Set(renderItems.map((item) => Number(item?.id || 0)).filter((id) => id > 0));
            if (!renderIds.has(Number(wbglNotificationsState.focusedNotificationId || 0))) {
                wbglNotificationsState.focusedNotificationId = null;
            }
            list.style.setProperty('--cards-visible', String(renderItems.length));
            section.classList.remove('u-hidden');

            list.innerHTML = renderItems.map((item, idx) => {
                const meta = wbglNotificationTypeMeta(item);
                const resolvedText = wbglResolveNotificationText(item);
                const title = wbglEscapeHtml(resolvedText.title || wbglNotificationTranslate('index.notifications.default_title', 'إشعار'));
                const message = wbglEscapeHtml(resolvedText.message || '');
                const when = wbglEscapeHtml(wbglFormatNotificationTime(item.created_at));
                const category = wbglEscapeHtml(meta.categoryLabel);
                const readLabel = wbglEscapeHtml(wbglNotificationTranslate('index.notifications.actions.read', 'مقروء'));
                const hideLabel = wbglEscapeHtml(wbglNotificationTranslate('index.notifications.actions.hide', 'إخفاء'));
                const id = Number(item.id || 0);
                const stackIndex = renderItems.length - 1 - idx;
                const tilt = 0;
                const isFocused = Number(wbglNotificationsState.focusedNotificationId || 0) === id;
                const currentDir = wbglNotificationDirection();
                return `
                    <article class="notification-card notification-card--${meta.css}${isFocused ? ' is-focused' : ''}" data-notification-id="${id}" data-stack-index="${stackIndex}" style="--stack-index:${stackIndex}; --stack-tilt:${tilt}" dir="${currentDir}" tabindex="0">
                        <div class="notification-card-header">
                            <span class="notification-type-badge"><span class="notification-pin">📌</span>${wbglEscapeHtml(meta.label)}</span>
                            <span class="notification-time">${when}</span>
                        </div>
                        <div class="notification-title">${title}</div>
                        <div class="notification-category">${category}</div>
                        <div class="notification-message">${message}</div>
                        <div class="notification-actions">
                            <button class="notification-btn notification-btn--read" data-notification-action="mark_read" data-notification-id="${id}">${readLabel}</button>
                            <button class="notification-btn notification-btn--hide" data-notification-action="hide" data-notification-id="${id}">${hideLabel}</button>
                        </div>
                    </article>
                `;
            }).join('');

            wbglSetFocusedNotification(wbglNotificationsState.focusedNotificationId);
            wbglScheduleAutoDismiss(renderItems);
            wbglUpdateUnreadBadge(activeItems.length);
        }

        async function wbglLoadNotifications() {
            try {
                const res = await fetch('/api/notifications.php?unread=1&include_hidden=0&limit=' + encodeURIComponent(String(wbglNotificationUiLimit)), {
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json'
                    }
                });
                const payload = await res.json();
                const rows = Array.isArray(payload?.data?.data)
                    ? payload.data.data
                    : (Array.isArray(payload?.data) ? payload.data : []);
                wbglNotificationsState.items = rows;
                wbglRenderNotifications();
            } catch (err) {
                console.error('NOTIFICATIONS_LOAD_ERROR', err);
                wbglNotificationsState.items = [];
                wbglRenderNotifications();
            }
        }

        async function wbglNotificationAction(action, notificationId, options = {}) {
            const id = Number(notificationId || 0);
            if (!id) return;
            const silent = Boolean(options && options.silent);
            try {
                const res = await fetch('/api/notifications.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        action: action,
                        notification_id: id
                    })
                });
                const payload = await res.json();
                if (!payload?.success) {
                    throw new Error(payload?.error || 'notification action failed');
                }
                wbglNotificationsState.items = wbglNotificationsState.items.filter((item) => Number(item.id) !== id);
                wbglRenderNotifications();
            } catch (err) {
                console.error('NOTIFICATION_ACTION_ERROR', err);
                if (!silent) {
                    showToast(wbglNotificationTranslate('index.notifications.errors.update_failed', 'تعذر تحديث الإشعار'), 'error');
                }
            }
        }

        document.addEventListener('click', (event) => {
            const actionBtn = event.target.closest('[data-notification-action]');
            if (actionBtn) {
                event.preventDefault();
                const action = String(actionBtn.getAttribute('data-notification-action') || '');
                const notificationId = Number(actionBtn.getAttribute('data-notification-id') || 0);
                if (action === 'hide') {
                    wbglHideNotificationForSession(notificationId);
                    return;
                }
                wbglNotificationAction(
                    action,
                    notificationId
                );
                return;
            }
        });

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
                toast.style.animationName = 'slideOut';
                toast.style.animationDuration = '0.3s';
                toast.style.animationTimingFunction = 'ease';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }

        // Notes functionality - Vanilla JS
        function showNoteInput() {
            const noteBox = document.getElementById('noteInputBox');
            const addBtn = document.getElementById('addNoteBtn');
            const noteTextarea = document.getElementById('noteTextarea');
            if (!noteBox || !addBtn || !noteTextarea) return;
            noteBox.style.display = 'block';
            addBtn.style.display = 'none';
            noteTextarea.focus();
        }

        function cancelNote() {
            const noteBox = document.getElementById('noteInputBox');
            const addBtn = document.getElementById('addNoteBtn');
            const noteTextarea = document.getElementById('noteTextarea');
            if (!noteBox || !addBtn || !noteTextarea) return;
            noteBox.style.display = 'none';
            addBtn.style.display = 'block';
            noteTextarea.value = '';
        }

        async function saveNote() {
            const noteTextarea = document.getElementById('noteTextarea');
            if (!noteTextarea) return;
            const content = noteTextarea.value.trim();
            if (!content) return;

            try {
                const res = await fetch('api/save-note.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        guarantee_id: <?= $mockRecord['id'] ?? 0 ?>,
                        content: content
                    })
                });
                const data = await res.json();
                if (data.success) {
                    showToast(window.WBGLTranslate('index.ui.txt_e503c356', ''), 'success');
                    // Reload page to show new note
                    setTimeout(() => location.reload(), 500);
                } else {
                    showToast(window.WBGLTranslate('index.ui.txt_ae142be6', '') + ' ' + (data.error || window.WBGLTranslate('messages.error.unknown', '')), 'error');
                }
            } catch (e) {
                console.error('NOTE_SAVE_ERROR', e);
                showToast(window.WBGLTranslate('index.ui.txt_e81befca', ''), 'error');
            }
        }

        // Attachments functionality
        async function uploadFile(event) {
            if (!event || !event.target) return;
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
                    showToast(window.WBGLTranslate('index.ui.txt_505575bc', ''), 'success');
                    // Reload page to show new attachment
                    setTimeout(() => location.reload(), 500);
                } else {
                    showToast(window.WBGLTranslate('index.ui.txt_b3c22c05', '') + ' ' + (data.error || window.WBGLTranslate('messages.error.unknown', '')), 'error');
                }
            } catch (err) {
                console.error('UPLOAD_ATTACHMENT_ERROR', err);
                showToast(window.WBGLTranslate('index.ui.txt_8cf0a654', ''), 'error');
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
            wbglLoadSessionHiddenNotifications();
            wbglBindNotificationInteractions();
            wbglLoadNotifications();
            setInterval(() => wbglRenderNotifications(), 30000);
            setInterval(() => wbglLoadNotifications(), 90000);

            const previewSection = document.getElementById('preview-section');
            if (previewSection) {
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

        document.addEventListener('wbgl:language-changed', function() {
            wbglRenderNotifications();
        });

        document.addEventListener('wbgl:direction-changed', function() {
            wbglRenderNotifications();
        });
    </script>


    <script src="/public/js/pilot-auto-load.js?v=<?= time() ?>"></script>


    <!-- ✅ UX UNIFICATION: Old Level B handler and modal removed -->
    <!-- Level B handler disabled by UX_UNIFICATION_ENABLED flag -->
    <!-- Modal no longer needed - Selection IS the confirmation -->

    <script src="/public/js/preview-formatter.js?v=<?= time() ?>"></script>
    <script src="/public/js/print-audit.js?v=<?= time() ?>"></script>
    <script src="/public/js/main.js?v=<?= time() ?>"></script>
    <script src="/public/js/input-modals.controller.js?v=<?= time() ?>"></script>
    <script src="/public/js/timeline.controller.js?v=<?= time() ?>"></script>
    <script src="/public/js/records.controller.js?v=<?= time() ?>"></script>
</body>

</html>
