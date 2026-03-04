<?php
declare(strict_types=1);

/**
 * API: Undo Requests Workflow
 *
 * Actions:
 * - GET  ?action=list&status=pending&limit=100
 * - POST { action: submit, guarantee_id, reason }
 * - POST { action: approve, request_id, note? }
 * - POST { action: reject, request_id, note? }
 * - POST { action: execute, request_id }
 */

require_once __DIR__ . '/_bootstrap.php';

use App\Services\UndoRequestService;
use App\Support\Input;

wbgl_api_require_permission('manage_data');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$user = wbgl_api_current_user_display();

try {
    if ($method === 'GET') {
        $action = Input::string($_GET, 'action', 'list');
        if ($action !== 'list') {
            throw new RuntimeException('Unsupported GET action');
        }

        $status = Input::string($_GET, 'status', '');
        $status = $status !== '' ? $status : null;
        $limit = Input::int($_GET, 'limit', 100) ?? 100;
        $guaranteeId = Input::int($_GET, 'guarantee_id');

        $rows = UndoRequestService::list($status, $limit, $guaranteeId);
        wbgl_api_compat_success([
            'data' => $rows,
        ]);
    }

    if ($method !== 'POST') {
        wbgl_api_compat_fail(405, 'Method not allowed');
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }
    $action = Input::string($input, 'action', '');

    switch ($action) {
        case 'submit':
            $guaranteeId = Input::int($input, 'guarantee_id', 0) ?? 0;
            $reason = Input::string($input, 'reason', '');
            wbgl_api_require_guarantee_visibility((int)$guaranteeId);
            $id = UndoRequestService::submit($guaranteeId, $reason, $user);
            wbgl_api_compat_success([
                'request_id' => $id,
            ]);
            break;

        case 'approve':
            $requestId = Input::int($input, 'request_id', 0) ?? 0;
            $note = Input::string($input, 'note', '');
            UndoRequestService::approve($requestId, $user, $note);
            wbgl_api_compat_success([]);
            break;

        case 'reject':
            $requestId = Input::int($input, 'request_id', 0) ?? 0;
            $note = Input::string($input, 'note', '');
            UndoRequestService::reject($requestId, $user, $note);
            wbgl_api_compat_success([]);
            break;

        case 'execute':
            $requestId = Input::int($input, 'request_id', 0) ?? 0;
            UndoRequestService::execute($requestId, $user);
            wbgl_api_compat_success([]);
            break;

        default:
            throw new RuntimeException('Unsupported action');
    }
} catch (Throwable $e) {
    wbgl_api_compat_fail(400, $e->getMessage(), [], 'validation');
}
