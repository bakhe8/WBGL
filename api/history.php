<?php
/**
 * API: Get Guarantee History
 */
require_once __DIR__ . '/../app/Support/autoload.php';

use App\Repositories\GuaranteeHistoryRepository;

header('Content-Type: application/json');

$guaranteeId = $_GET['guarantee_id'] ?? null;

if (!$guaranteeId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing guarantee_id']);
    exit;
}

try {
    $repo = new GuaranteeHistoryRepository();
    $history = $repo->getHistory((int)$guaranteeId);
    
    // Transform for UI (hide full snapshot unless needed)
    $data = array_map(function($item) {
        return [
            'id' => $item['id'],
            'action' => $item['action'],
            'reason' => $item['change_reason'],
            'created_at' => $item['created_at'],
            'created_by' => $item['created_by'],
            // We don't return full snapshot to save bandwidth, unless requested?
            // For print button we just need ID.
        ];
    }, $history);

    echo json_encode(['success' => true, 'data' => $data]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
