<?php

/**
 * API - Reopen Guarantee for Correction
 * Sets status back to pending and records timeline event
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;
use App\Support\Input;
use App\Support\Logger;
use App\Services\TimelineRecorder;

header('Content-Type: application/json; charset=utf-8');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $guaranteeId = Input::int($input, 'guarantee_id');
    $user = Input::string($input, 'user', 'web_user');

    if (!$guaranteeId) {
        throw new Exception('guarantee_id is required');
    }

    $db = Database::connect();

    // 1. Get current snapshot before change
    $oldSnapshot = TimelineRecorder::createSnapshot($guaranteeId);

    // 2. Update status to pending
    $stmt = $db->prepare("UPDATE guarantee_decisions SET status = 'pending', last_modified_at = CURRENT_TIMESTAMP, last_modified_by = ? WHERE guarantee_id = ?");
    $stmt->execute([$user, $guaranteeId]);

    // 3. Record timeline event
    TimelineRecorder::recordReopenEvent($guaranteeId, $oldSnapshot);

    Logger::info('record_reopened', ['guarantee_id' => $guaranteeId, 'user' => $user]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
