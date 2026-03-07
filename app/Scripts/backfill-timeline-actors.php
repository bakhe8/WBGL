<?php
declare(strict_types=1);

require_once __DIR__ . '/../Support/autoload.php';

use App\Services\TimelinePresentationNormalizer;
use App\Support\Database;
use App\Support\SchemaInspector;

/**
 * Backfill normalized actor columns for historical timeline rows.
 *
 * Usage:
 *   php app/Scripts/backfill-timeline-actors.php           # dry-run
 *   php app/Scripts/backfill-timeline-actors.php --apply   # execute updates
 */

/**
 * @return array{scanned:int,updated:int}
 */
function wbgl_backfill_table(PDO $db, string $table, bool $apply): array
{
    $requiredColumns = ['actor_kind', 'actor_display', 'actor_user_id', 'actor_username', 'actor_email', 'created_by'];
    foreach ($requiredColumns as $column) {
        if (!SchemaInspector::columnExists($db, $table, $column)) {
            return ['scanned' => 0, 'updated' => 0];
        }
    }

    $sql = "
        SELECT id, created_by, actor_kind, actor_display, actor_user_id, actor_username, actor_email
        FROM {$table}
        ORDER BY id ASC
    ";
    $stmt = $db->query($sql);
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    if (!is_array($rows)) {
        return ['scanned' => 0, 'updated' => 0];
    }

    $update = $db->prepare("
        UPDATE {$table}
        SET actor_kind = ?, actor_display = ?, actor_user_id = ?, actor_username = ?, actor_email = ?
        WHERE id = ?
    ");

    $scanned = 0;
    $updated = 0;
    foreach ($rows as $row) {
        $scanned++;
        $createdBy = (string)($row['created_by'] ?? 'system');
        $normalized = TimelinePresentationNormalizer::actorStorageFromCreator($createdBy);

        $current = [
            'actor_kind' => $row['actor_kind'] ?? null,
            'actor_display' => $row['actor_display'] ?? null,
            'actor_user_id' => isset($row['actor_user_id']) ? (int)$row['actor_user_id'] : null,
            'actor_username' => $row['actor_username'] ?? null,
            'actor_email' => $row['actor_email'] ?? null,
        ];

        $needsUpdate =
            (string)($current['actor_kind'] ?? '') !== (string)($normalized['actor_kind'] ?? '') ||
            (string)($current['actor_display'] ?? '') !== (string)($normalized['actor_display'] ?? '') ||
            (int)($current['actor_user_id'] ?? 0) !== (int)($normalized['actor_user_id'] ?? 0) ||
            (string)($current['actor_username'] ?? '') !== (string)($normalized['actor_username'] ?? '') ||
            (string)($current['actor_email'] ?? '') !== (string)($normalized['actor_email'] ?? '');

        if (!$needsUpdate) {
            continue;
        }

        $updated++;
        if ($apply) {
            $update->execute([
                $normalized['actor_kind'],
                $normalized['actor_display'],
                $normalized['actor_user_id'],
                $normalized['actor_username'],
                $normalized['actor_email'],
                (int)$row['id'],
            ]);
        }
    }

    return ['scanned' => $scanned, 'updated' => $updated];
}

try {
    /** @var array<string,string|false>|false $options */
    $options = getopt('', ['apply']);
    $apply = is_array($options) && array_key_exists('apply', $options);

    $db = Database::connect();
    $db->beginTransaction();

    $history = wbgl_backfill_table($db, 'guarantee_history', $apply);
    $archive = wbgl_backfill_table($db, 'guarantee_history_archive', $apply);

    if ($apply) {
        $db->commit();
    } else {
        $db->rollBack();
    }

    $mode = $apply ? 'APPLY' : 'DRY-RUN';
    echo "WBGL Timeline Actor Backfill ({$mode})" . PHP_EOL;
    echo "guarantee_history: scanned={$history['scanned']} updated={$history['updated']}" . PHP_EOL;
    echo "guarantee_history_archive: scanned={$archive['scanned']} updated={$archive['updated']}" . PHP_EOL;
    exit(0);
} catch (\Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'Backfill failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
