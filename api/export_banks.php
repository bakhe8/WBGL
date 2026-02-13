<?php
require_once __DIR__ . '/../app/Support/Database.php';
use App\Support\Database;

header('Content-Type: application/json');
header('Content-Disposition: attachment; filename="banks.json"');

try {
    $db = Database::connect();
    
    // 1. Fetch all banks
    $result = $db->query('
        SELECT id, arabic_name, english_name, short_name, department, address_line1, contact_email 
        FROM banks
    ');
    $banks = $result->fetchAll(PDO::FETCH_ASSOC);

    // 2. Fetch aliases for each bank
    $aliasStmt = $db->prepare('SELECT alternative_name FROM bank_alternative_names WHERE bank_id = ?');

    foreach ($banks as &$bank) {
        $aliasStmt->execute([$bank['id']]);
        $aliases = $aliasStmt->fetchAll(PDO::FETCH_COLUMN);
        $bank['aliases'] = $aliases;
    }

    echo json_encode($banks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
