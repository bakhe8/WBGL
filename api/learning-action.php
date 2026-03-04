<?php
require_once __DIR__ . '/_bootstrap.php';

use App\Support\Database;
use App\Support\Input;

wbgl_api_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    wbgl_api_compat_fail(405, 'Method not allowed');
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        $data = [];
    }
    $id = Input::int($data, 'id');
    $action = Input::string($data, 'action', ''); // 'delete'

    if (!$id || $action === '') {
        wbgl_api_compat_fail(400, 'Missing parameters');
    }

    $db = Database::connect();

    if ($action === 'delete') {
        $stmt = $db->prepare("DELETE FROM learning_confirmations WHERE id = ?");
        $stmt->execute([$id]);
        
        wbgl_api_compat_success(['message' => 'Item deleted']);
    } else {
        wbgl_api_compat_fail(400, 'Invalid action');
    }

} catch (Exception $e) {
    wbgl_api_compat_fail(500, $e->getMessage(), [], 'internal');
}
