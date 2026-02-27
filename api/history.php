<?php
declare(strict_types=1);

/**
 * Legacy endpoint retired.
 *
 * Use:
 * - /api/get-timeline.php
 * - /api/get-history-snapshot.php
 */

require_once __DIR__ . '/_bootstrap.php';

use App\Support\Logger;

header('Content-Type: application/json; charset=utf-8');
wbgl_api_require_login();

http_response_code(410);

Logger::info('legacy_endpoint_retired', [
    'endpoint' => 'api/history.php',
    'user' => wbgl_api_current_user_display(),
    'query' => $_GET,
]);

echo json_encode([
    'success' => false,
    'error' => 'api/history.php retired',
    'code' => 'LEGACY_ENDPOINT_RETIRED',
    'replacement' => [
        'timeline' => '/api/get-timeline.php',
        'snapshot' => '/api/get-history-snapshot.php',
    ],
], JSON_UNESCAPED_UNICODE);
