<?php
/**
 * V3 API - Get Record by Index (Server-Driven Partial HTML)
 * Returns HTML fragment for record form section
 */

require_once __DIR__ . '/_bootstrap.php';

use App\Support\Database;
use App\Services\GetRecordPresentationService;
use App\Services\NavigationService;

header('Content-Type: text/html; charset=utf-8');
wbgl_api_require_login();

try {
    $index = isset($_GET['index']) ? intval($_GET['index']) : 1;
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
    $renderer = new GetRecordPresentationService($db);

    $guaranteeId = NavigationService::getIdByIndex(
        $db,
        $index,
        $statusFilter,
        $searchTerm,
        $stageFilter
    );

    if (!$guaranteeId) {
        echo $renderer->renderOutOfScopeEmptyState();
        return;
    }

    $policy = wbgl_api_policy_for_guarantee($db, (int)$guaranteeId);
    if (!$policy['visible']) {
        wbgl_api_fail(403, 'Permission Denied');
    }

    echo $renderer->renderRecordSection((int)$guaranteeId, $index, $policy);
} catch (\Throwable $e) {
    http_response_code(500);
    echo GetRecordPresentationService::renderError($e->getMessage());
}
