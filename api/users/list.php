<?php

/**
 * API Endpoint: List Users
 * Returns all system users with their roles
 */

require_once __DIR__ . '/../../app/Support/autoload.php';

use App\Support\AuthService;
use App\Support\Database;
use App\Support\Guard;

header('Content-Type: application/json; charset=utf-8');

// 1. Auth Check - Only users with 'manage_users' permission
if (!AuthService::isLoggedIn() || !Guard::has('manage_users')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission Denied']);
    exit;
}

try {
    $db = Database::connect();

    // Fetch users with roles
    $stmt = $db->query("
        SELECT u.id, u.username, u.full_name, u.email, u.last_login, u.role_id, r.name as role_name, r.slug as role_slug
        FROM users u
        LEFT JOIN roles r ON u.role_id = r.id
        ORDER BY u.created_at DESC
    ");

    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch all roles for the dropdown selection
    $stmtRoles = $db->query("SELECT id, name FROM roles ORDER BY id ASC");
    $roles = $stmtRoles->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'users' => $users,
        'roles' => $roles
    ]);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
