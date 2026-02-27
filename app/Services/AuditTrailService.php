<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\AuthService;
use App\Support\Database;
use PDO;
use Throwable;

class AuditTrailService
{
    /**
     * @param array<string,mixed> $details
     */
    public static function record(
        string $eventType,
        string $action,
        ?string $targetType = null,
        ?string $targetId = null,
        array $details = [],
        string $severity = 'info'
    ): void {
        try {
            $db = Database::connect();
            if (!self::tableExists($db)) {
                return;
            }

            $user = AuthService::getCurrentUser();
            $actorId = $user?->id;
            $actorDisplay = $user?->fullName ?: ($user?->username ?: 'النظام');

            $stmt = $db->prepare(
                'INSERT INTO audit_trail_events
                    (event_type, actor_user_id, actor_display, target_type, target_id, action, severity, details_json, ip_address, user_agent, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)'
            );

            $stmt->execute([
                trim($eventType),
                $actorId,
                $actorDisplay,
                $targetType,
                $targetId,
                trim($action),
                trim($severity) !== '' ? trim($severity) : 'info',
                json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]);
        } catch (Throwable $e) {
            // Non-blocking by design.
        }
    }

    private static function tableExists(PDO $db): bool
    {
        try {
            $stmt = $db->prepare("
                SELECT 1
                FROM information_schema.tables
                WHERE table_schema = 'public'
                  AND table_name = 'audit_trail_events'
                LIMIT 1
            ");
            $stmt->execute();
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}
