<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use App\Services\SupplierMergeService;
use App\Support\Database;

header('Content-Type: application/json');
wbgl_api_require_permission('manage_data');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo JSON_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$sourceId = isset($input['source_id']) ? (int)$input['source_id'] : 0;
$targetId = isset($input['target_id']) ? (int)$input['target_id'] : 0;

if (!$sourceId || !$targetId) {
    echo json_encode(['success' => false, 'error' => 'Ids and Target IDs are required']);
    exit;
}

try {
    $db = Database::connect();
    $mergeService = new SupplierMergeService($db);
    $result = $mergeService->merge($sourceId, $targetId);
    
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
