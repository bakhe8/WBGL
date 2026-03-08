<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\AuthService;
use App\Support\Database;
use PDO;
use RuntimeException;

/**
 * DuplicateImportLifecycleService
 *
 * Centralizes duplicate-import behavior across Excel / Manual / Smart Paste:
 * 1) Always attach guarantee occurrence to the current batch context.
 * 2) Always record duplicate import timeline event.
 * 3) For non-locked decisions, reset current workflow cycle to a fresh draft
 *    so data-entry can select a new action for the new operational cycle.
 */
final class DuplicateImportLifecycleService
{
    /**
     * @return array{
     *   occurrence_attached:bool,
     *   duplicate_event_id:int|string,
     *   cycle_reset:bool,
     *   decision_locked:bool,
     *   decision_status:?string,
     *   action_after_reset:?string
     * }
     */
    public static function handle(
        int $guaranteeId,
        string $batchIdentifier,
        string $source,
        ?PDO $db = null
    ): array {
        $db = $db ?? Database::connect();

        ImportService::recordOccurrence($guaranteeId, $batchIdentifier, $source, null, $db);

        $duplicateEventId = TimelineRecorder::recordDuplicateImportEvent($guaranteeId, $source);
        if (!$duplicateEventId) {
            throw new RuntimeException('Failed to record duplicate import timeline event');
        }

        $decisionStmt = $db->prepare(
            'SELECT status, is_locked, supplier_id, bank_id, active_action, workflow_step, signatures_received
             FROM guarantee_decisions
             WHERE guarantee_id = ?
             LIMIT 1'
        );
        $decisionStmt->execute([$guaranteeId]);
        $decisionRow = $decisionStmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($decisionRow)) {
            return [
                'occurrence_attached' => true,
                'duplicate_event_id' => $duplicateEventId,
                'cycle_reset' => false,
                'decision_locked' => false,
                'decision_status' => null,
                'action_after_reset' => null,
            ];
        }

        $isLocked = (bool)($decisionRow['is_locked'] ?? false);
        $statusBefore = strtolower(trim((string)($decisionRow['status'] ?? 'pending')));
        $actionBefore = trim((string)($decisionRow['active_action'] ?? ''));
        $stepBefore = strtolower(trim((string)($decisionRow['workflow_step'] ?? 'draft')));
        $signaturesBefore = (int)($decisionRow['signatures_received'] ?? 0);

        if ($isLocked) {
            return [
                'occurrence_attached' => true,
                'duplicate_event_id' => $duplicateEventId,
                'cycle_reset' => false,
                'decision_locked' => true,
                'decision_status' => $statusBefore,
                'action_after_reset' => $actionBefore !== '' ? $actionBefore : null,
            ];
        }

        $supplierId = self::toNullablePositiveInt($decisionRow['supplier_id'] ?? null);
        $bankId = self::toNullablePositiveInt($decisionRow['bank_id'] ?? null);
        $statusAfter = StatusEvaluator::evaluate($supplierId, $bankId);

        $changes = [];
        if ($statusBefore !== $statusAfter) {
            $changes[] = [
                'field' => 'status',
                'old_value' => $statusBefore,
                'new_value' => $statusAfter,
                'trigger' => 'duplicate_import_cycle_reset',
            ];
        }
        if ($actionBefore !== '') {
            $changes[] = [
                'field' => 'active_action',
                'old_value' => $actionBefore,
                'new_value' => null,
                'trigger' => 'duplicate_import_cycle_reset',
            ];
        }
        if ($stepBefore !== 'draft') {
            $changes[] = [
                'field' => 'workflow_step',
                'old_value' => $stepBefore,
                'new_value' => 'draft',
                'trigger' => 'duplicate_import_cycle_reset',
            ];
        }
        if ($signaturesBefore !== 0) {
            $changes[] = [
                'field' => 'signatures_received',
                'old_value' => $signaturesBefore,
                'new_value' => 0,
                'trigger' => 'duplicate_import_cycle_reset',
            ];
        }

        if (empty($changes)) {
            return [
                'occurrence_attached' => true,
                'duplicate_event_id' => $duplicateEventId,
                'cycle_reset' => false,
                'decision_locked' => false,
                'decision_status' => $statusAfter,
                'action_after_reset' => null,
            ];
        }

        $beforeSnapshot = TimelineRecorder::createSnapshot($guaranteeId);
        $actor = self::resolveActorDisplay();

        $updateStmt = $db->prepare(
            'UPDATE guarantee_decisions
             SET status = ?,
                 active_action = NULL,
                 active_action_set_at = NULL,
                 workflow_step = ?,
                 signatures_received = 0,
                 last_modified_by = ?,
                 last_modified_at = CURRENT_TIMESTAMP
             WHERE guarantee_id = ?'
        );
        $updateStmt->execute([$statusAfter, 'draft', $actor, $guaranteeId]);

        $afterSnapshot = TimelineRecorder::createSnapshot($guaranteeId);
        $eventId = TimelineRecorder::recordStructuredEvent(
            $guaranteeId,
            'modified',
            'duplicate_cycle_reset',
            is_array($beforeSnapshot) ? $beforeSnapshot : [],
            $changes,
            $actor,
            [
                'source' => $source,
                'batch_identifier' => $batchIdentifier,
                'reason' => 'duplicate_import_cycle_reset',
            ],
            null,
            is_array($afterSnapshot) ? $afterSnapshot : null
        );
        if (!$eventId) {
            throw new RuntimeException('Failed to record duplicate cycle reset timeline event');
        }

        return [
            'occurrence_attached' => true,
            'duplicate_event_id' => $duplicateEventId,
            'cycle_reset' => true,
            'decision_locked' => false,
            'decision_status' => $statusAfter,
            'action_after_reset' => null,
        ];
    }

    private static function toNullablePositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $intValue = (int)$value;
        return $intValue > 0 ? $intValue : null;
    }

    private static function resolveActorDisplay(): string
    {
        $user = AuthService::getCurrentUser();
        if ($user === null) {
            return 'system';
        }

        $fullName = trim((string)($user->fullName ?? ''));
        if ($fullName !== '') {
            return $fullName;
        }

        $username = trim((string)($user->username ?? ''));
        if ($username !== '') {
            return '@' . $username;
        }

        $email = trim((string)($user->email ?? ''));
        if ($email !== '') {
            return $email;
        }

        $id = (int)($user->id ?? 0);
        return $id > 0 ? ('id:' . $id) : 'system';
    }
}

