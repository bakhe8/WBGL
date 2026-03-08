<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * TimelineDisplayService
 * 
 * Handles loading and formatting timeline events for display
 * Centralizes timeline presentation logic
 * 
 * @version 1.0
 */
class TimelineDisplayService
{
    // Note: Timeline icons are managed by TimelineRecorder::getEventIcon()
    // The iconMap was removed as dead code (never reached in practice)
    
    /**
     * Get formatted timeline events for display
     * 
     * @param PDO $db Database connection
     * @param int $guaranteeId Guarantee ID
     * @param string|null $importedAt Fallback import date
     * @param string|null $importSource Fallback import source
     * @param string|null $importedBy Fallback imported by
     * @return array Array of formatted timeline events
     */
    public static function getEventsForDisplay(
        PDO $db,
        int $guaranteeId,
        ?string $importedAt = null,
        ?string $importSource = null,
        ?string $importedBy = null
    ): array {
        $timeline = [];

        try {
            // Load from guarantee_history table (unified timeline)
            $stmt = $db->prepare('
                SELECT * FROM guarantee_history
                WHERE guarantee_id = ?
                ORDER BY id DESC
            ');
            $stmt->execute([$guaranteeId]);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($history as $event) {
                $eventId = isset($event['id']) ? (int)$event['id'] : 0;
                $resolvedSnapshot = TimelineHybridLedger::resolveEventSnapshot($db, $event);
                $snapshotRaw = json_encode($resolvedSnapshot, JSON_UNESCAPED_UNICODE);
                if (!is_string($snapshotRaw) || trim($snapshotRaw) === '') {
                    $snapshotRaw = '{}';
                }
                $eventType = (string)($event['event_type'] ?? 'unknown');
                $eventSubtype = (string)($event['event_subtype'] ?? '');
                $eventDetails = self::decodeEventDetails($event['event_details'] ?? null);
                $actor = TimelinePresentationNormalizer::actorFromEvent($event);
                $normalizedChanges = [];
                $rawChanges = $eventDetails['changes'] ?? [];
                if (is_array($rawChanges)) {
                    foreach ($rawChanges as $change) {
                        if (is_array($change) && isset($change['field'])) {
                            $normalizedChanges[] = TimelinePresentationNormalizer::normalizeChange($change);
                        }
                    }
                }
                $normalizedChanges = self::filterVisibleChanges($eventType, $eventSubtype, $normalizedChanges);
                $statusChangeRaw = $eventDetails['status_change'] ?? null;
                $statusChangePresent = $statusChangeRaw !== null
                    ? TimelinePresentationNormalizer::presentValue('status', $statusChangeRaw)
                    : null;
                $tone = self::detectTone($eventType, $eventSubtype);
                $sourceInfo = self::sourceInfo($eventType, $eventSubtype, $eventDetails);

                $timeline[] = [
                    'id' => $eventId,
                    'event_id' => $eventId,
                    'event_type' => $eventType,
                    'event_subtype' => $eventSubtype !== '' ? $eventSubtype : null,
                    'type' => $eventType,
                    'icon' => '📋',  // Fallback; actual icons from TimelineRecorder::getEventIcon()
                    'action' => $eventType,
                    'date' => $event['created_at'] ?? '',
                    'created_at' => $event['created_at'] ?? '',
                    'event_details' => $event['event_details'] ?? null,
                    'details' => $eventDetails,
                    'change_reason' => '',
                    'description' => json_encode(json_decode($event['event_details'] ?? '{}', true)),
                    'user' => $actor['display'],
                    'created_by' => $actor['display'],
                    'created_by_raw' => $event['created_by'] ?? 'system',
                    'created_by_i18n_key' => $actor['i18n_key'],
                    'actor' => $actor,
                    'snapshot' => $resolvedSnapshot,
                    'snapshot_data' => $snapshotRaw ?? '{}',
                    'anchor_snapshot' => $event['anchor_snapshot'] ?? null,
                    'patch_data' => $event['patch_data'] ?? null,
                    'letter_snapshot' => $event['letter_snapshot'] ?? null,
                    'changes' => $normalizedChanges,
                    'status_change' => $statusChangeRaw,
                    'status_change_present' => $statusChangePresent,
                    'tone' => $tone,
                    'source_info' => $sourceInfo,
                    'source_badge' => $actor['kind'] === 'system' ? '🤖' : '👤',
                ];
            }
        } catch (\Exception $e) {
            // If error, keep empty array
        }

        // Add import event if no events found
        if (empty($timeline) && $importedAt) {
            $fallbackActor = TimelinePresentationNormalizer::actorFromEvent([
                'actor_kind' => 'system',
                'created_by' => $importedBy ?? 'system',
            ]);
            $timeline[] = [
                'id' => 'import_1',
                'type' => 'import',
                'event_type' => 'import',
                'icon' => '📥',
                'action' => 'import',
                'date' => $importedAt,
                'created_at' => $importedAt,
                'change_reason' => 'استيراد من ' . ($importSource ?? 'Excel'),
                'description' => 'استيراد من ' . ($importSource ?? 'Excel'),
                'user' => $fallbackActor['display'],
                'created_by' => $fallbackActor['display'],
                'created_by_i18n_key' => $fallbackActor['i18n_key'],
                'actor' => $fallbackActor,
                'source_badge' => '🤖',
                'changes' => [],
                'tone' => 'slate',
                'source_info' => [
                    'label_key' => 'timeline.source.file_import',
                    'tone' => 'info',
                ],
            ];
        }

        return $timeline;
    }

    /**
     * @return array<string,mixed>
     */
    private static function decodeEventDetails(mixed $rawDetails): array
    {
        if (!is_string($rawDetails) || trim($rawDetails) === '') {
            return [];
        }
        $decoded = json_decode($rawDetails, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<int,array<string,mixed>> $changes
     * @return array<int,array<string,mixed>>
     */
    private static function filterVisibleChanges(string $eventType, string $eventSubtype, array $changes): array
    {
        $allowedFields = ['supplier_id', 'bank_id', 'amount', 'expiry_date', 'status', 'workflow_step'];
        if ($eventSubtype === 'extension') {
            $allowedFields = ['expiry_date'];
        } elseif ($eventSubtype === 'reduction') {
            $allowedFields = ['amount'];
        } elseif (in_array($eventSubtype, ['supplier_change', 'manual_edit'], true)) {
            $allowedFields = ['supplier_id', 'bank_id'];
        } elseif (
            $eventType === 'auto_matched' ||
            in_array($eventSubtype, ['ai_match', 'auto_match', 'bank_match', 'bank_change'], true)
        ) {
            $allowedFields = ['bank_name', 'supplier_name', 'supplier_id', 'bank_id'];
        } elseif (
            $eventSubtype === 'release' ||
            in_array($eventType, ['release', 'released'], true)
        ) {
            $allowedFields = ['status', 'active_action', 'workflow_step', 'signatures_received'];
        } elseif ($eventSubtype === 'duplicate_cycle_reset') {
            $allowedFields = ['status', 'active_action', 'workflow_step', 'signatures_received'];
        } elseif ($eventSubtype === 'workflow_advance' || $eventType === 'status_change') {
            $allowedFields = ['workflow_step', 'signatures_received', 'status'];
        } elseif ($eventType === 'modified' && $eventSubtype === '') {
            $allowedFields = [];
        }

        return array_values(array_filter($changes, static function ($change) use ($allowedFields): bool {
            if (!is_array($change)) {
                return false;
            }
            return in_array((string)($change['field'] ?? ''), $allowedFields, true);
        }));
    }

    private static function detectTone(string $eventType, string $eventSubtype): string
    {
        if ($eventType === 'import') {
            return 'slate';
        }
        if ($eventType === 'reimport' || str_starts_with($eventSubtype, 'duplicate_')) {
            return 'warning';
        }
        if (
            $eventType === 'auto_matched' ||
            in_array($eventSubtype, ['ai_match', 'auto_match', 'bank_match', 'bank_change'], true)
        ) {
            return 'info';
        }
        if (in_array($eventSubtype, ['manual_edit', 'supplier_change'], true)) {
            return 'success';
        }
        if ($eventSubtype === 'extension') {
            return 'amber';
        }
        if ($eventSubtype === 'reduction') {
            return 'violet';
        }
        if (
            $eventSubtype === 'release' ||
            in_array($eventType, ['release', 'released'], true)
        ) {
            return 'danger';
        }
        return 'muted';
    }

    /**
     * @param array<string,mixed> $eventDetails
     * @return array{label_key:string,tone:string}|null
     */
    private static function sourceInfo(string $eventType, string $eventSubtype, array $eventDetails): ?array
    {
        if (!in_array($eventType, ['import', 'reimport'], true)) {
            return null;
        }

        $sourceMap = [
            'excel' => ['label_key' => 'timeline.source.excel', 'tone' => 'info'],
            'smart_paste' => ['label_key' => 'timeline.source.smart_paste', 'tone' => 'violet'],
            'smart_paste_multi' => ['label_key' => 'timeline.source.smart_paste_multi', 'tone' => 'violet'],
            'duplicate_smart_paste' => ['label_key' => 'timeline.source.duplicate_smart_paste', 'tone' => 'warning'],
            'duplicate_excel' => ['label_key' => 'timeline.source.duplicate_excel', 'tone' => 'warning'],
            'duplicate_manual' => ['label_key' => 'timeline.source.duplicate_manual', 'tone' => 'warning'],
            'manual' => ['label_key' => 'timeline.source.manual', 'tone' => 'success'],
        ];

        $source = $eventSubtype !== '' ? $eventSubtype : (string)($eventDetails['source'] ?? 'excel');
        return $sourceMap[$source] ?? ['label_key' => 'timeline.source.file_import', 'tone' => 'muted'];
    }
}
