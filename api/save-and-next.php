<?php
/**
 * V3 API - Save and Next (Server-Driven Partial HTML)
 * Saves current record decision and returns HTML for next record
 * Single endpoint = single decision
 */

require_once __DIR__ . '/_bootstrap.php';

use App\Support\Database;
use App\Services\SaveAndNextApplicationService;
use App\Support\Input;
use App\Support\Settings;

header('Content-Type: application/json; charset=utf-8');
wbgl_api_require_login();

$policyContext = null;
$surface = null;

try {
    // Get POST data
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }
    
    $guaranteeId = Input::int($input, 'guarantee_id');
    $supplierId = Input::int($input, 'supplier_id');
    $supplierName = Input::string($input, 'supplier_name', '');
    
    if (!$guaranteeId) {
        wbgl_api_compat_fail(400, 'guarantee_id is required');
    }

    wbgl_api_require_guarantee_visibility((int)$guaranteeId);
    wbgl_api_require_permission('guarantee_save');
    
    $db = Database::connect();
    $context = wbgl_api_policy_surface_for_guarantee($db, (int)$guaranteeId);
    $policyContext = $context['policy'];
    $surface = $context['surface'];

    $decidedBy = Input::string($input, 'decided_by', wbgl_api_current_user_display());
    $statusFilter = Input::string($input, 'status_filter', 'all');
    $includeTestDataRaw = strtolower(trim(Input::string($input, 'include_test_data', '')));
    $includeTestData = in_array($includeTestDataRaw, ['1', 'true', 'yes', 'on'], true);
    if (Settings::getInstance()->isProductionMode()) {
        $includeTestData = false;
    }

    $result = SaveAndNextApplicationService::executeSaveAndNext(
        $db,
        (int)$guaranteeId,
        $supplierId ? (int)$supplierId : null,
        $supplierName,
        $decidedBy,
        $statusFilter,
        $includeTestData,
        $input,
        is_array($policyContext) ? $policyContext : [],
        is_array($surface) ? $surface : []
    );
    if (!(bool)($result['ok'] ?? false)) {
        wbgl_api_compat_fail(
            (int)($result['status_code'] ?? 400),
            (string)($result['error'] ?? 'save_and_next_failed'),
            is_array($result['payload'] ?? null) ? $result['payload'] : [],
            isset($result['error_type']) && is_string($result['error_type'])
                ? (string)$result['error_type']
                : null
        );
    }

    wbgl_api_compat_success(is_array($result['payload'] ?? null) ? $result['payload'] : []);
    
} catch (\Exception $e) {
    wbgl_api_compat_fail(500, $e->getMessage(), [
        'policy' => $policyContext,
        'surface' => $surface,
        'reasons' => is_array($policyContext) ? ($policyContext['reasons'] ?? []) : [],
    ]);
}
