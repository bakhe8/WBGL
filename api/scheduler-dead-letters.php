<?php
declare(strict_types=1);

/**
 * API: Scheduler dead letters
 *
 * GET  /api/scheduler-dead-letters.php?status=open&limit=100
 * POST /api/scheduler-dead-letters.php { action: resolve, id, note? }
 * POST /api/scheduler-dead-letters.php { action: retry, id }
 */

require_once __DIR__ . '/_bootstrap.php';

use App\Services\SchedulerDeadLetterService;
use App\Support\Input;

wbgl_api_json_headers();
wbgl_api_require_permission('manage_users');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$currentUser = wbgl_api_current_user_display();

try {
    if ($method === 'GET') {
        $limit = Input::int($_GET, 'limit', 100) ?? 100;
        $status = Input::string($_GET, 'status', 'open');
        $status = $status !== '' ? $status : 'open';
        $rows = SchedulerDeadLetterService::list($limit, $status);
        echo json_encode([
            'success' => true,
            'data' => $rows,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'error' => 'Method not allowed',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }

    $action = Input::string($input, 'action', '');
    $id = Input::int($input, 'id', 0) ?? 0;
    if ($id <= 0) {
        throw new RuntimeException('id is required');
    }

    if ($action === 'resolve') {
        $note = Input::string($input, 'note', '');
        SchedulerDeadLetterService::resolve($id, $currentUser, $note !== '' ? $note : null);
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'retry') {
        $result = SchedulerDeadLetterService::retry($id, $currentUser);
        echo json_encode([
            'success' => true,
            'data' => $result,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    throw new RuntimeException('Unsupported action');
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
