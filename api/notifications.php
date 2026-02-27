<?php
declare(strict_types=1);

/**
 * API: Notification Inbox
 *
 * GET  /api/notifications.php?unread=1&limit=50
 * POST /api/notifications.php { action: mark_read, notification_id }
 * POST /api/notifications.php { action: mark_all_read }
 */

require_once __DIR__ . '/_bootstrap.php';

use App\Services\NotificationService;
use App\Support\Input;

wbgl_api_json_headers();
wbgl_api_require_login();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET') {
        $limit = Input::int($_GET, 'limit', 50) ?? 50;
        $unread = Input::int($_GET, 'unread', 0) === 1;
        $rows = NotificationService::listForCurrentUser($limit, $unread);
        echo json_encode([
            'success' => true,
            'data' => $rows,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }
    $action = Input::string($input, 'action', '');

    if ($action === 'mark_read') {
        $id = Input::int($input, 'notification_id', 0) ?? 0;
        NotificationService::markReadForCurrentUser($id);
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'mark_all_read') {
        $count = NotificationService::markAllReadForCurrentUser();
        echo json_encode(['success' => true, 'updated' => $count], JSON_UNESCAPED_UNICODE);
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
