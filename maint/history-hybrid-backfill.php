<?php
declare(strict_types=1);

/**
 * Backfill guarantee_history to hybrid V2 (patch + anchor) format.
 *
 * Default mode is dry-run (no writes).
 *
 * Usage:
 *   php maint/history-hybrid-backfill.php
 *   php maint/history-hybrid-backfill.php --apply
 *   php maint/history-hybrid-backfill.php --apply --guarantee-id=123
 *   php maint/history-hybrid-backfill.php --json
 *   php maint/history-hybrid-backfill.php --no-strip-snapshot
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Services\TimelineHybridLedger;
use App\Services\TimelineRecorder;
use App\Support\Database;

/**
 * @return array<string,mixed>
 */
function wbglBackfillParseArgs(array $argv): array
{
    $options = [
        'apply' => false,
        'json' => false,
        'strip_snapshot' => true,
        'guarantee_id' => null,
        'limit' => null,
    ];

    foreach ($argv as $arg) {
        if ($arg === '--apply') {
            $options['apply'] = true;
            continue;
        }
        if ($arg === '--json') {
            $options['json'] = true;
            continue;
        }
        if ($arg === '--no-strip-snapshot') {
            $options['strip_snapshot'] = false;
            continue;
        }
        if (str_starts_with($arg, '--guarantee-id=')) {
            $value = (int)substr($arg, strlen('--guarantee-id='));
            $options['guarantee_id'] = $value > 0 ? $value : null;
            continue;
        }
        if (str_starts_with($arg, '--limit=')) {
            $value = (int)substr($arg, strlen('--limit='));
            $options['limit'] = $value > 0 ? $value : null;
            continue;
        }
    }

    return $options;
}

/**
 * @return array<string,mixed>
 */
function wbglBackfillDecodeMap(mixed $value): array
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
 * @return array<int,array<string,mixed>>
 */
function wbglBackfillDecodeChanges(array $details): array
{
    $changes = $details['changes'] ?? [];
    if (!is_array($changes)) {
        return [];
    }

    return array_values(array_filter($changes, static fn(mixed $row): bool => is_array($row)));
}

/**
 * @return array<int,array<string,mixed>>
 */
function wbglBackfillDecodePatch(mixed $value): array
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

function wbglBackfillHasJsonData(mixed $value): bool
{
    if (!is_string($value)) {
        return false;
    }
    $trimmed = trim($value);
    return $trimmed !== '' && $trimmed !== 'null' && $trimmed !== '{}' && $trimmed !== '[]';
}

/**
 * Normalize legacy snapshot to "before-change" semantics using old_value.
 *
 * @param array<string,mixed> $snapshot
 * @param array<int,array<string,mixed>> $changes
 * @return array<string,mixed>
 */
