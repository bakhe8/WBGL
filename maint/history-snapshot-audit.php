<?php
declare(strict_types=1);

/**
 * Audit guarantee_history snapshot contract semantics.
 *
 * Verifies whether snapshot_data represents BEFORE or AFTER state
 * relative to event_details.changes old_value/new_value.
 *
 * Usage:
 *   php maint/history-snapshot-audit.php
 *   php maint/history-snapshot-audit.php --json
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;
use App\Services\TimelineHybridLedger;

/**
 * @param array<string,mixed> $change
 */
function wbglAuditExtractComparableValue(array $change, string $key): mixed
{
    $value = $change[$key] ?? null;
    if (is_array($value) && array_key_exists('id', $value)) {
        return $value['id'];
    }
    return $value;
}

/**
 * @return array<string,mixed>
 */
function wbglAuditEmptyBucket(): array
{
    return [
        'events' => 0,
        'with_change' => 0,
        'before_match' => 0,
        'after_match' => 0,
        'neither' => 0,
        'fields' => [],
    ];
}

$asJson = in_array('--json', $argv ?? [], true);
$result = [
    'generated_at' => date(DATE_ATOM),
    'driver' => 'unknown',
    'summary' => [
        'events_total' => 0,
        'events_with_snapshot' => 0,
        'events_with_resolved_snapshot' => 0,
        'events_with_change' => 0,
        'before_match_ratio' => 0.0,
        'after_match_ratio' => 0.0,
    ],
    'by_event' => [],
    'error' => '',
];

try {
    $db = Database::connect();
    $result['driver'] = Database::currentDriver();

    $rows = $db->query(
        'SELECT id, guarantee_id, event_type, event_subtype, snapshot_data, event_details, patch_data, anchor_snapshot
         FROM guarantee_history
         ORDER BY id ASC'
    );
    $events = $rows ? $rows->fetchAll(PDO::FETCH_ASSOC) : [];

    $targets = ['expiry_date', 'amount', 'status', 'supplier_id', 'bank_id', 'bank_name'];
    $stats = [];

    foreach ($events as $row) {
        $result['summary']['events_total']++;
        $snapshotRaw = (string)($row['snapshot_data'] ?? '');
        $snapshotTrim = trim($snapshotRaw);
        if ($snapshotTrim !== '' && $snapshotTrim !== '{}' && $snapshotTrim !== 'null') {
            $result['summary']['events_with_snapshot']++;
        }

        $eventType = (string)($row['event_type'] ?? 'unknown');
        $eventSubtype = (string)($row['event_subtype'] ?? '');
        $bucketKey = $eventType . '::' . $eventSubtype;
        if (!isset($stats[$bucketKey])) {
            $stats[$bucketKey] = wbglAuditEmptyBucket();
        }
        $stats[$bucketKey]['events']++;

        $snapshot = TimelineHybridLedger::resolveEventSnapshot($db, $row);
        if (is_array($snapshot) && !empty($snapshot)) {
            $result['summary']['events_with_resolved_snapshot']++;
        }
        $details = json_decode((string)($row['event_details'] ?? ''), true);
        $changes = is_array($details) ? ($details['changes'] ?? []) : [];
        if (!is_array($changes) || !is_array($snapshot) || empty($changes)) {
            continue;
        }

        foreach ($changes as $change) {
            if (!is_array($change)) {
                continue;
            }

            $field = (string)($change['field'] ?? '');
            if (!in_array($field, $targets, true)) {
                continue;
            }

            $result['summary']['events_with_change']++;
            $stats[$bucketKey]['with_change']++;
            if (!isset($stats[$bucketKey]['fields'][$field])) {
                $stats[$bucketKey]['fields'][$field] = [
                    'n' => 0,
                    'before' => 0,
                    'after' => 0,
                    'neither' => 0,
                ];
            }
            $stats[$bucketKey]['fields'][$field]['n']++;

            $snapValue = $snapshot[$field] ?? null;
            $oldValue = wbglAuditExtractComparableValue($change, 'old_value');
            $newValue = wbglAuditExtractComparableValue($change, 'new_value');

            $isBefore = ((string)$snapValue === (string)$oldValue);
            $isAfter = ((string)$snapValue === (string)$newValue);

            if ($isBefore) {
                $stats[$bucketKey]['before_match']++;
                $stats[$bucketKey]['fields'][$field]['before']++;
            }
            if ($isAfter) {
                $stats[$bucketKey]['after_match']++;
                $stats[$bucketKey]['fields'][$field]['after']++;
            }
            if (!$isBefore && !$isAfter) {
                $stats[$bucketKey]['neither']++;
                $stats[$bucketKey]['fields'][$field]['neither']++;
            }
        }
    }

    $filtered = [];
    $totalCompared = 0;
    $beforeCount = 0;
    $afterCount = 0;
    foreach ($stats as $key => $bucket) {
        if ((int)$bucket['with_change'] <= 0) {
            continue;
        }
        $filtered[$key] = $bucket;
        $totalCompared += (int)$bucket['with_change'];
        $beforeCount += (int)$bucket['before_match'];
        $afterCount += (int)$bucket['after_match'];
    }
    $result['by_event'] = $filtered;

    if ($totalCompared > 0) {
        $result['summary']['before_match_ratio'] = round($beforeCount / $totalCompared, 4);
        $result['summary']['after_match_ratio'] = round($afterCount / $totalCompared, 4);
    }

    $reportDir = base_path('storage/database/cutover');
    if (!is_dir($reportDir)) {
        @mkdir($reportDir, 0777, true);
    }
    $stamp = date('Ymd_His');
    $timestampedPath = $reportDir . DIRECTORY_SEPARATOR . 'history_snapshot_audit_' . $stamp . '.json';
    $latestPath = $reportDir . DIRECTORY_SEPARATOR . 'history_snapshot_audit_latest.json';

    @file_put_contents($timestampedPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    @file_put_contents($latestPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
} catch (Throwable $e) {
    $result['error'] = $e->getMessage();
}

if ($asJson) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($result['error'] === '' ? 0 : 1);
}

echo "WBGL History Snapshot Audit\n";
echo "Driver: {$result['driver']}\n";
echo "Events total: {$result['summary']['events_total']}\n";
echo "Events with snapshot: {$result['summary']['events_with_snapshot']}\n";
echo "Events with resolved snapshot: {$result['summary']['events_with_resolved_snapshot']}\n";
echo "Comparable changes: {$result['summary']['events_with_change']}\n";
echo "Before ratio: {$result['summary']['before_match_ratio']}\n";
echo "After ratio: {$result['summary']['after_match_ratio']}\n";
if ($result['error'] !== '') {
    echo "Error: {$result['error']}\n";
    exit(1);
}

echo "Done.\n";
exit(0);
