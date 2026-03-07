<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\AuthService;
use App\Support\Database;
use App\Support\SchemaInspector;
use PDO;
use RuntimeException;

class NotificationService
{
    public static function create(
        string $type,
        string $title,
        string $message,
        ?string $recipientUsername = null,
        array $data = [],
        ?string $dedupeKey = null,
        ?string $recipientRoleSlug = null
    ): int {
        $type = trim($type);
        $title = trim($title);
        $message = trim($message);
        if ($type === '' || $title === '' || $message === '') {
            throw new RuntimeException('type/title/message are required');
        }

        $db = Database::connect();
        $payload = !empty($data) ? json_encode($data, JSON_UNESCAPED_UNICODE) : null;
        $recipientUsername = self::normalizeNullableText($recipientUsername);
        $recipientRoleSlug = self::normalizeNullableText($recipientRoleSlug);
        if ($recipientUsername !== null && $recipientRoleSlug !== null) {
            throw new RuntimeException('recipient_username and recipient_role_slug cannot be used together');
        }

        if ($dedupeKey !== null && trim($dedupeKey) !== '') {
            $stmt = $db->prepare('SELECT id FROM notifications WHERE dedupe_key = ? LIMIT 1');
            $stmt->execute([trim($dedupeKey)]);
            $existing = $stmt->fetchColumn();
            if ($existing) {
                return (int)$existing;
            }
        } else {
            $dedupeKey = null;
        }

        if (self::isRoleTargetingSupported($db)) {
            $visibilityScope = 'global';
            if ($recipientUsername !== null) {
                $visibilityScope = 'user';
            } elseif ($recipientRoleSlug !== null) {
                $visibilityScope = 'role';
            }

            $stmt = $db->prepare(
                'INSERT INTO notifications
                 (recipient_username, recipient_role_slug, visibility_scope, type, title, message, data_json, dedupe_key, is_read, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
            );
            $stmt->execute([
                $recipientUsername,
                $recipientRoleSlug,
                $visibilityScope,
                $type,
                $title,
                $message,
                $payload,
                $dedupeKey,
            ]);
        } else {
            // Legacy fallback (pre role-targeting schema).
            $stmt = $db->prepare(
                'INSERT INTO notifications
                 (recipient_username, type, title, message, data_json, dedupe_key, is_read, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
            );
            $stmt->execute([
                $recipientUsername,
                $type,
                $title,
                $message,
                $payload,
                $dedupeKey,
            ]);
        }

        return (int)$db->lastInsertId();
    }

    public static function createForRole(
        string $roleSlug,
        string $type,
        string $title,
        string $message,
        array $data = [],
        ?string $dedupeKey = null
    ): int {
        return self::create($type, $title, $message, null, $data, $dedupeKey, $roleSlug);
    }

    public static function listForCurrentUser(
        int $limit = 50,
        bool $unreadOnly = false,
        bool $includeHidden = false
    ): array {
        $user = AuthService::getCurrentUser();
        $username = trim((string)($user?->username ?? ''));
        if ($username === '') {
            return [];
        }
        $limit = max(1, min(200, $limit));
        $db = Database::connect();

        if (self::isPerUserStateSupported($db)) {
            $roleSlug = self::resolveCurrentRoleSlug();
            $sql = "
                SELECT
                    n.id,
                    n.recipient_username,
                    n.recipient_role_slug,
                    n.visibility_scope,
                    n.type,
                    n.title,
                    n.message,
                    n.data_json,
                    COALESCE(ns.is_read, n.is_read, 0) AS is_read,
                    ns.read_at,
                    COALESCE(ns.is_hidden, 0) AS is_hidden,
                    ns.hidden_at,
                    n.created_at
                FROM notifications n
                LEFT JOIN notification_user_states ns
                    ON ns.notification_id = n.id
                   AND ns.username = :username
                WHERE " . self::visibilityPredicateSql() . "
            ";

            if (!$includeHidden) {
                $sql .= " AND COALESCE(ns.is_hidden, 0) = 0";
            }
            if ($unreadOnly) {
                $sql .= " AND COALESCE(ns.is_read, n.is_read, 0) = 0";
            }
            // Show newest notifications first so recent alerts are not pushed
            // out by older unread backlog entries.
            $sql .= " ORDER BY n.created_at DESC, n.id DESC LIMIT {$limit}";

            $stmt = $db->prepare($sql);
            $stmt->execute([
                'username' => $username,
                'role_slug' => $roleSlug ?? '',
            ]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $sql = "
                SELECT id, recipient_username, type, title, message, data_json, is_read, read_at, created_at
                FROM notifications
                WHERE (recipient_username IS NULL OR recipient_username = :username)
            ";
            if ($unreadOnly) {
                $sql .= " AND is_read = 0";
            }
            $sql .= " ORDER BY created_at DESC, id DESC LIMIT {$limit}";

            $stmt = $db->prepare($sql);
            $stmt->execute(['username' => $username]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return array_map(static function (array $row): array {
            $row['is_read'] = (int)($row['is_read'] ?? 0);
            $row['is_hidden'] = (int)($row['is_hidden'] ?? 0);
            $row['data'] = [];
            if (!empty($row['data_json'])) {
                $decoded = json_decode((string)$row['data_json'], true);
                if (is_array($decoded)) {
                    $row['data'] = $decoded;
                }
            }
            unset($row['data_json']);
            return $row;
        }, $rows);
    }

    public static function markReadForCurrentUser(int $notificationId): void
    {
        if ($notificationId <= 0) {
            throw new RuntimeException('notification_id is required');
        }
        $user = AuthService::getCurrentUser();
        $username = trim((string)($user?->username ?? ''));
        if ($username === '') {
            // Backward-compatible behavior for non-session contexts (e.g. legacy unit tests/CLI paths):
            // mark the base notification row as read.
            $db = Database::connect();
            $stmt = $db->prepare(
                "UPDATE notifications
                 SET is_read = 1, read_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?"
            );
            $stmt->execute([$notificationId]);
            return;
        }

        $db = Database::connect();
        if (self::isPerUserStateSupported($db)) {
            if (!self::isVisibleForUser($db, $notificationId, $username, self::resolveCurrentRoleSlug())) {
                throw new RuntimeException('Notification not visible for current user');
            }

            $stmt = $db->prepare(
                "INSERT INTO notification_user_states
                    (notification_id, username, is_read, is_hidden, read_at, created_at, updated_at)
                 VALUES (?, ?, 1, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                 ON CONFLICT (notification_id, username)
                 DO UPDATE SET
                    is_read = 1,
                    read_at = COALESCE(notification_user_states.read_at, CURRENT_TIMESTAMP),
                    updated_at = CURRENT_TIMESTAMP"
            );
            $stmt->execute([$notificationId, $username]);
            return;
        }

        // Legacy fallback.
        $stmt = $db->prepare(
            "UPDATE notifications
             SET is_read = 1, read_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?
               AND (recipient_username IS NULL OR recipient_username = ?)"
        );
        $stmt->execute([$notificationId, $username]);
    }

    public static function hideForCurrentUser(int $notificationId): void
    {
        if ($notificationId <= 0) {
            throw new RuntimeException('notification_id is required');
        }
        $user = AuthService::getCurrentUser();
        $username = trim((string)($user?->username ?? ''));
        if ($username === '') {
            return;
        }

        $db = Database::connect();
        if (!self::isPerUserStateSupported($db)) {
            // Legacy fallback: hide == mark read.
            self::markReadForCurrentUser($notificationId);
            return;
        }

        if (!self::isVisibleForUser($db, $notificationId, $username, self::resolveCurrentRoleSlug())) {
            throw new RuntimeException('Notification not visible for current user');
        }

        $stmt = $db->prepare(
            "INSERT INTO notification_user_states
                (notification_id, username, is_read, is_hidden, read_at, hidden_at, created_at, updated_at)
             VALUES (?, ?, 1, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
             ON CONFLICT (notification_id, username)
             DO UPDATE SET
                is_read = 1,
                is_hidden = 1,
                read_at = COALESCE(notification_user_states.read_at, CURRENT_TIMESTAMP),
                hidden_at = COALESCE(notification_user_states.hidden_at, CURRENT_TIMESTAMP),
                updated_at = CURRENT_TIMESTAMP"
        );
        $stmt->execute([$notificationId, $username]);
    }

    public static function markAllReadForCurrentUser(): int
    {
        $user = AuthService::getCurrentUser();
        $username = trim((string)($user?->username ?? ''));
        if ($username === '') {
            return 0;
        }

        $db = Database::connect();
        if (self::isPerUserStateSupported($db)) {
            $roleSlug = self::resolveCurrentRoleSlug();
            $stmt = $db->prepare(
                "INSERT INTO notification_user_states
                    (notification_id, username, is_read, is_hidden, read_at, created_at, updated_at)
                 SELECT
                    n.id,
                    :username,
                    1,
                    COALESCE(ns.is_hidden, 0),
                    CURRENT_TIMESTAMP,
                    CURRENT_TIMESTAMP,
                    CURRENT_TIMESTAMP
                 FROM notifications n
                 LEFT JOIN notification_user_states ns
                    ON ns.notification_id = n.id
                   AND ns.username = :username
                 WHERE " . self::visibilityPredicateSql() . "
                   AND COALESCE(ns.is_hidden, 0) = 0
                   AND COALESCE(ns.is_read, n.is_read, 0) = 0
                 ON CONFLICT (notification_id, username)
                 DO UPDATE SET
                    is_read = 1,
                    read_at = COALESCE(notification_user_states.read_at, CURRENT_TIMESTAMP),
                    updated_at = CURRENT_TIMESTAMP"
            );
            $stmt->execute([
                'username' => $username,
                'role_slug' => $roleSlug ?? '',
            ]);
            return (int)$stmt->rowCount();
        }

        // Legacy fallback.
        $stmt = $db->prepare(
            "UPDATE notifications
             SET is_read = 1, read_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE is_read = 0
               AND (recipient_username IS NULL OR recipient_username = ?)"
        );
        $stmt->execute([$username]);
        return (int)$stmt->rowCount();
    }

    public static function countUnreadForCurrentUser(): int
    {
        $user = AuthService::getCurrentUser();
        $username = trim((string)($user?->username ?? ''));
        if ($username === '') {
            return 0;
        }

        $db = Database::connect();
        if (self::isPerUserStateSupported($db)) {
            $roleSlug = self::resolveCurrentRoleSlug();
            $stmt = $db->prepare(
                "SELECT COUNT(*)
                 FROM notifications n
                 LEFT JOIN notification_user_states ns
                    ON ns.notification_id = n.id
                   AND ns.username = :username
                 WHERE " . self::visibilityPredicateSql() . "
                   AND COALESCE(ns.is_hidden, 0) = 0
                   AND COALESCE(ns.is_read, n.is_read, 0) = 0"
            );
            $stmt->execute([
                'username' => $username,
                'role_slug' => $roleSlug ?? '',
            ]);
            return (int)$stmt->fetchColumn();
        }

        // Legacy fallback.
        $stmt = $db->prepare(
            "SELECT COUNT(*)
             FROM notifications
             WHERE is_read = 0
               AND (recipient_username IS NULL OR recipient_username = ?)"
        );
        $stmt->execute([$username]);
        return (int)$stmt->fetchColumn();
    }

    private static function visibilityPredicateSql(): string
    {
        return "(
            (n.visibility_scope = 'global' AND :role_slug = 'developer')
            OR (n.visibility_scope = 'user' AND n.recipient_username = :username)
            OR (n.visibility_scope = 'role' AND n.recipient_role_slug = :role_slug)
            OR (
                n.visibility_scope IS NULL
                AND (
                    n.recipient_username = :username
                    OR (n.recipient_username IS NULL AND :role_slug = 'developer')
                )
            )
        )";
    }

    private static function isVisibleForUser(PDO $db, int $notificationId, string $username, ?string $roleSlug): bool
    {
        $stmt = $db->prepare(
            "SELECT n.id
             FROM notifications n
             WHERE n.id = :id
               AND " . self::visibilityPredicateSql() . "
             LIMIT 1"
        );
        $stmt->execute([
            'id' => $notificationId,
            'username' => $username,
            'role_slug' => $roleSlug ?? '',
        ]);
        return (bool)$stmt->fetchColumn();
    }

    private static function resolveCurrentRoleSlug(): ?string
    {
        $user = AuthService::getCurrentUser();
        if ($user === null || $user->roleId === null) {
            return null;
        }

        try {
            $db = Database::connect();
            $stmt = $db->prepare('SELECT slug FROM roles WHERE id = ? LIMIT 1');
            $stmt->execute([(int)$user->roleId]);
            $slug = $stmt->fetchColumn();
            if (!is_string($slug)) {
                return null;
            }
            return self::normalizeNullableText($slug);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function isRoleTargetingSupported(PDO $db): bool
    {
        return self::hasColumn($db, 'notifications', 'visibility_scope')
            && self::hasColumn($db, 'notifications', 'recipient_role_slug');
    }

    private static function isPerUserStateSupported(PDO $db): bool
    {
        return self::isRoleTargetingSupported($db)
            && self::hasTable($db, 'notification_user_states');
    }

    private static function hasTable(PDO $db, string $tableName): bool
    {
        return SchemaInspector::tableExists($db, $tableName);
    }

    private static function hasColumn(PDO $db, string $tableName, string $columnName): bool
    {
        return SchemaInspector::columnExists($db, $tableName, $columnName);
    }

    private static function normalizeNullableText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }
}
