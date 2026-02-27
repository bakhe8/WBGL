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

wbgl_api_json_headers();
wbgl_api_require_permission('manage_users');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'error' => 'Method not allowed',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $limit = Input::int($_GET, 'limit', 100) ?? 100;
    $key = Input::string($_GET, 'key', '');
    $key = $key !== '' ? $key : null;

    $rows = SettingsAuditService::listRecent($limit, $key);
    echo json_encode([
        'success' => true,
        'data' => $rows,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
