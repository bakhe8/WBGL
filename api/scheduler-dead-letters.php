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

wbgl_api_require_permission('manage_users');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$currentUser = wbgl_api_current_user_display();

try {
    if ($method === 'GET') {
        $limit = Input::int($_GET, 'limit', 100) ?? 100;
        $status = Input::string($_GET, 'status', 'open');
        $status = $status !== '' ? $status : 'open';
        $rows = SchedulerDeadLetterService::list($limit, $status);
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

    $action = Input::string($input, 'action', '');
    $id = Input::int($input, 'id', 0) ?? 0;
    if ($id <= 0) {
        throw new RuntimeException('id is required');
    }

    if ($action === 'resolve') {
        $note = Input::string($input, 'note', '');
        SchedulerDeadLetterService::resolve($id, $currentUser, $note !== '' ? $note : null);
        wbgl_api_compat_success([]);
    }

    if ($action === 'retry') {
        $result = SchedulerDeadLetterService::retry($id, $currentUser);
        wbgl_api_compat_success([
            'data' => $result,
        ]);
    }

    throw new RuntimeException('Unsupported action');
} catch (Throwable $e) {
    wbgl_api_compat_fail(400, $e->getMessage(), [], 'validation');
}
