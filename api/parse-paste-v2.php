<?php
/**
 * V3 API - Smart Paste Parse (v2 - With Confidence Scores)
 * 
 * Enhanced version that includes confidence scores for extracted data
 * Uses ConfidenceCalculator to assess reliability of each field
 * 
 * @version 2.0
 */

require_once __DIR__ . '/../app/Support/Database.php';
require_once __DIR__ . '/../app/Models/Guarantee.php';
require_once __DIR__ . '/../app/Repositories/GuaranteeRepository.php';
require_once __DIR__ . '/../app/Services/TimelineRecorder.php';
require_once __DIR__ . '/_bootstrap.php';

use App\Services\AuditTrailService;
use App\Services\SmartPaste\ConfidenceCalculator;
use App\Support\AuthService;
use App\Support\Database;
use App\Support\Input;
use App\Support\Settings;
use App\Services\ParseCoordinatorService;
use App\Services\SmartPaste\ParseResponseConfidenceGuard;

header('Content-Type: application/json; charset=utf-8');
wbgl_api_require_permission('import_excel');

$requestedEndpointVersion = defined('WBGL_PARSE_PASTE_REQUESTED_VERSION')
    ? strtolower(trim((string)WBGL_PARSE_PASTE_REQUESTED_VERSION))
    : 'v2';
if ($requestedEndpointVersion === '') {
    $requestedEndpointVersion = 'v2';
}
$effectiveEndpointVersion = 'v2';
$clientHint = trim((string)($_SERVER['HTTP_X_WBGL_PARSE_CLIENT'] ?? 'unknown-client'));

try {
    // Get input
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }
    $text = Input::string($input, 'text', '');
    $isTestData = !empty($input['is_test_data']);

    // Production Mode: Block test data creation
    $settings = Settings::getInstance();
    if ($isTestData && $settings->isProductionMode()) {
        wbgl_parse_paste_record_usage(
            $requestedEndpointVersion,
            $effectiveEndpointVersion,
            $clientHint,
            false,
            403,
            ['error' => 'production_mode_test_data_blocked']
        );
        wbgl_api_compat_fail(403, 'لا يمكن إنشاء بيانات اختبار في وضع الإنتاج', [], 'permission');
    }

    if (empty($text)) {
        throw new \RuntimeException("لم يتم إدخال أي نص للتحليل");
    }

    // Connect to database
    $db = Database::connect();
    
    // ✅ NEW: Extract test data parameters (Phase 1)
    $testBatchId = Input::string($input, 'test_batch_id', null);
    $testNote = Input::string($input, 'test_note', null);
    
    $options = [
        'is_test_data' => $isTestData,
        'test_batch_id' => $testBatchId,
        'test_note' => $testNote,
    ];

    // Parse text using ParseCoordinatorService
    $result = ParseCoordinatorService::parseText($text, $db, $options);
    if (is_array($result)) {
        $result = ParseResponseConfidenceGuard::strengthen($result, $text);
    }

    if (!is_array($result)) {
        wbgl_parse_paste_record_usage(
            $requestedEndpointVersion,
            $effectiveEndpointVersion,
            $clientHint,
            false,
            500,
            ['error' => 'parse_service_invalid_response']
        );
        wbgl_api_compat_fail(500, 'Parse service returned invalid response', [], 'internal');
    }

    // Defense-in-depth: when parse resolves to an existing guarantee,
    // enforce object-level visibility before returning its id/details.
    if (
        !empty($result['success'])
        && !empty($result['exists_before'])
        && !empty($result['id'])
        && is_numeric($result['id'])
    ) {
        wbgl_api_require_guarantee_visibility((int)$result['id']);
    }
    
    // ✅ NEW (Phase 2): Log confidence scores in timeline metadata
    if ($result['success'] && !empty($result['id']) && !empty($result['confidence'])) {
        try {
            // Store confidence metadata for future analysis
            $confidenceSummary = [
                'overall' => $result['overall_confidence'] ?? 0,
                'fields' => []
            ];
            
            foreach ($result['confidence'] as $field => $data) {
                $confidenceSummary['fields'][$field] = [
                    'score' => $data['confidence'] ?? 0,
                    'level' => \App\Services\SmartPaste\ConfidenceCalculator::getConfidenceLevel((int)($data['confidence'] ?? 0))
                ];
            }
            
            // Update guarantee metadata with confidence info
            $stmt = $db->prepare("
                INSERT INTO guarantee_metadata (guarantee_id, meta_key, meta_value, created_at)
                VALUES (?, 'smart_paste_confidence', ?, ?)
            ");
            $stmt->execute([
                $result['id'],
                json_encode($confidenceSummary),
                date('Y-m-d H:i:s')
            ]);
            
        } catch (\Exception $e) {
            // Log error but don't fail the request
            error_log("Failed to store confidence metadata: " . $e->getMessage());
        }
    }
    
    if (!($result['success'] ?? false)) {
        $message = (string)($result['error'] ?? 'فشل تحليل النص');
        wbgl_parse_paste_record_usage(
            $requestedEndpointVersion,
            $effectiveEndpointVersion,
            $clientHint,
            false,
            400,
            ['error' => $message]
        );
        wbgl_api_compat_fail(400, $message, $result, 'validation');
    }

    // Return result with confidence scores
    wbgl_parse_paste_record_usage(
        $requestedEndpointVersion,
        $effectiveEndpointVersion,
        $clientHint,
        true,
        200,
        [
            'exists_before' => !empty($result['exists_before']),
            'is_multi' => !empty($result['multi']),
        ]
    );
    wbgl_api_compat_success($result);

} catch (\Throwable $e) {
    // Error handling
    error_log("Parse-paste-v2 error: " . $e->getMessage());

    wbgl_parse_paste_record_usage(
        $requestedEndpointVersion,
        $effectiveEndpointVersion,
        $clientHint,
        false,
        400,
        [
            'error' => $e->getMessage(),
            'exception' => get_class($e),
        ]
    );
    
    wbgl_api_compat_fail(400, $e->getMessage(), [
        'extracted' => [],
        'field_status' => [],
        'confidence' => [],
    ], 'validation');
}

if (!function_exists('wbgl_parse_paste_record_usage')) {
    /**
     * @param array<string,mixed> $extra
     */
    function wbgl_parse_paste_record_usage(
        string $requestedVersion,
        string $effectiveVersion,
        string $clientHint,
        bool $success,
        int $statusCode,
        array $extra = []
    ): void {
        $settings = Settings::getInstance();
        if (!(bool)$settings->get('PARSE_PASTE_USAGE_AUDIT_ENABLED', true)) {
            return;
        }

        $user = AuthService::getCurrentUser();
        $details = array_merge([
            'requested_version' => $requestedVersion,
            'effective_version' => $effectiveVersion,
            'client_hint' => $clientHint,
            'success' => $success,
            'status_code' => $statusCode,
            'request_id' => wbgl_api_request_id(),
            'endpoint' => (string)($_SERVER['REQUEST_URI'] ?? ''),
            'method' => (string)($_SERVER['REQUEST_METHOD'] ?? 'POST'),
            'user_id' => $user?->id,
            'username' => (string)($user?->username ?? ''),
        ], $extra);

        AuditTrailService::record(
            'parse_paste_endpoint_usage',
            'observe',
            'parse_paste_endpoint',
            $requestedVersion,
            $details,
            $success ? 'info' : 'medium'
        );
    }
}
