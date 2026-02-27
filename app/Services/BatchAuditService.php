<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use PDO;

class BatchAuditService
{
    public static function record(
        string $importSource,
        string $eventType,
        string $initiatedBy,
        ?string $reason = null,
        array $payload = []
    ): int {
        $importSource = trim($importSource);
        $eventType = trim($eventType);
        $initiatedBy = trim($initiatedBy) !== '' ? trim($initiatedBy) : 'النظام';
        $reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;

        if ($importSource === '' || $eventType === '') {
            return 0;
        }

        $payloadJson = null;
        if (!empty($payload)) {
            $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $db = Database::connect();
        $stmt = $db->prepare(
            'INSERT INTO batch_audit_events
             (import_source, event_type, reason, initiated_by, payload_json, created_at)
             VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            $importSource,
            $eventType,
            $reason,
            $initiatedBy,
            $payloadJson,
        ]);

        return (int)$db->lastInsertId();
    }

    public static function listByBatch(string $importSource, int $limit = 100): array
    {
        $importSource = trim($importSource);
        if ($importSource === '') {
            return [];
        }

        $limit = max(1, min(500, $limit));
        $db = Database::connect();
        $stmt = $db->prepare(
            "SELECT id, import_source, event_type, reason, initiated_by, payload_json, created_at
             FROM batch_audit_events
             WHERE import_source = ?
             ORDER BY id DESC
             LIMIT {$limit}"
        );
        $stmt->execute([$importSource]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static function (array $row): array {
            $payload = [];
            if (!empty($row['payload_json'])) {
                $decoded = json_decode((string)$row['payload_json'], true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            }
            $row['payload'] = $payload;
            unset($row['payload_json']);
            return $row;
        }, $rows);
    }
}
