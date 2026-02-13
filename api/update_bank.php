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
    
    // ✅ This function UPDATES existing bank data only
    // ID is used to IDENTIFY the record, not to change it
    // ID should NEVER be modified - it's immutable
    $stmt = $db->prepare("
        UPDATE banks 
        SET 
            arabic_name = ?,
            english_name = ?,
            short_name = ?,
            department = ?,
            address_line1 = ?,
            contact_email = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE CAST(id AS INTEGER) = ?
    ");
    
    
    // Execute with explicit non-null values
    $result = $stmt->execute([
        Input::string($data, 'arabic_name', ''),
        Input::string($data, 'english_name', ''),
        Input::string($data, 'short_name', ''),
        Input::string($data, 'department', ''),
        Input::string($data, 'address_line1', ''),
        Input::string($data, 'contact_email', ''),
        $bankId
    ]);
    
    if (!$result) {
        throw new Exception('Update execution failed');
    }
    
    // ✅ Verify the bank still exists (using direct query due to SQLite PDO bug)
    // Note: rows affected = 0 is OK if data didn't change
    // IMPORTANT: Using direct query because prepared statements fail for some IDs in SQLite
    $verifyStmt = $db->query("SELECT id FROM banks WHERE id = $bankId");
    $verified = $verifyStmt->fetchColumn();
    
    if (!$verified) {
        throw new Exception('Critical: Bank ID was lost during update!');
    }
    
    echo json_encode(['success' => true, 'updated' => $stmt->rowCount() > 0]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
