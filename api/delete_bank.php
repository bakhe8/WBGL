<?php
require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;
use App\Support\Input;

header('Content-Type: application/json');

try {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        $data = [];
    }
    
    $bankId = Input::int($data, 'id');
    if (!$bankId) {
        throw new Exception('Missing ID');
    }

    $db = Database::connect();
    
    $stmt = $db->prepare("DELETE FROM banks WHERE id = ?");
    $result = $stmt->execute([$bankId]);
    
    if ($result) {
        echo json_encode(['success' => true]);
    } else {
        throw new Exception('Delete failed');
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
