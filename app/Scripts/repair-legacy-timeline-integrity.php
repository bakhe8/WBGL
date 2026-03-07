<?php
declare(strict_types=1);

/**
 * Repair legacy timeline/decision integrity gaps.
 *
 * Scope:
 * - Backfill missing import timeline event for guarantees without history.
 * - Repair ready decisions missing supplier/bank by downgrading to pending with status-change event.
 * - Normalize generic timeline actor labels in created_by.
 *
 * Usage:
 *   php app/Scripts/repair-legacy-timeline-integrity.php
 *   php app/Scripts/repair-legacy-timeline-integrity.php --apply
 *   php app/Scripts/repair-legacy-timeline-integrity.php --apply --scope=all
 *   php app/Scripts/repair-legacy-timeline-integrity.php --apply --scope=real --limit=100
 */

require_once __DIR__ . '/../Support/autoload.php';

use App\Services\TimelineRecorder;
use App\Support\Database;
use App\Support\SchemaInspector;

/**
 * @param array<string,string|false>|false $options
 */
function option_value($options, string $key, string $default = ''): string
{
    if (!is_array($options) || !array_key_exists($key, $options)) {
        return $default;
    }

    $raw = $options[$key];
    if ($raw === false) {
        return $default;
    }

    return trim((string)$raw);
}

/**
 * @return array{sql:string,params:array<int,mixed>}
 */
function scope_filter_sql(string $scope, string $alias = 'g'): array
{
    if ($scope === 'test') {
        return ['sql' => " AND COALESCE({$alias}.is_test_data, 0) = 1", 'params' => []];
    }

    if ($scope === 'real') {
        return ['sql' => " AND COALESCE({$alias}.is_test_data, 0) = 0", 'params' => []];
    }

    return ['sql' => '', 'params' => []];
}

function limit_sql(?int $limit): string
{
    return $limit !== null && $limit > 0 ? " LIMIT {$limit}" : '';
}

function normalize_source_subtype(string $importSource): string
{
    $value = mb_strtolower(trim($importSource), 'UTF-8');
    if ($value === '') {
        return 'legacy';
    }

    if (str_contains($value, 'excel')) {
        return 'excel';
    }
    if (str_contains($value, 'email')) {
        return 'email';
    }
    if (str_contains($value, 'paste')) {
        return 'smart_paste';
    }
    if (str_contains($value, 'manual')) {
        return 'manual';
    }
    if (str_contains($value, 'integration')) {
        return 'integration';
    }

    return 'legacy';
}

/**
 * @param mixed $rawPayload
 * @return array<string,mixed>
 */
