<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use PDO;
use RuntimeException;

class PrintAuditService
{
    private const EVENT_TYPES = [
        'preview_opened',
        'print_requested',
    ];

    private const CONTEXTS = [
        'single_letter',
        'batch_letter',
    ];

    public static function record(
        string $eventType,
        string $context,
        array $guaranteeIds,
        string $initiatedBy,
        ?string $batchIdentifier = null,
        array $meta = [],
        string $channel = 'browser',
        ?string $sourcePage = null
    ): array {
        $eventType = trim($eventType);
        $context = trim($context);
        $channel = trim($channel) !== '' ? trim($channel) : 'browser';
        $sourcePage = $sourcePage !== null ? trim($sourcePage) : null;
        $initiatedBy = trim($initiatedBy) !== '' ? trim($initiatedBy) : 'النظام';
        $batchIdentifier = $batchIdentifier !== null && trim($batchIdentifier) !== ''
            ? trim($batchIdentifier)
            : null;

        if (!in_array($eventType, self::EVENT_TYPES, true)) {
            throw new RuntimeException('Unsupported print event_type');
        }
        if (!in_array($context, self::CONTEXTS, true)) {
            throw new RuntimeException('Unsupported print context');
        }

        $ids = self::normalizeGuaranteeIds($guaranteeIds);
        if (empty($ids) && $batchIdentifier === null) {
            throw new RuntimeException('guarantee_ids or batch_identifier is required');
        }

        $targets = !empty($ids) ? $ids : [null];
        $payload = !empty($meta) ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null;

        $db = Database::connect();
        $stmt = $db->prepare(
            'INSERT INTO print_events
             (guarantee_id, batch_identifier, event_type, context, channel, source_page, initiated_by, payload_json, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)'
        );

        $inserted = 0;
        foreach ($targets as $guaranteeId) {
            $stmt->execute([
                $guaranteeId,
                $batchIdentifier,
                $eventType,
                $context,
                $channel,
                $sourcePage,
                $initiatedBy,
                $payload,
            ]);
            $inserted++;
        }

        return [
            'inserted' => $inserted,
            'guarantee_ids' => $ids,
            'batch_identifier' => $batchIdentifier,
            'event_type' => $eventType,
            'context' => $context,
        ];
    }

    public static function listByGuarantee(int $guaranteeId, int $limit = 100): array
    {
        if ($guaranteeId <= 0) {
            throw new RuntimeException('guarantee_id is required');
        }
        $limit = max(1, min(500, $limit));

        $db = Database::connect();
        $stmt = $db->prepare(
            "SELECT id, guarantee_id, batch_identifier, event_type, context, channel, source_page, initiated_by, payload_json, created_at
             FROM print_events
             WHERE guarantee_id = ?
             ORDER BY id DESC
             LIMIT {$limit}"
        );
        $stmt->execute([$guaranteeId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static function (array $row): array {
            $row['guarantee_id'] = $row['guarantee_id'] !== null ? (int)$row['guarantee_id'] : null;
            $row['payload'] = [];
            if (!empty($row['payload_json'])) {
                $decoded = json_decode((string)$row['payload_json'], true);
                if (is_array($decoded)) {
                    $row['payload'] = $decoded;
                }
            }
            unset($row['payload_json']);
            return $row;
        }, $rows);
    }

    /**
     * @param array<mixed> $guaranteeIds
     * @return array<int>
     */
    private static function normalizeGuaranteeIds(array $guaranteeIds): array
    {
        $normalized = [];
        foreach ($guaranteeIds as $value) {
            if (is_int($value)) {
                if ($value > 0) {
                    $normalized[] = $value;
                }
                continue;
            }
            if (is_float($value)) {
                $id = (int)$value;
                if ($id > 0) {
                    $normalized[] = $id;
                }
                continue;
            }
            if (is_string($value) && trim($value) !== '' && is_numeric($value)) {
                $id = (int)$value;
                if ($id > 0) {
                    $normalized[] = $id;
                }
            }
        }

        return array_values(array_unique($normalized));
    }
}
