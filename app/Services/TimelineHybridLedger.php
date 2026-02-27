<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Settings;
use PDO;

/**
 * Hybrid history ledger helper (V2).
 *
 * Keeps V1 fields intact while optionally writing:
 * - patch_data
 * - anchor_snapshot
 * - history_version
 * - letter_context/template_version
 */
class TimelineHybridLedger
{
    private static ?array $historyColumns = null;

    /**
     * Event subtypes that must always carry an anchor snapshot.
     *
     * @var array<int, string>
     */
    private const ANCHOR_SUBTYPES = [
        'extension',
        'reduction',
        'release',
        'reopened',
    ];

    /**
     * Event types that are considered major milestones.
     *
     * @var array<int, string>
     */
    private const ANCHOR_TYPES = [
        'import',
        'reimport',
        'release',
        'manual_override',
    ];

    public static function isEnabled(): bool
    {
        // Transitional toggle retired: hybrid ledger is the permanent history policy.
        return true;
    }

    public static function anchorInterval(): int
    {
        $interval = (int) Settings::getInstance()->get('HISTORY_ANCHOR_INTERVAL', 10);
        return $interval > 0 ? $interval : 10;
    }

    public static function templateVersion(): string
    {
        $value = trim((string) Settings::getInstance()->get('HISTORY_TEMPLATE_VERSION', 'v1'));
        return $value !== '' ? $value : 'v1';
    }

