<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\Guard;
use App\Support\Settings;
use RuntimeException;

class BreakGlassService
{
    /**
     * @return array{enabled:bool,reason:string,ticket_ref:string,ttl_minutes:int,payload:array}
     */
    public static function parseInput(array $input): array
    {
        $raw = $input['break_glass'] ?? null;
        $enabled = false;
        $reason = '';
        $ticketRef = '';
        $ttlMinutes = 0;
        $payload = [];

        if (is_array($raw)) {
            $enabled = self::toBool($raw['enabled'] ?? true);
            $reason = trim((string)($raw['reason'] ?? ''));
            $ticketRef = trim((string)($raw['ticket_ref'] ?? ($raw['ticket'] ?? '')));
            $ttlMinutes = self::toInt($raw['ttl_minutes'] ?? 0);
            $payload = $raw;
        } else {
            $enabled = self::toBool($input['break_glass_enabled'] ?? false);
            $reason = trim((string)($input['break_glass_reason'] ?? ''));
            $ticketRef = trim((string)($input['break_glass_ticket'] ?? ($input['ticket_ref'] ?? '')));
            $ttlMinutes = self::toInt($input['break_glass_ttl_minutes'] ?? 0);
            $payload = [
                'enabled' => $enabled,
                'reason' => $reason,
                'ticket_ref' => $ticketRef,
                'ttl_minutes' => $ttlMinutes,
            ];
        }

        return [
            'enabled' => $enabled,
            'reason' => $reason,
            'ticket_ref' => $ticketRef,
            'ttl_minutes' => $ttlMinutes,
            'payload' => is_array($payload) ? $payload : [],
        ];
    }

    public static function isRequested(array $input): bool
    {
        $parsed = self::parseInput($input);
        return $parsed['enabled'];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{id:int,expires_at:string,ticket_ref:?string,reason:string}
     */
    public static function authorizeAndRecord(
        array $input,
        string $actionName,
        string $targetType,
        ?string $targetId,
        string $requestedBy
    ): array {
        $parsed = self::parseInput($input);
        if (!$parsed['enabled']) {
            throw new RuntimeException('Emergency override is not requested');
        }

        if (!Guard::has('break_glass_override')) {
            throw new RuntimeException('ليس لديك صلاحية تنفيذ تجاوز الطوارئ');
        }

        $settings = Settings::getInstance();
        if (!(bool)$settings->get('BREAK_GLASS_ENABLED', false)) {
            throw new RuntimeException('تجاوز الطوارئ معطل حالياً');
        }

        $reason = trim($parsed['reason']);
        if (mb_strlen($reason) < 8) {
            throw new RuntimeException('سبب الطوارئ مطلوب (8 أحرف على الأقل)');
        }

        $requireTicket = (bool)$settings->get('BREAK_GLASS_REQUIRE_TICKET', true);
        $ticketRef = trim($parsed['ticket_ref']);
        if ($requireTicket && $ticketRef === '') {
            throw new RuntimeException('رقم التذكرة/الحادث مطلوب لتفعيل الطوارئ');
        }

        $defaultTtl = max(5, (int)$settings->get('BREAK_GLASS_DEFAULT_TTL_MINUTES', 60));
        $maxTtl = max($defaultTtl, (int)$settings->get('BREAK_GLASS_MAX_TTL_MINUTES', 240));
        $ttlMinutes = $parsed['ttl_minutes'] > 0 ? $parsed['ttl_minutes'] : $defaultTtl;
        $ttlMinutes = max(5, min($maxTtl, $ttlMinutes));

        $expiresAt = date('Y-m-d H:i:s', time() + ($ttlMinutes * 60));
        $requestedBy = trim($requestedBy) !== '' ? trim($requestedBy) : 'النظام';
        $targetId = $targetId !== null && trim($targetId) !== '' ? trim($targetId) : null;
        $payload = json_encode($parsed['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $db = Database::connect();
        $stmt = $db->prepare(
            'INSERT INTO break_glass_events
             (action_name, target_type, target_id, requested_by, reason, ticket_ref, ttl_minutes, expires_at, payload_json, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            $actionName,
            $targetType,
            $targetId,
            $requestedBy,
            $reason,
            $ticketRef !== '' ? $ticketRef : null,
            $ttlMinutes,
            $expiresAt,
            $payload,
        ]);

        return [
            'id' => (int)$db->lastInsertId(),
            'expires_at' => $expiresAt,
            'ticket_ref' => $ticketRef !== '' ? $ticketRef : null,
            'reason' => $reason,
        ];
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (bool)$value;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
        }
        return false;
    }

    private static function toInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int)$value;
        }
        if (is_string($value) && trim($value) !== '' && is_numeric($value)) {
            return (int)$value;
        }
        return 0;
    }
}
