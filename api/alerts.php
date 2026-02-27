<?php
declare(strict_types=1);

/**
 * API: Operational Alerts Snapshot (Wave-3)
 *
 * GET /api/alerts.php
 */

require_once __DIR__ . '/_bootstrap.php';

use App\Services\OperationalAlertService;
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
    $metrics = OperationalMetricsService::snapshot();
    $alerts = OperationalAlertService::evaluate($metrics);

    echo json_encode([
        'success' => true,
        'data' => [
            'metrics' => $metrics,
            'alerts' => $alerts,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to build alerts snapshot',
    ], JSON_UNESCAPED_UNICODE);
}