function wbglBackfillNormalizeSnapshotBefore(array $snapshot, array $changes): array
{
    if (empty($snapshot) || empty($changes)) {
        return $snapshot;
    }

    foreach ($changes as $change) {
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
 * @param array<string,mixed> $before
 * @param array<int,array<string,mixed>> $changes
 * @return array<string,mixed>
 */
function wbglBackfillApplyChanges(array $before, array $changes): array
{
    $after = $before;
    foreach ($changes as $change) {
        $field = (string)($change['field'] ?? '');
        if ($field === '') {
            continue;
        }

        if (!array_key_exists('new_value', $change)) {
            continue;
        }

        $newValue = $change['new_value'];
        if (is_array($newValue) && array_key_exists('id', $newValue)) {
            $after[$field] = $newValue['id'];
            if ($field === 'supplier_id' && array_key_exists('name', $newValue)) {
                $after['supplier_name'] = $newValue['name'];
            }
            if ($field === 'bank_id' && array_key_exists('name', $newValue)) {
                $after['bank_name'] = $newValue['name'];
            }
            continue;
        }

        $after[$field] = $newValue;
    }

    return $after;
}

/**
 * @return array{0: bool, 1: string}
 */
function wbglBackfillResolveAnchorDecision(
    int $positionBeforeEvent,
    string $eventType,
    ?string $eventSubtype,
    bool $forceAnchor,
    int $anchorInterval
): array {
    $anchorTypes = ['import', 'reimport', 'release', 'manual_override', 'status_change'];
    $anchorSubtypes = ['extension', 'reduction', 'release', 'reopened'];

    if ($forceAnchor) {
        return [true, 'legacy_anchor'];
    }

    if (in_array($eventType, $anchorTypes, true) || ($eventSubtype !== null && in_array($eventSubtype, $anchorSubtypes, true))) {
        return [true, 'milestone_event'];
    }

    if ($positionBeforeEvent > 0 && (($positionBeforeEvent + 1) % $anchorInterval) === 0) {
        return [true, 'periodic_anchor'];
    }

    return [false, 'patch_only'];
}

/**
 * @param array<string,mixed> $details
 * @return array<string,mixed>
 */
function wbglBackfillBuildLetterContext(
    string $eventType,
    ?string $eventSubtype,
    array $details,
    bool $hasLetterSnapshot,
    string $templateVersion
): array {
    $context = [
        'history_mode' => 'hybrid_v2',
        'event_type' => $eventType,
        'event_subtype' => $eventSubtype,
        'template_version' => $templateVersion,
        'has_letter_snapshot' => $hasLetterSnapshot,
    ];

    foreach (['source', 'reason', 'reason_text'] as $key) {
        if (array_key_exists($key, $details)) {
            $context[$key] = $details[$key];
        }
    }

    return $context;
}

function wbglBackfillCanonicalize(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }

    $isList = array_values($value) === $value;
    if ($isList) {
        foreach ($value as $idx => $entry) {
            $value[$idx] = wbglBackfillCanonicalize($entry);
        }
        return $value;
    }

    ksort($value);
    foreach ($value as $key => $entry) {
        $value[$key] = wbglBackfillCanonicalize($entry);
    }
    return $value;
}

function wbglBackfillEncodeCanonical(mixed $value): ?string
{
    if (!is_array($value) || empty($value)) {
        return null;
    }
    $normalized = wbglBackfillCanonicalize($value);
    return json_encode($normalized, JSON_UNESCAPED_UNICODE);
}

function wbglBackfillNormalizeJsonString(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $trimmed = trim($value);
    if ($trimmed === '' || $trimmed === 'null' || $trimmed === '{}' || $trimmed === '[]') {
        return null;
    }
    $decoded = json_decode($trimmed, true);
    if (!is_array($decoded) || empty($decoded)) {
        return null;
    }
    return wbglBackfillEncodeCanonical($decoded);
}

$opts = wbglBackfillParseArgs($argv ?? []);
$report = [
    'generated_at' => date(DATE_ATOM),
    'driver' => 'unknown',
    'mode' => $opts['apply'] ? 'apply' : 'dry-run',
    'scope' => [
        'guarantee_id' => $opts['guarantee_id'],
        'limit' => $opts['limit'],
        'strip_snapshot' => (bool)$opts['strip_snapshot'],
    ],
    'summary' => [
        'events_scanned' => 0,
        'guarantees_scanned' => 0,
        'events_rewritten' => 0,
        'anchors_marked' => 0,
        'patch_only_events' => 0,
        'snapshots_trimmed' => 0,
        'storage_bytes_before' => 0,
        'storage_bytes_after' => 0,
    ],
    'error' => '',
];

try {
    $db = Database::connect();
    $report['driver'] = Database::currentDriver();

    $sql = 'SELECT id, guarantee_id, event_type, event_subtype, snapshot_data, event_details, letter_snapshot, history_version, patch_data, anchor_snapshot, is_anchor, anchor_reason, letter_context, template_version
            FROM guarantee_history';
    $where = [];
    $params = [];
    if (is_int($opts['guarantee_id']) && $opts['guarantee_id'] > 0) {
        $where[] = 'guarantee_id = :guarantee_id';
        $params['guarantee_id'] = $opts['guarantee_id'];
    }
    if (!empty($where)) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY guarantee_id ASC, id ASC';
    if (is_int($opts['limit']) && $opts['limit'] > 0) {
        $sql .= ' LIMIT ' . (int)$opts['limit'];
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $anchorInterval = TimelineHybridLedger::anchorInterval();
    $defaultTemplateVersion = TimelineHybridLedger::templateVersion();

    $updateStmt = $db->prepare(
        'UPDATE guarantee_history
         SET history_version = ?, patch_data = ?, anchor_snapshot = ?, is_anchor = ?, anchor_reason = ?, letter_context = ?, template_version = ?, snapshot_data = ?
         WHERE id = ?'
    );

    if ($opts['apply']) {
        $db->beginTransaction();
    }

    $currentGuaranteeId = null;
    $positionBeforeEvent = 0;
    $previousAfterState = [];

    foreach ($rows as $row) {
        $report['summary']['events_scanned']++;
        $guaranteeId = (int)($row['guarantee_id'] ?? 0);
        if ($guaranteeId <= 0) {
            continue;
        }

        if ($currentGuaranteeId !== $guaranteeId) {
            $currentGuaranteeId = $guaranteeId;
            $positionBeforeEvent = 0;
            $previousAfterState = [];
            $report['summary']['guarantees_scanned']++;
        }

        $details = wbglBackfillDecodeMap($row['event_details'] ?? null);
        $changes = wbglBackfillDecodeChanges($details);

        $snapshotRaw = (string)($row['snapshot_data'] ?? '');
        $report['summary']['storage_bytes_before'] += strlen($snapshotRaw);
        $report['summary']['storage_bytes_before'] += strlen((string)($row['patch_data'] ?? ''));
        $report['summary']['storage_bytes_before'] += strlen((string)($row['anchor_snapshot'] ?? ''));

        $snapshotOriginal = wbglBackfillDecodeMap($snapshotRaw);
        $snapshotBefore = wbglBackfillNormalizeSnapshotBefore($snapshotOriginal, $changes);
        $existingAnchor = wbglBackfillDecodeMap($row['anchor_snapshot'] ?? null);
        $existingPatch = wbglBackfillDecodePatch($row['patch_data'] ?? null);

        $beforeState = $previousAfterState;
        if (!empty($snapshotBefore)) {
            $beforeState = array_merge($beforeState, $snapshotBefore);
        }

        $hasLegacySignal = !empty($snapshotBefore) || !empty($changes);
        if (!$hasLegacySignal && !empty($existingAnchor)) {
            // Strict mode compatibility:
            // when snapshot_data is intentionally null, preserve V2 row semantics
            // from existing anchor/patch payloads instead of regenerating from empty legacy data.
            $afterState = TimelineHybridLedger::applyPatch($existingAnchor, $existingPatch);
        } elseif (!$hasLegacySignal && !empty($existingPatch)) {
            $afterState = TimelineHybridLedger::applyPatch($previousAfterState, $existingPatch);
        } else {
            $afterState = wbglBackfillApplyChanges($beforeState, $changes);
            if (empty($afterState) && !empty($beforeState)) {
                $afterState = $beforeState;
            }
        }

        $patchData = TimelineRecorder::createPatch($previousAfterState, $afterState);
        $eventType = trim((string)($row['event_type'] ?? ''));
        $eventSubtypeRaw = trim((string)($row['event_subtype'] ?? ''));
        $eventSubtype = $eventSubtypeRaw !== '' ? $eventSubtypeRaw : null;
        $historyVersionRaw = strtolower(trim((string)($row['history_version'] ?? '')));
        $legacyAnchor = false;
        if ($historyVersionRaw !== 'v2') {
            $legacyAnchor = (int)($row['is_anchor'] ?? 0) === 1
                || wbglBackfillHasJsonData($row['anchor_snapshot'] ?? null)
                || !empty($details['ledger_auto_anchor']);
        } elseif (trim((string)($row['anchor_reason'] ?? '')) === 'legacy_anchor') {
            // Preserve previous explicit legacy override if already marked as such.
            $legacyAnchor = true;
        }

        [$isAnchor, $anchorReason] = wbglBackfillResolveAnchorDecision(
            $positionBeforeEvent,
            $eventType,
            $eventSubtype,
            $legacyAnchor,
            $anchorInterval
        );

        $anchorSnapshot = $isAnchor ? $afterState : [];
        if ($isAnchor) {
            // Anchor rows do not need patch_data; anchor already stores full post-event state.
            $patchData = [];
            $report['summary']['anchors_marked']++;
        } else {
            $report['summary']['patch_only_events']++;
        }

        $templateVersion = trim((string)($row['template_version'] ?? ''));
        if ($templateVersion === '') {
            $templateVersion = $defaultTemplateVersion;
        }

        $letterContext = wbglBackfillDecodeMap($row['letter_context'] ?? null);
        if (empty($letterContext)) {
            $letterContext = wbglBackfillBuildLetterContext(
                $eventType,
                $eventSubtype,
                $details,
                wbglBackfillHasJsonData($row['letter_snapshot'] ?? null),
                $templateVersion
            );
        }

        $forceLegacySnapshot = !empty($details['force_legacy_snapshot']);
        // Strict policy: legacy snapshot_data is not retained operationally.
        // Only explicit legacy/legal override may keep it.
        $keepSnapshot = $forceLegacySnapshot;
        $snapshotOut = null;
        if (!$opts['strip_snapshot'] || $keepSnapshot) {
            $snapshotToPersist = !empty($snapshotBefore) ? $snapshotBefore : $beforeState;
            if (empty($snapshotToPersist) && !empty($afterState) && $keepSnapshot) {
                $snapshotToPersist = $afterState;
            }
            $snapshotOut = wbglBackfillEncodeCanonical($snapshotToPersist);
        } elseif (wbglBackfillHasJsonData($snapshotRaw)) {
            $report['summary']['snapshots_trimmed']++;
        }

        $patchOut = wbglBackfillEncodeCanonical($patchData);
        $anchorOut = $isAnchor ? wbglBackfillEncodeCanonical($anchorSnapshot) : null;
        $letterContextOut = wbglBackfillEncodeCanonical($letterContext);
        $historyVersionOut = 'v2';
        $isAnchorOut = $isAnchor ? 1 : 0;

        $oldHistoryVersion = trim((string)($row['history_version'] ?? ''));
        $oldPatch = wbglBackfillNormalizeJsonString($row['patch_data'] ?? null);
        $oldAnchor = wbglBackfillNormalizeJsonString($row['anchor_snapshot'] ?? null);
        $oldLetterContext = wbglBackfillNormalizeJsonString($row['letter_context'] ?? null);
        $oldSnapshot = wbglBackfillNormalizeJsonString($row['snapshot_data'] ?? null);
        $oldTemplateVersion = trim((string)($row['template_version'] ?? ''));
        $oldAnchorReason = trim((string)($row['anchor_reason'] ?? ''));
        $oldIsAnchor = (int)($row['is_anchor'] ?? 0);

        $changed = $oldHistoryVersion !== $historyVersionOut
            || $oldPatch !== $patchOut
            || $oldAnchor !== $anchorOut
            || $oldLetterContext !== $letterContextOut
            || $oldSnapshot !== $snapshotOut
            || $oldTemplateVersion !== $templateVersion
            || $oldAnchorReason !== $anchorReason
            || $oldIsAnchor !== $isAnchorOut;

        if ($changed) {
            $report['summary']['events_rewritten']++;
            if ($opts['apply']) {
                $updateStmt->execute([
                    $historyVersionOut,
                    $patchOut,
                    $anchorOut,
                    $isAnchorOut,
                    $anchorReason,
                    $letterContextOut,
                    $templateVersion,
                    $snapshotOut,
                    (int)$row['id'],
                ]);
            }
        }

        $report['summary']['storage_bytes_after'] += strlen((string)($snapshotOut ?? ''));
        $report['summary']['storage_bytes_after'] += strlen((string)($patchOut ?? ''));
        $report['summary']['storage_bytes_after'] += strlen((string)($anchorOut ?? ''));

        $previousAfterState = $afterState;
        $positionBeforeEvent++;
    }

    if ($opts['apply']) {
        $db->commit();
    }

    $reportDir = base_path('storage/database/cutover');
    if (!is_dir($reportDir)) {
        @mkdir($reportDir, 0777, true);
    }
    $stamp = date('Ymd_His');
    $timestampedPath = $reportDir . DIRECTORY_SEPARATOR . 'history_hybrid_backfill_' . $stamp . '.json';
    $latestPath = $reportDir . DIRECTORY_SEPARATOR . 'history_hybrid_backfill_latest.json';
    @file_put_contents($timestampedPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    @file_put_contents($latestPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
} catch (Throwable $e) {
    if (!empty($opts['apply'])) {
        try {
            if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
                $db->rollBack();
            }
        } catch (Throwable $rollbackError) {
            // Ignore rollback failure.
        }
    }
    $report['error'] = $e->getMessage();
}

if (!empty($opts['json'])) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($report['error'] === '' ? 0 : 1);
}

echo "WBGL History Hybrid Backfill\n";
echo "Driver: {$report['driver']}\n";
echo "Mode: {$report['mode']}\n";
echo "Events scanned: {$report['summary']['events_scanned']}\n";
echo "Guarantees scanned: {$report['summary']['guarantees_scanned']}\n";
echo "Events rewritten: {$report['summary']['events_rewritten']}\n";
echo "Anchors marked: {$report['summary']['anchors_marked']}\n";
echo "Patch-only events: {$report['summary']['patch_only_events']}\n";
echo "Snapshots trimmed: {$report['summary']['snapshots_trimmed']}\n";
echo "Storage bytes before: {$report['summary']['storage_bytes_before']}\n";
echo "Storage bytes after: {$report['summary']['storage_bytes_after']}\n";
if ($report['error'] !== '') {
    echo "Error: {$report['error']}\n";
    exit(1);
}
echo "Done.\n";
exit(0);
