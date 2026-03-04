<?php
require_once __DIR__ . '/_bootstrap.php';

use App\Support\Database;

wbgl_api_require_login();

try {
    $db = Database::connect();

    $fetchAggregated = static function (PDO $db, string $action): array {
        $stmt = $db->prepare("
            SELECT
                MIN(lc.id) as id,
                lc.raw_supplier_name as pattern,
                lc.supplier_id,
                MAX(s.official_name) as official_name,
                MAX(lc.matched_anchor) as matched_anchor,
                COUNT(*) as count,
                MAX(lc.updated_at) as updated_at
            FROM learning_confirmations lc
            LEFT JOIN suppliers s ON lc.supplier_id = s.id
            WHERE lc.action = :action
            GROUP BY lc.raw_supplier_name, lc.supplier_id
            ORDER BY MAX(lc.updated_at) DESC
            LIMIT 100
        ");
        $stmt->execute(['action' => $action]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };

    $confirmations = $fetchAggregated($db, 'confirm');
    $rejections = $fetchAggregated($db, 'reject');

    wbgl_api_compat_success([
        'confirmations' => $confirmations,
        'rejections' => $rejections
    ]);

} catch (Exception $e) {
    wbgl_api_compat_fail(500, $e->getMessage(), [], 'internal');
}
