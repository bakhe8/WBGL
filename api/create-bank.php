<?php
/**
 * Create Bank - Unified API
 * 
 * Combines features from both add-bank.php and create_bank.php:
 * - Aliases support (from add-bank)
 * - Contact details support (from create_bank)
 * 
 * @version 2.0 (unified)
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;
use App\Services\BankManagementService;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        $data = [];
    }
    $db = Database::connect();
    
    $result = BankManagementService::create($db, $data);
    
    echo json_encode($result);
    
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
