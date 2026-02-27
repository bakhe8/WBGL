<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use PDO;

class SettingsAuditService
{
    public static function recordChangeSet(
        array $before,
        array $after,
        array $submitted,
        string $changedBy,
        ?string $sourceIp = null,
        ?string $userAgent = null
    ): int {
        $changedBy = trim($changedBy) !== '' ? trim($changedBy) : 'النظام';
        $sourceIp = $sourceIp !== null && trim($sourceIp) !== '' ? trim($sourceIp) : null;
        $userAgent = $userAgent !== null && trim($userAgent) !== '' ? trim($userAgent) : null;

        $keys = array_keys($submitted);
        if (empty($keys)) {
            return 0;
        }

        $token = self::generateToken();
        $db = Database::connect();
        $stmt = $db->prepare(
            'INSERT INTO settings_audit_logs
             (change_set_token, setting_key, old_value_json, new_value_json, changed_by, source_ip, user_agent, changed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)'
        );

        $inserted = 0;
        foreach ($keys as $key) {
            if (!is_string($key) || trim($key) === '') {
                continue;
            }

            $oldValue = array_key_exists($key, $before) ? $before[$key] : null;
            $newValue = array_key_exists($key, $after) ? $after[$key] : null;
            if (!self::isDifferent($oldValue, $newValue)) {
                continue;
            }

            $stmt->execute([
                $token,
                $key,
                self::toJson($oldValue),
                self::toJson($newValue),
                $changedBy,
                $sourceIp,
                $userAgent,
            ]);
            $inserted++;
        }

        return $inserted;
    }

    public static function listRecent(int $limit = 100, ?string $settingKey = null): array
    {
        $limit = max(1, min(500, $limit));
        $db = Database::connect();

        if ($settingKey !== null && trim($settingKey) !== '') {
            $stmt = $db->prepare(
                "SELECT id, change_set_token, setting_key, old_value_json, new_value_json, changed_by, source_ip, user_agent, changed_at
                 FROM settings_audit_logs
                 WHERE setting_key = ?
                 ORDER BY id DESC
                 LIMIT {$limit}"
            );
            $stmt->execute([trim($settingKey)]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $db->query(
                "SELECT id, change_set_token, setting_key, old_value_json, new_value_json, changed_by, source_ip, user_agent, changed_at
                 FROM settings_audit_logs
                 ORDER BY id DESC
                 LIMIT {$limit}"
            );
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        }

        return array_map(static function (array $row): array {
            $row['old_value'] = self::fromJson((string)($row['old_value_json'] ?? 'null'));
            $row['new_value'] = self::fromJson((string)($row['new_value_json'] ?? 'null'));
            unset($row['old_value_json'], $row['new_value_json']);
            return $row;
        }, $rows);
    }

    private static function isDifferent(mixed $oldValue, mixed $newValue): bool
    {
        return self::toJson($oldValue) !== self::toJson($newValue);
    }

    private static function toJson(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function fromJson(string $json): mixed
    {
        $decoded = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $json;
        }
        return $decoded;
    }

    private static function generateToken(): string
    {
        try {
            return 'cfg-' . bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            return 'cfg-' . str_replace('.', '', (string)microtime(true));
        }
    }
}
