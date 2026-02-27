<?php
require_once __DIR__ . '/_bootstrap.php';

use App\Support\Database;

header('Content-Type: application/json');
wbgl_api_require_login();

try {
    $db = Database::connect();

    // 1. Fetch Confirmations (Aggregated)
    $stmt = $db->query("
        SELECT 
            MIN(lc.id) as id,
            lc.raw_supplier_name as pattern,
            lc.supplier_id,
            s.official_name,
            lc.matched_anchor,
            COUNT(*) as count,
            MAX(lc.updated_at) as updated_at
        FROM learning_confirmations lc
        LEFT JOIN suppliers s ON lc.supplier_id = s.id
        WHERE lc.action = 'confirm'
        GROUP BY lc.raw_supplier_name, lc.supplier_id
        ORDER BY updated_at DESC
        LIMIT 100
    ");
    $confirmations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Fetch Rejections (Aggregated)
    $stmt = $db->query("
        SELECT 
            MIN(lc.id) as id,
            lc.raw_supplier_name as pattern,
            lc.supplier_id,
            s.official_name,
            lc.matched_anchor,
            COUNT(*) as count,
            MAX(lc.updated_at) as updated_at
        FROM learning_confirmations lc
        LEFT JOIN suppliers s ON lc.supplier_id = s.id
        WHERE lc.action = 'reject'
        GROUP BY lc.raw_supplier_name, lc.supplier_id
        ORDER BY updated_at DESC
        LIMIT 100
    ");
    $rejections = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'confirmations' => $confirmations,
        'rejections' => $rejections
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
