<?php
declare(strict_types=1);

/**
 * API: Operational Metrics Snapshot (Wave-3 seed)
 *
 * GET /api/metrics.php
 */

require_once __DIR__ . '/_bootstrap.php';

use App\Services\OperationalMetricsService;

wbgl_api_require_permission('manage_users');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') {
    wbgl_api_compat_fail(405, 'Method not allowed');
}

try {
    $snapshot = OperationalMetricsService::snapshot();
    wbgl_api_compat_success([
        'data' => $snapshot,
    ]);
} catch (Throwable $e) {
    wbgl_api_compat_fail(500, 'Failed to build operational metrics snapshot', [], 'internal');
}
