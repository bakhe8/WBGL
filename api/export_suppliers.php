<?php
require_once __DIR__ . '/../app/Support/Database.php';
use App\Support\Database;

header('Content-Type: application/json');
header('Content-Disposition: attachment; filename="suppliers.json"');

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
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
