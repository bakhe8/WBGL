<?php
require_once __DIR__ . '/_bootstrap.php';
use App\Support\Database;

header('Content-Type: application/json');
header('Content-Disposition: attachment; filename="suppliers.json"');
wbgl_api_require_login();

try {
    $db = Database::connect();
    // Only select displayed fields (excluding dates)
    $result = $db->query('
        SELECT id, official_name, english_name, is_confirmed 
        FROM suppliers
    ');
    
    $suppliers = $result->fetchAll();
    echo json_encode($suppliers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    if (function_exists('header_remove')) {
        @header_remove('Content-Disposition');
    }
    wbgl_api_compat_fail(500, $e->getMessage(), [], 'internal');
}
