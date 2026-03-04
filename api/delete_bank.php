<?php
require_once __DIR__ . '/_bootstrap.php';

use App\Support\Database;
use App\Support\Input;

wbgl_api_require_permission('bank_manage');

try {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        $data = [];
    }
    
    $bankId = Input::int($data, 'id');
    if (!$bankId) {
        wbgl_api_compat_fail(400, 'Missing ID');
    }

    $db = Database::connect();
    
    $stmt = $db->prepare("DELETE FROM banks WHERE id = ?");
    $result = $stmt->execute([$bankId]);
    
    if ($result) {
        wbgl_api_compat_success(['success' => true]);
    } else {
        throw new Exception('Delete failed');
    }

} catch (Exception $e) {
    wbgl_api_compat_fail(500, $e->getMessage(), [], 'internal');
}
