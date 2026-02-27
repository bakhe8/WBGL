<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\AuthService;
use App\Support\Database;
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
        ?string $dedupeKey = null
    ): int {
        $type = trim($type);
        $title = trim($title);
        $message = trim($message);
        if ($type === '' || $title === '' || $message === '') {
            throw new RuntimeException('type/title/message are required');
        }

        $db = Database::connect();
        $payload = !empty($data) ? json_encode($data, JSON_UNESCAPED_UNICODE) : null;

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

        return (int)$db->lastInsertId();
    }

    public static function listForCurrentUser(int $limit = 50, bool $unreadOnly = false): array
    {
        $user = AuthService::getCurrentUser();
        $username = $user?->username;
        $limit = max(1, min(200, $limit));

        $db = Database::connect();
        $sql = "
            SELECT id, recipient_username, type, title, message, data_json, is_read, read_at, created_at
            FROM notifications
            WHERE (recipient_username IS NULL OR recipient_username = :username)
        ";
        if ($unreadOnly) {
            $sql .= " AND is_read = 0";
        }
        $sql .= " ORDER BY id DESC LIMIT {$limit}";

        $stmt = $db->prepare($sql);
        $stmt->execute(['username' => $username ?? '']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static function (array $row): array {
            $row['is_read'] = (int)($row['is_read'] ?? 0);
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
        $username = $user?->username;

        $db = Database::connect();
        $stmt = $db->prepare(
            "UPDATE notifications
             SET is_read = 1, read_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?
               AND (recipient_username IS NULL OR recipient_username = ?)"
        );
        $stmt->execute([$notificationId, $username ?? '']);
    }

    public static function markAllReadForCurrentUser(): int
    {
        $user = AuthService::getCurrentUser();
        $username = $user?->username;

        $db = Database::connect();
        $stmt = $db->prepare(
            "UPDATE notifications
             SET is_read = 1, read_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE is_read = 0
               AND (recipient_username IS NULL OR recipient_username = ?)"
        );
        $stmt->execute([$username ?? '']);
        return (int)$stmt->rowCount();
    }
}
