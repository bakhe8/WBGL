<?php
/**
 * V3 API - Manual Entry
 * Create single guarantee manually
 */

require_once __DIR__ . '/_bootstrap.php';

use App\Services\ImportService;

wbgl_api_json_headers();
wbgl_api_require_permission('manual_entry');
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        wbgl_api_compat_fail(405, 'Method not allowed');
    }

    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }
    
    if (!$input) {
        wbgl_api_compat_fail(400, 'بيانات غير صالحة', [], 'validation');
    }

    // Create using ImportService
    $service = new ImportService();
    $createdBy = wbgl_api_current_user_display();
    $id = $service->createManually($input, $createdBy);

    wbgl_api_compat_success([
        'id' => $id,
        'message' => 'تم إضافة السجل بنجاح!',
    ]);
    
    // --- SMART AUTOMATION ---
    // Try to auto-match this new record immediately
    try {
        // Close session/buffer so user doesn't wait (optional but good for performance)
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        $processor = new \App\Services\SmartProcessingService('manual', $createdBy);
        $processor->processNewGuarantees(1);
    } catch (\Throwable $e) { /* background task */ }

} catch (\Throwable $e) {
    wbgl_api_compat_fail(500, $e->getMessage(), [
        'message' => $e->getMessage(),
    ], 'internal');
}
