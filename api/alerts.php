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

wbgl_api_require_permission('manage_users');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') {
    wbgl_api_compat_fail(405, 'Method not allowed');
}

try {
    $metrics = OperationalMetricsService::snapshot();
    $alerts = OperationalAlertService::evaluate($metrics);

    wbgl_api_compat_success([
        'data' => [
            'metrics' => $metrics,
            'alerts' => $alerts,
        ],
    ]);
} catch (Throwable $e) {
    wbgl_api_compat_fail(500, 'Failed to build alerts snapshot', [], 'internal');
}
