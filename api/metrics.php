<?php
declare(strict_types=1);

/**
 * API: Operational Metrics Snapshot (Wave-3 seed)
 *
 * GET /api/metrics.php
 */

require_once __DIR__ . '/_bootstrap.php';

use App\Services\OperationalMetricsService;

wbgl_api_json_headers();
wbgl_api_require_permission('manage_users');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $snapshot = OperationalMetricsService::snapshot();
    echo json_encode([
        'success' => true,
        'data' => $snapshot,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to build operational metrics snapshot',
    ], JSON_UNESCAPED_UNICODE);
}
