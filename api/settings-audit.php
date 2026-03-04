<?php
declare(strict_types=1);

/**
 * API: Settings audit log
 *
 * GET /api/settings-audit.php?limit=100&key=MATCH_AUTO_THRESHOLD
 */

require_once __DIR__ . '/_bootstrap.php';

use App\Services\SettingsAuditService;
use App\Support\Input;

wbgl_api_require_permission('manage_users');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        wbgl_api_compat_fail(405, 'Method not allowed');
    }

    $limit = Input::int($_GET, 'limit', 100) ?? 100;
    $key = Input::string($_GET, 'key', '');
    $key = $key !== '' ? $key : null;

    $rows = SettingsAuditService::listRecent($limit, $key);
    wbgl_api_compat_success([
        'data' => $rows,
    ]);
} catch (Throwable $e) {
    wbgl_api_compat_fail(400, $e->getMessage());
}
