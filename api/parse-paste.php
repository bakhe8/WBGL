<?php
/**
 * V3 API - Smart Paste Parse (Text Analysis)
 * 
 * ✨ REFACTORED - Phase 10
 * Extracts guarantee details from unstructured text
 * Now uses specialized services for better maintainability
 * 
 * @version 2.0
 */

require_once __DIR__ . '/../app/Support/Database.php';
require_once __DIR__ . '/../app/Models/Guarantee.php';
require_once __DIR__ . '/../app/Repositories/GuaranteeRepository.php';
require_once __DIR__ . '/../app/Services/TimelineRecorder.php';
require_once __DIR__ . '/_bootstrap.php';

use App\Support\Database;
use App\Support\Input;
use App\Support\Settings;
use App\Services\ParseCoordinatorService;

header('Content-Type: application/json; charset=utf-8');
wbgl_api_require_permission('import_excel');

// ============================================================================
// MAIN PROCESSING
// ============================================================================
try {
    // Get input
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }
    $text = Input::string($input, 'text', '');

    // Production Mode: Block test data creation
    $settings = Settings::getInstance();
    if (!empty($input['is_test_data']) && $settings->isProductionMode()) {
        wbgl_api_compat_fail(403, 'لا يمكن إنشاء بيانات اختبار في وضع الإنتاج', [], 'permission');
    }

    if (empty($text)) {
        throw new \RuntimeException("لم يتم إدخال أي نص للتحليل");
    }

    // Connect to database
    $db = Database::connect();
    
    // 🎯 NEW: Use ParseCoordinatorService to handle everything
    // This replaces 688 lines of inline logic with clean service calls
    // ✅ Pass test data options
    $options = [
        'is_test_data' => !empty($input['is_test_data']),
        'test_batch_id' => $input['test_batch_id'] ?? null,
        'test_note' => $input['test_note'] ?? null
    ];

    // Parse text
    $result = ParseCoordinatorService::parseText($text, $db, $options);

    if (!is_array($result)) {
        wbgl_api_compat_fail(500, 'Parse service returned invalid response', [], 'internal');
    }

    if (!($result['success'] ?? false)) {
        $message = (string)($result['error'] ?? 'فشل تحليل النص');
        wbgl_api_compat_fail(400, $message, $result, 'validation');
    }

    wbgl_api_compat_success($result);

} catch (\Throwable $e) {
    // Error handling
    error_log("Parse-paste error: " . $e->getMessage());
    
    wbgl_api_compat_fail(400, $e->getMessage(), [
        'extracted' => [],
        'field_status' => [],
        'confidence' => [],
    ], 'validation');
}
