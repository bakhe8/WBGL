<?php
/**
 * API: Convert Test Guarantee to Real Guarantee
 */

require_once __DIR__ . '/_bootstrap.php';

use App\Repositories\GuaranteeRepository;

header('Content-Type: application/json; charset=utf-8');
wbgl_api_require_permission('manage_data');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $guaranteeId = $data['guarantee_id'] ?? null;
    
    if (!$guaranteeId) {
        echo json_encode(['success' => false, 'error' => 'Missing guarantee_id']);
        exit;
    }
    
    $repo = new GuaranteeRepository();
    $success = $repo->convertToReal((int)$guaranteeId);
    
    if ($success) {
        echo json_encode([
            'success' => true,
            'message' => 'Guarantee converted to real data successfully'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Failed to convert guarantee'
        ]);
    }
    
} catch (Exception $e) {
    error_log("Convert to real error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