    public static function supportsHybridColumns(PDO $db): bool
    {
        $columns = self::historyColumns($db);
        $required = [
            'history_version',
            'patch_data',
            'anchor_snapshot',
            'is_anchor',
            'anchor_reason',
            'letter_context',
            'template_version',
        ];
        foreach ($required as $column) {
            if (!in_array($column, $columns, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Build V2 payload fields for guarantee_history write.
     *
     * @param array<string, mixed>|null $snapshot
     * @param array<string, mixed> $extraDetails
     * @return array<string, mixed>
     */
    public static function buildHybridPayload(
        PDO $db,
        int $guaranteeId,
        string $eventType,
        ?string $eventSubtype,
        ?array $snapshot,
        ?array $currentSnapshot,
        array $extraDetails,
        ?string $letterSnapshot
    ): array {
        $historyCount = self::historyCount($db, $guaranteeId);
        $forceAnchor = (bool)($extraDetails['ledger_auto_anchor'] ?? false);
        [$shouldAnchor, $anchorReason] = self::resolveAnchorDecision(
            $historyCount,
            $eventType,
            $eventSubtype,
            $forceAnchor
        );

        $previousState = self::fetchLatestKnownState($db, $guaranteeId);
        $currentState = is_array($currentSnapshot) && !empty($currentSnapshot)
            ? $currentSnapshot
            : self::resolveCurrentState($guaranteeId, $snapshot);
        if ($currentState === null) {
            $currentState = $previousState ?? [];
        }

        $patch = [];
        if (!empty($currentState)) {
            $patch = TimelineRecorder::createPatch($previousState ?? [], $currentState);
        }

        $anchorSnapshot = null;
        if ($shouldAnchor && !empty($currentState)) {
            $anchorSnapshot = $currentState;
            // Anchor rows already carry full post-event state.
            // Keep them lean by omitting redundant patch payload.
            $patch = [];
        }

        $letterContext = self::buildLetterContext(
            $eventType,
            $eventSubtype,
            $extraDetails,
            $letterSnapshot !== null && trim($letterSnapshot) !== ''
        );

        return [
            'history_version' => 'v2',
            'patch_data' => $patch,
            'anchor_snapshot' => $anchorSnapshot,
            'is_anchor' => $anchorSnapshot !== null ? 1 : 0,
            'anchor_reason' => $anchorSnapshot !== null ? $anchorReason : null,
            'letter_context' => $letterContext,
            'template_version' => self::templateVersion(),
        ];
    }

    /**
     * Resolve a usable snapshot for a single event row.
     *
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    public static function resolveEventSnapshot(PDO $db, array $event): array
    {
        $guaranteeId = isset($event['guarantee_id']) ? (int) $event['guarantee_id'] : 0;
        $eventId = isset($event['id']) ? (int) $event['id'] : 0;
        if ($guaranteeId > 0 && $eventId > 0) {
            $beforeState = self::reconstructStateBeforeEvent($db, $guaranteeId, $eventId);
            if (!empty($beforeState)) {
                return $beforeState;
            }
        }

        $anchor = self::decodeJsonMap($event['anchor_snapshot'] ?? null);
        if (!empty($anchor)) {
            $patch = self::decodePatch($event['patch_data'] ?? null);
            return self::applyPatch($anchor, $patch);
        }

        // Archive fallback only for old rows that still store snapshot_data.
        $snapshot = self::decodeJsonMap($event['snapshot_data'] ?? null);
        if (!empty($snapshot)) {
            return self::normalizeSnapshotToBeforeContract($snapshot, $event);
        }

        return [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function resolveCurrentState(int $guaranteeId, ?array $snapshot): ?array
    {
        if (is_array($snapshot) && !empty($snapshot)) {
            return $snapshot;
        }
        $current = TimelineRecorder::createSnapshot($guaranteeId);
        return is_array($current) ? $current : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function fetchLatestKnownState(PDO $db, int $guaranteeId): ?array
    {
        $stmt = $db->prepare(
            'SELECT id
             FROM guarantee_history
             WHERE guarantee_id = ?
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute([$guaranteeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $latestId = isset($row['id']) ? (int) $row['id'] : 0;
        if ($latestId <= 0) {
            return null;
        }

        $state = self::reconstructStateUpToEvent($db, $guaranteeId, $latestId);
        return !empty($state) ? $state : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function reconstructStateUpToEvent(PDO $db, int $guaranteeId, int $eventId): array
    {
        $stmt = $db->prepare(
            'SELECT id, event_details, snapshot_data, anchor_snapshot, patch_data
             FROM guarantee_history
             WHERE guarantee_id = ? AND id <= ?
             ORDER BY id ASC'
        );
        $stmt->execute([$guaranteeId, $eventId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $state = [];
        foreach ($rows as $row) {
            $anchor = self::decodeJsonMap($row['anchor_snapshot'] ?? null);
            $hasAnchor = !empty($anchor);
            if ($hasAnchor) {
                $state = $anchor;
            }

            $patch = self::decodePatch($row['patch_data'] ?? null);
            $hasPatch = !empty($patch);
            if ($hasPatch) {
                $state = self::applyPatch($state, $patch);
                continue;
            }

            // Legacy fallback for rows that have neither anchor nor patch.
            if (!$hasAnchor && !$hasPatch) {
                $snapshot = self::decodeJsonMap($row['snapshot_data'] ?? null);
                if (!empty($snapshot)) {
                    $state = self::normalizeSnapshotToBeforeContract($snapshot, $row);
                }
            }
        }

        return $state;
    }

    /**
     * Reconstruct state just BEFORE a specific event ID.
     *
     * @return array<string,mixed>
     */
    public static function reconstructStateBeforeEvent(PDO $db, int $guaranteeId, int $eventId): array
    {
        $stmt = $db->prepare(
            'SELECT id, event_details, snapshot_data, anchor_snapshot, patch_data
             FROM guarantee_history
             WHERE guarantee_id = ? AND id < ?
             ORDER BY id ASC'
        );
        $stmt->execute([$guaranteeId, $eventId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $state = [];
        foreach ($rows as $row) {
            $anchor = self::decodeJsonMap($row['anchor_snapshot'] ?? null);
            $hasAnchor = !empty($anchor);
            if ($hasAnchor) {
                $state = $anchor;
            }

            $patch = self::decodePatch($row['patch_data'] ?? null);
            $hasPatch = !empty($patch);
            if ($hasPatch) {
                $state = self::applyPatch($state, $patch);
                continue;
            }

            // Legacy fallback for rows that have neither anchor nor patch.
            if (!$hasAnchor && !$hasPatch) {
                $snapshot = self::decodeJsonMap($row['snapshot_data'] ?? null);
                if (!empty($snapshot)) {
                    $state = self::normalizeSnapshotToBeforeContract($snapshot, $row);
                }
            }
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     * @param array<int, array<string, mixed>> $patch
     * @return array<string, mixed>
     */
    public static function applyPatch(array $state, array $patch): array
    {
        foreach ($patch as $op) {
            $operation = (string) ($op['op'] ?? '');
            $path = (string) ($op['path'] ?? '');
            if (!str_starts_with($path, '/')) {
                continue;
            }
            $key = substr($path, 1);
            if ($key === '') {
                continue;
            }

            if ($operation === 'remove') {
                unset($state[$key]);
                continue;
            }

            if (($operation === 'add' || $operation === 'replace') && array_key_exists('value', $op)) {
                $state[$key] = $op['value'];
            }
        }

        return $state;
    }

    /**
     * @param array<string, mixed>|null $snapshot
     * @return array{0: bool, 1: string}
     */
    private static function resolveAnchorDecision(
        int $historyCount,
        string $eventType,
        ?string $eventSubtype,
        bool $forceAnchor
    ): array {
        if ($forceAnchor) {
            return [true, 'forced_anchor'];
        }

        if (self::isMilestoneEvent($eventType, $eventSubtype)) {
            return [true, 'milestone_event'];
        }

        $interval = self::anchorInterval();
        if ($historyCount > 0 && (($historyCount + 1) % $interval === 0)) {
            return [true, 'periodic_anchor'];
        }

        return [false, 'patch_only'];
    }

    private static function isMilestoneEvent(string $eventType, ?string $eventSubtype): bool
    {
        if (in_array($eventType, self::ANCHOR_TYPES, true)) {
            return true;
        }
        return $eventSubtype !== null && in_array($eventSubtype, self::ANCHOR_SUBTYPES, true);
    }

    /**
     * @param array<string, mixed> $extraDetails
     * @return array<string, mixed>
     */
    private static function buildLetterContext(
        string $eventType,
        ?string $eventSubtype,
        array $extraDetails,
        bool $hasLetterSnapshot
    ): array {
        $context = [
            'history_mode' => 'hybrid_v2',
            'event_type' => $eventType,
            'event_subtype' => $eventSubtype,
            'template_version' => self::templateVersion(),
            'has_letter_snapshot' => $hasLetterSnapshot,
        ];

        if (array_key_exists('source', $extraDetails)) {
            $context['source'] = $extraDetails['source'];
        }
        if (array_key_exists('reason', $extraDetails)) {
            $context['reason'] = $extraDetails['reason'];
        }
        if (array_key_exists('reason_text', $extraDetails)) {
            $context['reason_text'] = $extraDetails['reason_text'];
        }

        return $context;
    }

    /**
     * Normalize event snapshot to the BEFORE-change contract using event_details.changes.
     *
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed> $event
     * @return array<string,mixed>
     */
    private static function normalizeSnapshotToBeforeContract(array $snapshot, array $event): array
    {
        $detailsRaw = (string)($event['event_details'] ?? '');
        if ($detailsRaw === '') {
            return $snapshot;
        }

        $details = json_decode($detailsRaw, true);
        $changes = is_array($details) ? ($details['changes'] ?? []) : [];
        if (!is_array($changes) || empty($changes)) {
            return $snapshot;
        }

        foreach ($changes as $change) {
            if (!is_array($change)) {
                continue;
            }
            $field = (string)($change['field'] ?? '');
            if ($field === '') {
                continue;
            }

            $oldValue = $change['old_value'] ?? null;
            if (is_array($oldValue) && array_key_exists('id', $oldValue)) {
                $snapshot[$field] = $oldValue['id'];
                if ($field === 'supplier_id' && array_key_exists('name', $oldValue)) {
                    $snapshot['supplier_name'] = $oldValue['name'];
                }
                if ($field === 'bank_id' && array_key_exists('name', $oldValue)) {
                    $snapshot['bank_name'] = $oldValue['name'];
                }
                continue;
            }

            $snapshot[$field] = $oldValue;
        }

        return $snapshot;
    }

    /**
     * @return array<int, string>
     */
    private static function historyColumns(PDO $db): array
    {
        if (self::$historyColumns !== null) {
            return self::$historyColumns;
        }

        $columns = [];
        $stmt = $db->prepare(
            "SELECT column_name
             FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = 'guarantee_history'
             ORDER BY ordinal_position"
        );
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!empty($row['column_name'])) {
                $columns[] = (string) $row['column_name'];
            }
        }

        self::$historyColumns = $columns;
        return $columns;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeJsonMap(mixed $value): array
    {
        if (!is_string($value)) {
            return [];
        }
        $trimmed = trim($value);
        if ($trimmed === '' || $trimmed === 'null' || $trimmed === '{}') {
            return [];
        }
        $decoded = json_decode($trimmed, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function decodePatch(mixed $value): array
    {
        if (!is_string($value)) {
            return [];
        }
        $trimmed = trim($value);
        if ($trimmed === '' || $trimmed === 'null' || $trimmed === '[]') {
            return [];
        }
        $decoded = json_decode($trimmed, true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_filter($decoded, static fn(mixed $row): bool => is_array($row)));
    }

    private static function historyCount(PDO $db, int $guaranteeId): int
    {
        $stmt = $db->prepare('SELECT COUNT(*) FROM guarantee_history WHERE guarantee_id = ?');
        $stmt->execute([$guaranteeId]);
        return (int) $stmt->fetchColumn();
    }
}
