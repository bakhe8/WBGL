<?php
/**
 * API: Convert Test Guarantee to Real Guarantee
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use App\Repositories\GuaranteeRepository;
use App\Support\Database;

wbgl_api_require_permission('manage_data');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    wbgl_api_compat_fail(405, 'Method not allowed');
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $guaranteeId = $data['guarantee_id'] ?? null;
    
    if (!$guaranteeId) {
        wbgl_api_compat_fail(400, 'Missing guarantee_id');
    }

    wbgl_api_require_guarantee_visibility((int)$guaranteeId);
    
    $db = Database::connect();
    $repo = new GuaranteeRepository($db);
    $success = $repo->convertToReal((int)$guaranteeId);
    
    if ($success) {
        wbgl_api_compat_success([
            'message' => 'Guarantee converted to real data successfully'
        ]);
    } else {
        wbgl_api_compat_fail(500, 'Failed to convert guarantee', [], 'internal');
    }
    
} catch (Exception $e) {
    error_log("Convert to real error: " . $e->getMessage());
    wbgl_api_compat_fail(500, $e->getMessage(), [], 'internal');
}
