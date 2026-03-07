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

wbgl_api_require_login();

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }
    $guaranteeId = Input::int($input, 'guarantee_id');
    $user = wbgl_api_current_user_display();
    $breakGlassRequested = BreakGlassService::isRequested($input);
    $hasReopenPermission = Guard::has('reopen_guarantee');

    if (!$guaranteeId) {
        wbgl_api_compat_fail(400, 'guarantee_id is required');
    }

    $breakGlass = null;
    // Governance decision:
    // - Users with reopen_guarantee can submit undo requests without broad manage_data.
    // - break_glass path is validated first (ticket/reason/permission), then action is executed.
    // - visibility scope remains enforced for non-privileged callers.
    if (!$hasReopenPermission && !$breakGlassRequested) {
        wbgl_api_require_guarantee_visibility((int)$guaranteeId);
        wbgl_api_compat_fail(403, 'Permission Denied');
    }

    // Reopen governance endpoints intentionally allow privileged callers
    // (reopen_guarantee or break_glass_override path) to operate outside
    // regular task visibility scope.

    if ($breakGlassRequested) {
        $breakGlass = BreakGlassService::authorizeAndRecord(
            $input,
            'reopen_guarantee_direct',
            'guarantee',
            (string)$guaranteeId,
            $user
        );
    }

    if ($breakGlass === null) {
        $reason = Input::string($input, 'reason', '');
        if (trim($reason) === '') {
            wbgl_api_compat_fail(400, 'reason is required');
        }
        $requestId = UndoRequestService::submit($guaranteeId, $reason, $user);
        Logger::info('undo_request_submitted_via_reopen', [
            'request_id' => $requestId,
            'guarantee_id' => $guaranteeId,
            'user' => $user
        ]);
        wbgl_api_compat_success([
            'mode' => 'undo_request',
            'request_id' => $requestId
        ]);
    }

    UndoRequestService::reopenDirect($guaranteeId, $user);

    Logger::info('record_reopened', [
        'guarantee_id' => $guaranteeId,
        'user' => $user,
        'break_glass' => $breakGlass,
        'bypassed_undo_workflow' => true,
    ]);

    wbgl_api_compat_success([
        'mode' => $breakGlass !== null ? 'break_glass_direct' : 'direct',
        'break_glass' => $breakGlass,
    ]);
} catch (Exception $e) {
    wbgl_api_compat_fail(400, $e->getMessage(), [], 'validation');
}
