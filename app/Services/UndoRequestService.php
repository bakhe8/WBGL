<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use PDO;
use RuntimeException;
use Throwable;

class UndoRequestService
{
    public static function submit(int $guaranteeId, string $reason, string $requestedBy): int
    {
        if ($guaranteeId <= 0) {
            throw new RuntimeException('guarantee_id is required');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('reason is required');
        }

        $db = Database::connect();
        self::assertGuaranteeExists($db, $guaranteeId);
        self::assertNoPendingRequest($db, $guaranteeId);

        $stmt = $db->prepare(
            "INSERT INTO undo_requests (guarantee_id, reason, status, requested_by, created_at, updated_at)
             VALUES (?, ?, 'pending', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $stmt->execute([$guaranteeId, $reason, $requestedBy]);

        $requestId = (int)$db->lastInsertId();
        self::notify(
            'undo_request_submitted',
            'طلب إعادة فتح جديد',
            "تم إنشاء طلب إعادة فتح للضمان رقم {$guaranteeId}",
            [
                'request_id' => $requestId,
                'guarantee_id' => $guaranteeId,
                'status' => 'pending',
                'requested_by' => $requestedBy,
            ],
            "undo_request_submitted_{$requestId}"
        );

        return $requestId;
    }

    public static function approve(int $requestId, string $approver, string $note = ''): void
    {
        $db = Database::connect();
        $request = self::findOrFail($db, $requestId);
        self::assertPending($request);
        self::assertNotSelfAction((string)$request['requested_by'], $approver);

        $stmt = $db->prepare(
            "UPDATE undo_requests
             SET status = 'approved',
                 approved_by = ?,
                 decision_note = ?,
                 approved_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ?"
        );
        $stmt->execute([$approver, trim($note), $requestId]);

        self::notify(
            'undo_request_approved',
            'اعتماد طلب إعادة فتح',
            "تم اعتماد طلب إعادة الفتح رقم {$requestId}",
            [
                'request_id' => $requestId,
                'guarantee_id' => (int)$request['guarantee_id'],
                'status' => 'approved',
                'approved_by' => $approver,
            ],
            "undo_request_approved_{$requestId}"
        );
    }

    public static function reject(int $requestId, string $rejector, string $note = ''): void
    {
        $db = Database::connect();
        $request = self::findOrFail($db, $requestId);
        self::assertPending($request);
        self::assertNotSelfAction((string)$request['requested_by'], $rejector);

        $stmt = $db->prepare(
            "UPDATE undo_requests
             SET status = 'rejected',
                 rejected_by = ?,
                 decision_note = ?,
                 rejected_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ?"
        );
        $stmt->execute([$rejector, trim($note), $requestId]);

        self::notify(
            'undo_request_rejected',
            'رفض طلب إعادة فتح',
            "تم رفض طلب إعادة الفتح رقم {$requestId}",
            [
                'request_id' => $requestId,
                'guarantee_id' => (int)$request['guarantee_id'],
                'status' => 'rejected',
                'rejected_by' => $rejector,
            ],
            "undo_request_rejected_{$requestId}"
        );
    }

    public static function execute(int $requestId, string $executor): void
    {
        $db = Database::connect();
        $request = self::findOrFail($db, $requestId);
        if ((string)$request['status'] !== 'approved') {
            throw new RuntimeException('Undo request must be approved before execute');
        }
        self::assertNotSelfAction((string)$request['requested_by'], $executor);

        $db->beginTransaction();
        try {
            self::applyReopen($db, (int)$request['guarantee_id'], $executor);

            $stmt = $db->prepare(
                "UPDATE undo_requests
                 SET status = 'executed',
                     executed_by = ?,
                     executed_at = CURRENT_TIMESTAMP,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?"
            );
            $stmt->execute([$executor, $requestId]);

            $db->commit();

            self::notify(
                'undo_request_executed',
                'تنفيذ طلب إعادة فتح',
                "تم تنفيذ طلب إعادة الفتح رقم {$requestId}",
                [
                    'request_id' => $requestId,
                    'guarantee_id' => (int)$request['guarantee_id'],
                    'status' => 'executed',
                    'executed_by' => $executor,
                ],
                "undo_request_executed_{$requestId}"
            );
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    public static function reopenDirect(int $guaranteeId, string $actor): void
    {
        $db = Database::connect();
        self::applyReopen($db, $guaranteeId, $actor);
    }

    public static function list(?string $status = null, int $limit = 100, ?int $guaranteeId = null): array
    {
        $db = Database::connect();
        $limit = max(1, min(500, $limit));

        $sql = "
            SELECT ur.*,
                   g.guarantee_number
            FROM undo_requests ur
            JOIN guarantees g ON g.id = ur.guarantee_id
        ";
        $params = [];
        $conditions = [];
        if ($status !== null && trim($status) !== '') {
            $conditions[] = "ur.status = ?";
            $params[] = trim($status);
        }
        if ($guaranteeId !== null && $guaranteeId > 0) {
            $conditions[] = "ur.guarantee_id = ?";
            $params[] = $guaranteeId;
        }
        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }
        $sql .= " ORDER BY ur.id DESC LIMIT {$limit}";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function applyReopen(PDO $db, int $guaranteeId, string $actor): void
    {
        self::assertGuaranteeExists($db, $guaranteeId);

        $oldSnapshot = TimelineRecorder::createSnapshot($guaranteeId);
        $stmt = $db->prepare(
            "UPDATE guarantee_decisions
             SET status = 'pending',
                 is_locked = FALSE,
                 locked_reason = NULL,
                 last_modified_at = CURRENT_TIMESTAMP,
                 last_modified_by = ?
             WHERE guarantee_id = ?"
        );
        $stmt->execute([$actor, $guaranteeId]);

        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('No decision found for guarantee');
        }

        TimelineRecorder::recordReopenEvent($guaranteeId, $oldSnapshot);
    }

    private static function findOrFail(PDO $db, int $requestId): array
    {
        if ($requestId <= 0) {
            throw new RuntimeException('request_id is required');
        }
        $stmt = $db->prepare('SELECT * FROM undo_requests WHERE id = ? LIMIT 1');
        $stmt->execute([$requestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Undo request not found');
        }
        return $row;
    }

    private static function assertGuaranteeExists(PDO $db, int $guaranteeId): void
    {
        $stmt = $db->prepare('SELECT id FROM guarantees WHERE id = ? LIMIT 1');
        $stmt->execute([$guaranteeId]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('Guarantee not found');
        }
    }

    private static function assertPending(array $request): void
    {
        if ((string)$request['status'] !== 'pending') {
            throw new RuntimeException('Undo request is not pending');
        }
    }

    private static function assertNotSelfAction(string $requestedBy, string $actor): void
    {
        if ($requestedBy !== '' && $requestedBy === $actor) {
            throw new RuntimeException('Self-approval is not allowed');
        }
    }

    private static function assertNoPendingRequest(PDO $db, int $guaranteeId): void
    {
        $stmt = $db->prepare(
            "SELECT id
             FROM undo_requests
             WHERE guarantee_id = ? AND status = 'pending'
             ORDER BY id DESC
             LIMIT 1"
        );
        $stmt->execute([$guaranteeId]);
        if ($stmt->fetchColumn()) {
            throw new RuntimeException('There is already a pending undo request for this guarantee');
        }
    }

    private static function notify(
        string $type,
        string $title,
        string $message,
        array $data = [],
        ?string $dedupeKey = null
    ): void {
        try {
            NotificationService::create($type, $title, $message, null, $data, $dedupeKey);
        } catch (Throwable $e) {
            // Notification failure must not break undo workflow.
        }
    }
}
