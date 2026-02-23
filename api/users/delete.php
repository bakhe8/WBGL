<?php

/**
 * API Endpoint: Delete User
 */

require_once __DIR__ . '/../../app/Support/autoload.php';

use App\Support\AuthService;
use App\Support\Database;
use App\Support\Guard;
use App\Repositories\UserRepository;

header('Content-Type: application/json; charset=utf-8');

// 1. Auth Check
if (!AuthService::isLoggedIn() || !Guard::has('manage_users')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission Denied']);
    exit;
}

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

    // Check if user exists
    if (!$repo->find((int)$userId)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'المستخدم غير موجود']);
        exit;
    }

    $repo->delete((int)$userId);

    echo json_encode(['success' => true, 'message' => 'تم حذف المستخدم بنجاح']);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
