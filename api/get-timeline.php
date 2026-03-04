<?php
/**
 * V3 API - Get Timeline (Server-Driven Partial HTML)
 * Returns the HTML for the timeline sidebar
 */

require_once __DIR__ . '/_bootstrap.php';

use App\Support\Database;
use App\Services\TimelineReadPresentationService;

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
    $timelineService = new TimelineReadPresentationService($db);
    $result = $timelineService->renderTimelineByIndex(
        $index,
        $statusFilter,
        $searchTerm,
        $stageFilter
    );

    if ($result['forbidden']) {
        wbgl_api_fail(403, 'Permission Denied');
    }
    echo (string)($result['html'] ?? '');

} catch (\Throwable $e) {
    http_response_code(500);
    echo '<div style="color:red">Error loading timeline: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
}
