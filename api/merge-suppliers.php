<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use App\Services\SupplierMergeService;
use App\Support\Database;

wbgl_api_require_permission('supplier_manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    wbgl_api_compat_fail(405, 'Invalid request method');
}

$input = json_decode(file_get_contents('php://input'), true);
$sourceId = isset($input['source_id']) ? (int)$input['source_id'] : 0;
$targetId = isset($input['target_id']) ? (int)$input['target_id'] : 0;

if (!$sourceId || !$targetId) {
    wbgl_api_compat_fail(400, 'Ids and Target IDs are required');
}

try {
    $db = Database::connect();
    $mergeService = new SupplierMergeService($db);
    $result = $mergeService->merge($sourceId, $targetId);
    
    wbgl_api_compat_success([
        'success' => (bool)$result,
    ]);
} catch (Exception $e) {
    wbgl_api_compat_fail(400, $e->getMessage());
}
