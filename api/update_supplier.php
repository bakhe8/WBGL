<?php
require_once __DIR__ . '/_bootstrap.php';
use App\Support\Database;
use App\Support\Input;
use App\Support\Normalizer;

wbgl_api_require_permission('supplier_manage');

try {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        $data = [];
    }
    
    $supplierId = Input::int($data, 'id');
    if (!$supplierId) {
        wbgl_api_compat_fail(400, 'Missing ID');
    }
    
    $officialName = Input::string($data, 'official_name', '');
    if ($officialName === '') {
        wbgl_api_compat_fail(400, 'Official name is required');
    }

    // Auto-normalize
    $normalizer = new Normalizer();
    $normalizedName = $normalizer->normalizeSupplierName($officialName);

    $db = Database::connect();
    
    $stmt = $db->prepare("
        UPDATE suppliers
        SET 
            official_name = ?,
            english_name = ?,
            normalized_name = ?,
            is_confirmed = ?
        WHERE id = ?
    ");
    
    
    // ✅ Smart Validation: Prevent Arabic in English Name field
    $englishName = Input::string($data, 'english_name', '');
    if (preg_match('/\p{Arabic}/u', $englishName)) {
        // Option A: Ignore it (Save as NULL) - Keeps data clean
        $englishName = null;
    }

    // ✅ Reverse Smart: If Official Name is English (No Arabic) AND English field is empty
    // Auto-populate English Name (Assumes it's a foreign company)
    if (!preg_match('/\p{Arabic}/u', $officialName) && empty($englishName)) {
        $englishName = $officialName;
    }

    // ✅ FIX: Protect against ID loss
    $result = $stmt->execute([
        $officialName,
        $englishName,
        $normalizedName,
        Input::bool($data, 'is_confirmed', false) ? 1 : 0,
        $supplierId
    ]);
    
    if (!$result) {
        throw new Exception('Update execution failed');
    }
    
    // ✅ Verify ID preservation
    $verifyStmt = $db->prepare('SELECT id FROM suppliers WHERE id = ?');
    $verifyStmt->execute([$supplierId]);
    if (!$verifyStmt->fetchColumn()) {
        throw new Exception('Critical: Supplier ID was lost during update!');
    }
    
    wbgl_api_compat_success([
        'success' => true,
        'updated' => $stmt->rowCount() > 0,
    ]);

} catch (Exception $e) {
    wbgl_api_compat_fail(500, $e->getMessage(), [], 'internal');
}
