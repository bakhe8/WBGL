<?php
declare(strict_types=1);

/**
 * API: Print Events Governance
 *
 * GET  /api/print-events.php?guarantee_id=123&limit=50
 * POST /api/print-events.php
 * {
 *   "event_type": "preview_opened|print_requested",
 *   "context": "single_letter|batch_letter",
 *   "guarantee_id": 123,
 *   "guarantee_ids": [123, 124],
 *   "batch_identifier": "batch_...",
 *   "channel": "browser",
 *   "source_page": "/views/batch-print.php",
 *   "meta": {...}
 * }
 */

require_once __DIR__ . '/_bootstrap.php';

use App\Services\GuaranteeVisibilityService;
use App\Services\PrintAuditService;
use App\Support\Input;

wbgl_api_require_login();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET') {
        $guaranteeId = Input::int($_GET, 'guarantee_id', 0) ?? 0;
        if ($guaranteeId <= 0) {
            throw new RuntimeException('guarantee_id is required');
        }

        if (!GuaranteeVisibilityService::canAccessGuarantee($guaranteeId)) {
            throw new RuntimeException('Permission Denied');
        }

        $limit = Input::int($_GET, 'limit', 100) ?? 100;
        $rows = PrintAuditService::listByGuarantee($guaranteeId, $limit);
        wbgl_api_compat_success([
            'data' => $rows,
        ]);
    }

    if ($method !== 'POST') {
        wbgl_api_compat_fail(405, 'Method not allowed');
    }

    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }

    $eventType = Input::string($input, 'event_type', '');
    $context = Input::string($input, 'context', '');
    $batchIdentifier = Input::string($input, 'batch_identifier', '');
    $batchIdentifier = $batchIdentifier !== '' ? $batchIdentifier : null;
    $sourcePage = Input::string($input, 'source_page', '');
    $sourcePage = $sourcePage !== '' ? $sourcePage : null;
    $channel = Input::string($input, 'channel', 'browser');
    $meta = Input::array($input, 'meta', []) ?? [];

    $guaranteeIds = [];
    $singleId = Input::int($input, 'guarantee_id');
    if ($singleId !== null && $singleId > 0) {
        $guaranteeIds[] = $singleId;
    }
    $many = Input::array($input, 'guarantee_ids', []);
    foreach ($many as $value) {
        if (is_numeric($value)) {
            $id = (int)$value;
            if ($id > 0) {
                $guaranteeIds[] = $id;
            }
        }
    }
    $guaranteeIds = array_values(array_unique($guaranteeIds));

    foreach ($guaranteeIds as $guaranteeId) {
        if (!GuaranteeVisibilityService::canAccessGuarantee((int)$guaranteeId)) {
            throw new RuntimeException('Permission Denied');
        }
    }

    $result = PrintAuditService::record(
        $eventType,
        $context,
        $guaranteeIds,
        wbgl_api_current_user_display(),
        $batchIdentifier,
        is_array($meta) ? $meta : [],
        $channel,
        $sourcePage
    );

    wbgl_api_compat_success([
        'data' => $result,
    ]);
} catch (Throwable $e) {
    wbgl_api_compat_fail(400, $e->getMessage(), [], 'validation');
}
