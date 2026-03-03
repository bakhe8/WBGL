<?php
/**
 * V3 API - Get Timeline (Server-Driven Partial HTML)
 * Returns the HTML for the timeline sidebar
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../app/Services/TimelineDisplayService.php';

use App\Support\Database;
use App\Services\TimelineDisplayService;
use App\Services\UiSurfacePolicyService;

header('Content-Type: text/html; charset=utf-8');
wbgl_api_require_permission('timeline_view');

try {
    $index = isset($_GET['index']) ? (int)$_GET['index'] : 1;
    $statusFilter = isset($_GET['filter']) ? trim((string)$_GET['filter']) : (isset($_GET['status_filter']) ? trim((string)$_GET['status_filter']) : 'all');
    $stageFilter = isset($_GET['stage']) ? trim((string)$_GET['stage']) : null;
    if ($stageFilter === '') {
        $stageFilter = null;
    }
    $searchTerm = isset($_GET['search']) ? trim((string)$_GET['search']) : null;
    if ($searchTerm === '') {
        $searchTerm = null;
    }

    if ($index < 1) {
        throw new \RuntimeException('Invalid index');
    }

    $db = Database::connect();

    $guaranteeId = \App\Services\NavigationService::getIdByIndex(
        $db,
        $index,
        $statusFilter,
        $searchTerm,
        $stageFilter
    );

    $timeline = [];

    if ($guaranteeId) {
        $policy = wbgl_api_policy_for_guarantee($db, (int)$guaranteeId);
        if (!$policy['visible']) {
            wbgl_api_fail(403, 'Permission Denied');
        }

        $stmtDec = $db->prepare('SELECT status FROM guarantee_decisions WHERE guarantee_id = ? LIMIT 1');
        $stmtDec->execute([(int)$guaranteeId]);
        $decisionRow = $stmtDec->fetch(\PDO::FETCH_ASSOC) ?: [];

        $surface = UiSurfacePolicyService::forGuarantee(
            $policy,
            \App\Support\Guard::permissions(),
            (string)($decisionRow['status'] ?? 'pending')
        );

        if ($surface['can_view_timeline'] ?? false) {
            $timeline = TimelineDisplayService::getEventsForDisplay($db, (int)$guaranteeId);
        }
    }
    
    // Use the comprehensive partial for rendering
    include __DIR__ . '/../partials/timeline-section.php';

} catch (\Throwable $e) {
    http_response_code(500);
    echo '<div style="color:red">Error loading timeline: ' . $e->getMessage() . '</div>';
}
