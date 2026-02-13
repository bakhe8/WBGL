<?php
require __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;

header('Content-Type: application/json');

try {
    $db = Database::connect();

    // 1. Fetch Confirmations
    $stmt = $db->query("
        SELECT 
            lc.id,
            lc.raw_supplier_name as pattern,
            lc.supplier_id,
            s.official_name,
            lc.matched_anchor,
            lc.count,
            lc.updated_at
        FROM learning_confirmations lc
        LEFT JOIN suppliers s ON lc.supplier_id = s.id
        WHERE lc.action = 'confirm'
        ORDER BY lc.updated_at DESC
        LIMIT 100
    ");
    $confirmations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Fetch Rejections (Penalized Items)
    $stmt = $db->query("
        SELECT 
            lc.id,
            lc.raw_supplier_name as pattern,
            lc.supplier_id,
            s.official_name,
            lc.matched_anchor,
            lc.count,
            lc.updated_at
        FROM learning_confirmations lc
        left join suppliers s ON lc.supplier_id = s.id
        WHERE lc.action = 'reject'
        ORDER BY lc.updated_at DESC
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
