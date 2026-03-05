<?php
declare(strict_types=1);

/**
 * API: Notification Inbox
 *
 * GET  /api/notifications.php?unread=1&include_hidden=0&limit=50
 * POST /api/notifications.php { action: mark_read, notification_id }
 * POST /api/notifications.php { action: hide, notification_id }
 * POST /api/notifications.php { action: mark_all_read }
 */

require_once __DIR__ . '/_bootstrap.php';

use App\Services\NotificationService;
use App\Support\Input;

wbgl_api_require_login();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET') {
        $limit = Input::int($_GET, 'limit', 50) ?? 50;
        $unread = Input::int($_GET, 'unread', 0) === 1;
        $includeHidden = Input::int($_GET, 'include_hidden', 0) === 1;
        $rows = NotificationService::listForCurrentUser($limit, $unread, $includeHidden);
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

    if ($action === 'mark_read') {
        $id = Input::int($input, 'notification_id', 0) ?? 0;
        NotificationService::markReadForCurrentUser($id);
        wbgl_api_compat_success([]);
    }

    if ($action === 'hide') {
        $id = Input::int($input, 'notification_id', 0) ?? 0;
        NotificationService::hideForCurrentUser($id);
        wbgl_api_compat_success([]);
    }

    if ($action === 'mark_all_read') {
        $count = NotificationService::markAllReadForCurrentUser();
        wbgl_api_compat_success(['updated' => $count]);
    }

    throw new RuntimeException('Unsupported action');
} catch (Throwable $e) {
    wbgl_api_compat_fail(400, $e->getMessage(), [], 'validation');
}
