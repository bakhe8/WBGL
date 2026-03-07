<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use App\Services\TimelineReadPresentationService;
use App\Support\Database;

header('Content-Type: application/json; charset=utf-8');
wbgl_api_require_permission('timeline_view');

try {
    $db = Database::connect();
    $service = new TimelineReadPresentationService($db);

    $index = isset($_GET['index']) ? (int)$_GET['index'] : 1;
    if ($index < 1) {
        $index = 1;
    }

    $historyIdRaw = trim((string)($_GET['history_id'] ?? ''));
    $guaranteeIdRaw = trim((string)($_GET['guarantee_id'] ?? ''));

    if ($historyIdRaw !== '') {
        if (preg_match('/^\d+$/', $historyIdRaw) !== 1) {
            wbgl_api_compat_fail(400, 'history_id must be numeric');
        }

        $historyId = (int)$historyIdRaw;
        $payload = $service->renderHistoryViewState($historyId, $index);
        if (!empty($payload['forbidden'])) {
            wbgl_api_compat_fail(403, 'Permission Denied', [], 'permission');
        }
        wbgl_api_compat_success($payload);
    }

    if ($guaranteeIdRaw !== '') {
        if (preg_match('/^\d+$/', $guaranteeIdRaw) !== 1) {
            wbgl_api_compat_fail(400, 'guarantee_id must be numeric');
        }

        $guaranteeId = (int)$guaranteeIdRaw;
        $payload = $service->renderCurrentViewState($guaranteeId, $index);
        if (!empty($payload['forbidden'])) {
            wbgl_api_compat_fail(403, 'Permission Denied', [], 'permission');
        }
        wbgl_api_compat_success($payload);
    }

    wbgl_api_compat_fail(400, 'Either history_id or guarantee_id is required');
} catch (\Throwable $e) {
    wbgl_api_compat_fail(500, $e->getMessage(), [], 'internal');
}

