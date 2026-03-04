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

require_once __DIR__ . '/_bootstrap.php';

use App\Support\Database;
use App\Services\BankManagementService;

wbgl_api_require_permission('bank_manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    wbgl_api_compat_fail(405, 'Method not allowed');
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        $data = [];
    }
    $db = Database::connect();
    
    $result = BankManagementService::create($db, $data);
    
    wbgl_api_compat_success($result);
    
} catch (\Throwable $e) {
    wbgl_api_compat_fail(400, $e->getMessage());
}