function decode_raw_data(mixed $rawPayload): array
{
    if (is_array($rawPayload)) {
        return $rawPayload;
    }

    if (!is_string($rawPayload) || trim($rawPayload) === '') {
        return [];
    }

    $decoded = json_decode($rawPayload, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * @param array<string,mixed> $row
 * @param array<string,mixed> $raw
 * @return array<string,mixed>
 */
function build_import_snapshot(array $row, array $raw): array
{
    $status = trim((string)($row['decision_status'] ?? ''));
    if ($status === '') {
        $status = 'pending';
    }

    $supplierName = trim((string)($row['supplier_name'] ?? ''));
    if ($supplierName === '') {
        $supplierName = (string)($raw['supplier'] ?? '');
    }

    $bankName = trim((string)($row['bank_name'] ?? ''));
    if ($bankName === '') {
        $bankName = (string)($raw['bank'] ?? '');
    }

    return [
        'supplier_name' => $supplierName,
        'bank_name' => $bankName,
        'supplier_id' => isset($row['supplier_id']) ? (int)$row['supplier_id'] : null,
        'bank_id' => isset($row['bank_id']) ? (int)$row['bank_id'] : null,
        'amount' => $raw['amount'] ?? 0,
        'expiry_date' => $raw['expiry_date'] ?? '',
        'issue_date' => $raw['issue_date'] ?? '',
        'contract_number' => $raw['contract_number'] ?? ($raw['document_reference'] ?? ''),
        'guarantee_number' => $raw['guarantee_number'] ?? ($raw['bg_number'] ?? ''),
        'type' => $raw['type'] ?? '',
        'status' => $status,
        'raw_supplier_name' => $raw['supplier'] ?? '',
        'raw_bank_name' => $raw['bank'] ?? '',
    ];
}

/**
 * @return array<int,array<string,mixed>>
 */
function fetch_missing_history_rows(PDO $db, string $scope, ?int $limit): array
{
    $scopeFilter = scope_filter_sql($scope, 'g');
    $sql = "
        SELECT
            g.id,
            CAST(g.raw_data AS TEXT) AS raw_data,
            COALESCE(g.import_source, '') AS import_source,
            g.imported_at,
            d.status AS decision_status,
            d.supplier_id,
            d.bank_id,
            s.official_name AS supplier_name,
            b.arabic_name AS bank_name
        FROM guarantees g
        LEFT JOIN guarantee_decisions d ON d.guarantee_id = g.id
        LEFT JOIN suppliers s ON s.id = d.supplier_id
        LEFT JOIN banks b ON b.id = d.bank_id
        WHERE NOT EXISTS (
            SELECT 1
            FROM guarantee_history h
            WHERE h.guarantee_id = g.id
        )
        {$scopeFilter['sql']}
        ORDER BY g.id ASC
    " . limit_sql($limit);

    $stmt = $db->prepare($sql);
    $stmt->execute($scopeFilter['params']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

/**
 * @return array<int,array<string,mixed>>
 */
function fetch_ready_violation_rows(PDO $db, string $scope, ?int $limit): array
{
    $scopeFilter = scope_filter_sql($scope, 'g');
    $sql = "
        SELECT d.guarantee_id
        FROM guarantee_decisions d
        JOIN guarantees g ON g.id = d.guarantee_id
        WHERE d.status = 'ready'
          AND (d.supplier_id IS NULL OR d.bank_id IS NULL)
          {$scopeFilter['sql']}
        ORDER BY d.guarantee_id ASC
    " . limit_sql($limit);

    $stmt = $db->prepare($sql);
    $stmt->execute($scopeFilter['params']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

/**
 * @return array<int,array<string,mixed>>
 */
function fetch_generic_actor_rows(PDO $db, string $scope, ?int $limit): array
{
    $scopeFilter = scope_filter_sql($scope, 'g');
    $sql = "
        SELECT h.id
        FROM guarantee_history h
        JOIN guarantees g ON g.id = h.guarantee_id
        WHERE LOWER(TRIM(COALESCE(h.created_by, ''))) IN ('user', 'web_user', 'المستخدم', 'بواسطة المستخدم')
          {$scopeFilter['sql']}
        ORDER BY h.id ASC
    " . limit_sql($limit);

    $stmt = $db->prepare($sql);
    $stmt->execute($scopeFilter['params']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

/**
 * @return array<int,array<string,mixed>>
 */
function fetch_generic_note_actor_rows(PDO $db, string $scope, ?int $limit): array
{
    $scopeFilter = scope_filter_sql($scope, 'g');
    $sql = "
        SELECT n.id
        FROM guarantee_notes n
        JOIN guarantees g ON g.id = n.guarantee_id
        WHERE LOWER(TRIM(COALESCE(n.created_by, ''))) IN ('user', 'web_user', 'المستخدم', 'بواسطة المستخدم')
          {$scopeFilter['sql']}
        ORDER BY n.id ASC
    " . limit_sql($limit);

    $stmt = $db->prepare($sql);
    $stmt->execute($scopeFilter['params']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

/**
 * @return array<int,array<string,mixed>>
 */
function fetch_generic_attachment_actor_rows(PDO $db, string $scope, ?int $limit): array
{
    $scopeFilter = scope_filter_sql($scope, 'g');
    $sql = "
        SELECT a.id
        FROM guarantee_attachments a
        JOIN guarantees g ON g.id = a.guarantee_id
        WHERE LOWER(TRIM(COALESCE(a.uploaded_by, ''))) IN ('user', 'web_user', 'المستخدم', 'بواسطة المستخدم')
          {$scopeFilter['sql']}
        ORDER BY a.id ASC
    " . limit_sql($limit);

    $stmt = $db->prepare($sql);
    $stmt->execute($scopeFilter['params']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

try {
    /** @var array<string,string|false>|false $options */
    $options = getopt('', ['apply', 'scope::', 'limit::']);
    $apply = is_array($options) && array_key_exists('apply', $options);

    $scope = strtolower(option_value($options, 'scope', 'all'));
    if (!in_array($scope, ['all', 'real', 'test'], true)) {
        throw new RuntimeException('Invalid --scope value. Allowed: all|real|test');
    }

    $limitRaw = option_value($options, 'limit', '');
    $limit = null;
    if ($limitRaw !== '') {
        if (!preg_match('/^\d+$/', $limitRaw)) {
            throw new RuntimeException('Invalid --limit value. It must be a positive integer.');
        }
        $limit = (int)$limitRaw;
        if ($limit <= 0) {
            throw new RuntimeException('Invalid --limit value. It must be greater than zero.');
        }
    }

    $db = Database::connect();

    $missingHistoryRows = fetch_missing_history_rows($db, $scope, $limit);
    $readyViolationRows = fetch_ready_violation_rows($db, $scope, $limit);
    $genericActorRows = fetch_generic_actor_rows($db, $scope, $limit);
    $genericNoteActorRows = fetch_generic_note_actor_rows($db, $scope, $limit);
    $genericAttachmentActorRows = fetch_generic_attachment_actor_rows($db, $scope, $limit);

    echo "WBGL Legacy Timeline Integrity Repair\n";
    echo 'Mode: ' . ($apply ? 'APPLY' : 'DRY-RUN') . "\n";
    echo "Scope: {$scope}\n";
    echo 'Limit per phase: ' . ($limit !== null ? (string)$limit : 'none') . "\n";
    echo "- Missing history guarantees: " . count($missingHistoryRows) . "\n";
    echo "- Ready violations to repair: " . count($readyViolationRows) . "\n";
    echo "- Generic timeline actors to normalize: " . count($genericActorRows) . "\n";
    echo "- Generic note actors to normalize: " . count($genericNoteActorRows) . "\n";
    echo "- Generic attachment actors to normalize: " . count($genericAttachmentActorRows) . "\n";

    if (!$apply) {
        echo "Dry-run only. Re-run with --apply to persist fixes.\n";
        exit(0);
    }

    $repairActor = 'legacy_repair_bot';
    $unknownUserLabel = 'legacy_user_unresolved';
    $hasActorColumns =
        SchemaInspector::columnExists($db, 'guarantee_history', 'actor_kind') &&
        SchemaInspector::columnExists($db, 'guarantee_history', 'actor_display') &&
        SchemaInspector::columnExists($db, 'guarantee_history', 'actor_user_id') &&
        SchemaInspector::columnExists($db, 'guarantee_history', 'actor_username') &&
        SchemaInspector::columnExists($db, 'guarantee_history', 'actor_email');

    $updateDecisionStmt = $db->prepare(
        "UPDATE guarantee_decisions
         SET status = 'pending',
             is_locked = FALSE,
             locked_reason = NULL,
             active_action = NULL,
             active_action_set_at = NULL,
             last_modified_at = CURRENT_TIMESTAMP,
             last_modified_by = ?,
             updated_at = CURRENT_TIMESTAMP
         WHERE guarantee_id = ?
           AND status = 'ready'
           AND (supplier_id IS NULL OR bank_id IS NULL)"
    );

    if ($hasActorColumns) {
        $updateHistoryActorStmt = $db->prepare(
            "UPDATE guarantee_history
             SET created_by = ?,
                 actor_kind = 'user',
                 actor_display = ?,
                 actor_user_id = NULL,
                 actor_username = NULL,
                 actor_email = NULL
             WHERE id = ?"
        );
    } else {
        $updateHistoryActorStmt = $db->prepare(
            "UPDATE guarantee_history
             SET created_by = ?
             WHERE id = ?"
        );
    }

    $updateNotesActorStmt = $db->prepare(
        "UPDATE guarantee_notes
         SET created_by = ?
         WHERE id = ?"
    );

    $updateAttachmentActorStmt = $db->prepare(
        "UPDATE guarantee_attachments
         SET uploaded_by = ?
         WHERE id = ?"
    );

    $applied = [
        'history_backfilled' => 0,
        'ready_fixed' => 0,
        'actor_normalized' => 0,
        'notes_actor_normalized' => 0,
        'attachments_actor_normalized' => 0,
    ];

    $db->beginTransaction();
    try {
        foreach ($missingHistoryRows as $row) {
            $guaranteeId = (int)($row['id'] ?? 0);
            if ($guaranteeId <= 0) {
                continue;
            }

            $raw = decode_raw_data($row['raw_data'] ?? null);
            $sourceSubtype = normalize_source_subtype((string)($row['import_source'] ?? ''));
            $eventAt = (string)($row['imported_at'] ?? '');
            if (trim($eventAt) === '') {
                $eventAt = date('Y-m-d H:i:s');
            }

            $snapshot = build_import_snapshot($row, $raw);
            $eventDetails = [
                'source' => $sourceSubtype,
                'repair' => 'legacy_missing_history_backfill',
                'event_time' => $eventAt,
                'import_source_raw' => (string)($row['import_source'] ?? ''),
            ];

            $eventId = TimelineRecorder::recordStructuredEvent(
                $guaranteeId,
                'import',
                $sourceSubtype,
                $snapshot,
                [],
                $repairActor,
                $eventDetails,
                null,
                $snapshot
            );

            if (!$eventId) {
                throw new RuntimeException('Failed to backfill import history event for guarantee #' . $guaranteeId);
            }

            $applied['history_backfilled']++;
        }

        foreach ($readyViolationRows as $row) {
            $guaranteeId = (int)($row['guarantee_id'] ?? 0);
            if ($guaranteeId <= 0) {
                continue;
            }

            $beforeSnapshot = TimelineRecorder::createSnapshot($guaranteeId);

            $updateDecisionStmt->execute([$repairActor, $guaranteeId]);
            if ($updateDecisionStmt->rowCount() <= 0) {
                continue;
            }

            $eventId = TimelineRecorder::recordStatusTransitionEvent(
                $guaranteeId,
                is_array($beforeSnapshot) ? $beforeSnapshot : [],
                'pending',
                'legacy_repair_ready_requires_supplier_bank'
            );

            if (!$eventId) {
                throw new RuntimeException('Failed to record ready->pending repair event for guarantee #' . $guaranteeId);
            }

            $applied['ready_fixed']++;
        }

        foreach ($genericActorRows as $row) {
            $historyId = (int)($row['id'] ?? 0);
            if ($historyId <= 0) {
                continue;
            }

            if ($hasActorColumns) {
                $updateHistoryActorStmt->execute([$unknownUserLabel, $unknownUserLabel, $historyId]);
            } else {
                $updateHistoryActorStmt->execute([$unknownUserLabel, $historyId]);
            }

            if ($updateHistoryActorStmt->rowCount() > 0) {
                $applied['actor_normalized']++;
            }
        }

        foreach ($genericNoteActorRows as $row) {
            $noteId = (int)($row['id'] ?? 0);
            if ($noteId <= 0) {
                continue;
            }

            $updateNotesActorStmt->execute([$unknownUserLabel, $noteId]);
            if ($updateNotesActorStmt->rowCount() > 0) {
                $applied['notes_actor_normalized']++;
            }
        }

        foreach ($genericAttachmentActorRows as $row) {
            $attachmentId = (int)($row['id'] ?? 0);
            if ($attachmentId <= 0) {
                continue;
            }

            $updateAttachmentActorStmt->execute([$unknownUserLabel, $attachmentId]);
            if ($updateAttachmentActorStmt->rowCount() > 0) {
                $applied['attachments_actor_normalized']++;
            }
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    echo "Applied:\n";
    echo "- history_backfilled={$applied['history_backfilled']}\n";
    echo "- ready_fixed={$applied['ready_fixed']}\n";
    echo "- actor_normalized={$applied['actor_normalized']}\n";
    echo "- notes_actor_normalized={$applied['notes_actor_normalized']}\n";
    echo "- attachments_actor_normalized={$applied['attachments_actor_normalized']}\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Repair failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
