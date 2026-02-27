<?php

/**
 * API Endpoint: Delete User
 */

require_once __DIR__ . '/../_bootstrap.php';

use App\Services\AuditTrailService;
use App\Support\AuthService;
use App\Support\Database;
use App\Repositories\UserRepository;

header('Content-Type: application/json; charset=utf-8');
wbgl_api_require_permission('manage_users');

$input = json_decode(file_get_contents('php://input'), true);
$userId = $input['user_id'] ?? null;

if (!$userId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing user_id']);
    exit;
}

try {
    $db = Database::connect();
    $repo = new UserRepository($db);

    // Sanity Checks
    $currentUser = AuthService::getCurrentUser();
    if ($currentUser && $currentUser->id == $userId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'لا يمكنك حذف حسابك الحالي']);
        exit;
    }

    $user = $repo->find((int)$userId);
    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'المستخدم غير موجود']);
        exit;
    }

    $repo->delete((int)$userId);

    AuditTrailService::record(
        'user_deleted',
        'delete',
        'user',
        (string)$userId,
        [
            'username' => $user->username,
            'full_name' => $user->fullName,
            'role_id' => $user->roleId,
        ],
        'critical'
    );

    echo json_encode(['success' => true, 'message' => 'تم حذف المستخدم بنجاح']);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
