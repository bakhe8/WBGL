<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Archives guarantee_history rows before destructive operations.
 *
 * This preserves timeline auditability even when parent guarantees are removed.
 */
final class HistoryArchiveService
{
    /**
     * @param array<int,int|string> $guaranteeIds
     */
    public static function archiveForGuarantees(
        PDO $db,
        array $guaranteeIds,
        string $reason = 'delete_operation',
        string $actor = 'system'
    ): int {
        $normalizedIds = array_values(array_unique(array_filter(array_map(
            static fn($id): int => (int)$id,
            $guaranteeIds
        ), static fn(int $id): bool => $id > 0)));

        if (empty($normalizedIds)) {
            return 0;
        }

        if (!self::archiveTableExists($db)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($normalizedIds), '?'));

        $sqlBase = "
            INSERT INTO guarantee_history_archive (
                original_history_id,
                guarantee_id,
                event_type,
                event_subtype,
                snapshot_data,
                event_details,
                letter_snapshot,
                history_version,
                patch_data,
                anchor_snapshot,
                is_anchor,
                anchor_reason,
                letter_context,
                template_version,
                created_at,
                created_by,
                archived_at,
                archived_by,
                archive_reason,
                source_table
            )
            SELECT
                gh.id,
                gh.guarantee_id,
                gh.event_type,
                gh.event_subtype,
                gh.snapshot_data,
                gh.event_details,
                gh.letter_snapshot,
                gh.history_version,
                gh.patch_data,
                gh.anchor_snapshot,
                gh.is_anchor,
                gh.anchor_reason,
                gh.letter_context,
                gh.template_version,
                gh.created_at,
                gh.created_by,
                CURRENT_TIMESTAMP,
                ?,
                ?,
                'guarantee_history'
            FROM guarantee_history gh
            WHERE gh.guarantee_id IN ({$placeholders})
        ";

        $sql = $sqlBase . " ON CONFLICT (original_history_id, source_table) DO NOTHING";

        $params = array_merge([$actor, $reason], $normalizedIds);
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return (int)$stmt->rowCount();
    }

    private static function archiveTableExists(PDO $db): bool
    {
        $stmt = $db->prepare("
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = 'public'
              AND table_name = 'guarantee_history_archive'
            LIMIT 1
        ");
        $stmt->execute();
        return (bool)$stmt->fetchColumn();
    }
}
