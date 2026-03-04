<?php
/**
 * API: Get History Snapshot
 * Fetches a specific history event and renders the record form as it was at that time.
 */

require_once __DIR__ . '/_bootstrap.php';

use App\Support\Database;
use App\Services\TimelineReadPresentationService;

header('Content-Type: text/html; charset=utf-8');
wbgl_api_require_permission('timeline_view');

try {
    if (!isset($_GET['history_id'])) {
        throw new Exception('History ID is required');
    }

    $rawHistoryId = trim((string)$_GET['history_id']);
    if ($rawHistoryId === '' || preg_match('/^\d+$/', $rawHistoryId) !== 1) {
        throw new Exception('history_id must be a numeric event id');
    }

    $historyId = (int)$rawHistoryId;
    $index = isset($_GET['index']) ? (int)$_GET['index'] : 1;
    $db = Database::connect();
    $timelineService = new TimelineReadPresentationService($db);
    $result = $timelineService->renderHistorySnapshot($historyId, $index);
    if ($result['forbidden']) {
        wbgl_api_fail(403, 'Permission Denied');
    }
    echo (string)($result['html'] ?? '');

} catch (Throwable $e) {
    http_response_code(500);
    $fallbackIndex = isset($_GET['index']) ? (int)$_GET['index'] : 1;
    echo TimelineReadPresentationService::renderHistoryError($e->getMessage(), $fallbackIndex);
}
