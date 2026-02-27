<?php

/**
 * API - Reopen Guarantee for Correction
 * Sets status back to pending and records timeline event
 */

require_once __DIR__ . '/_bootstrap.php';

use App\Support\Input;
use App\Support\Guard;
use App\Support\Logger;
use App\Services\BreakGlassService;
use App\Services\UndoRequestService;

wbgl_api_json_headers();
wbgl_api_require_permission('manage_data');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }
    $guaranteeId = Input::int($input, 'guarantee_id');
    $user = wbgl_api_current_user_display();

    if (!$guaranteeId) {
        throw new Exception('guarantee_id is required');
    }

    $breakGlass = null;
    if (BreakGlassService::isRequested($input)) {
        $breakGlass = BreakGlassService::authorizeAndRecord(
            $input,
            'reopen_guarantee_direct',
            'guarantee',
            (string)$guaranteeId,
            $user
        );
    }

    if (!Guard::has('reopen_guarantee') && $breakGlass === null) {
        throw new Exception('ليس لديك صلاحية إعادة فتح الضمان مباشرة');
    }

    if ($breakGlass === null) {
        $reason = Input::string($input, 'reason', '');
        if (trim($reason) === '') {
            throw new Exception('reason is required');
        }
        $requestId = UndoRequestService::submit($guaranteeId, $reason, $user);
        Logger::info('undo_request_submitted_via_reopen', [
            'request_id' => $requestId,
            'guarantee_id' => $guaranteeId,
            'user' => $user
        ]);
        echo json_encode([
            'success' => true,
            'mode' => 'undo_request',
            'request_id' => $requestId
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    UndoRequestService::reopenDirect($guaranteeId, $user);

    Logger::info('record_reopened', [
        'guarantee_id' => $guaranteeId,
        'user' => $user,
        'break_glass' => $breakGlass,
        'bypassed_undo_workflow' => true,
    ]);

    echo json_encode([
        'success' => true,
        'mode' => $breakGlass !== null ? 'break_glass_direct' : 'direct',
        'break_glass' => $breakGlass,
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
