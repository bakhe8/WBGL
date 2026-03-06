<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Settings;
use Throwable;

final class NotificationPolicyService
{
    /**
     * Default in-app notification policy by notification type.
     *
     * @var array<string, array<string, mixed>>
     */
    private const DEFAULT_POLICY = [
        'workflow_reject' => [
            'category' => 'workflow',
            'severity' => 'warning',
            'roles' => ['data_entry', 'developer'],
            'allow_direct_user' => false,
            'fallback_global' => false,
        ],
        'break_glass_override_used' => [
            'category' => 'security',
            'severity' => 'warning',
            'roles' => ['developer', 'supervisor', 'approver'],
            'allow_direct_user' => false,
            'fallback_global' => false,
        ],
        'import_failure' => [
            'category' => 'operations',
            'severity' => 'error',
            'roles' => ['developer'],
            'allow_direct_user' => true,
            'fallback_global' => false,
        ],
        'scheduler_failure' => [
            'category' => 'operations',
            'severity' => 'error',
            'roles' => ['developer'],
            'allow_direct_user' => false,
            'fallback_global' => false,
        ],
        'undo_request_submitted' => [
            'category' => 'governance',
            'severity' => 'warning',
            'roles' => ['supervisor', 'approver', 'developer'],
            'allow_direct_user' => false,
            'fallback_global' => false,
        ],
        'undo_request_approved' => [
            'category' => 'workflow',
            'severity' => 'info',
            'roles' => ['developer'],
            'allow_direct_user' => true,
            'fallback_global' => false,
        ],
        'undo_request_rejected' => [
            'category' => 'workflow',
            'severity' => 'warning',
            'roles' => ['developer'],
            'allow_direct_user' => true,
            'fallback_global' => false,
        ],
        'undo_request_executed' => [
            'category' => 'workflow',
            'severity' => 'success',
            'roles' => ['developer'],
            'allow_direct_user' => true,
            'fallback_global' => false,
        ],
    ];

    /**
     * Emit a notification using centralized routing policy.
     *
     * @return array<int,int> Created notification IDs.
     */
    public static function emit(
        string $type,
        string $title,
        string $message,
        array $data = [],
        ?string $dedupeKey = null,
        ?string $directUsername = null,
        ?array $roleTargetsOverride = null
    ): array {
        if (!self::isEnabled()) {
            return [];
        }

        $type = trim($type);
        $title = trim($title);
        $message = trim($message);
        if ($type === '' || $title === '' || $message === '') {
            return [];
        }

        $policy = self::policyForType($type);
        $roles = self::normalizeRoleList($roleTargetsOverride ?? ($policy['roles'] ?? []));
        $allowDirectUser = (bool)($policy['allow_direct_user'] ?? true);
        $fallbackGlobal = (bool)($policy['fallback_global'] ?? true);
        $recipientUser = self::normalizeNullableText($directUsername);

        $payload = self::attachMetadata($type, $policy, $roles, $recipientUser, $data);

        $createdIds = [];
        $baseDedupe = self::normalizeNullableText($dedupeKey);

        foreach ($roles as $roleSlug) {
            $roleDedupe = $baseDedupe !== null ? "{$baseDedupe}:role:{$roleSlug}" : null;
            $createdIds[] = NotificationService::createForRole(
                $roleSlug,
                $type,
                $title,
                $message,
                $payload,
                $roleDedupe
            );
        }

        if ($allowDirectUser && $recipientUser !== null) {
            $userDedupe = $baseDedupe !== null ? "{$baseDedupe}:user:{$recipientUser}" : null;
            $createdIds[] = NotificationService::create(
                $type,
                $title,
                $message,
                $recipientUser,
                $payload,
                $userDedupe
            );
        }

        if (empty($createdIds) && $fallbackGlobal) {
            $createdIds[] = NotificationService::create(
                $type,
                $title,
                $message,
                null,
                $payload,
                $baseDedupe
            );
        }

        return array_values(array_filter(array_map('intval', $createdIds), static fn (int $id): bool => $id > 0));
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function defaultPolicyMap(): array
    {
        return self::DEFAULT_POLICY;
    }

    private static function isEnabled(): bool
    {
        try {
            return (bool)Settings::getInstance()->get('NOTIFICATIONS_ENABLED', true);
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private static function policyForType(string $type): array
    {
        $base = self::DEFAULT_POLICY[$type] ?? [
            'category' => 'system',
            'severity' => 'info',
            'roles' => [],
            'allow_direct_user' => true,
            'fallback_global' => true,
        ];

        $overrideMap = self::readOverridePolicyMap();
        $override = $overrideMap[$type] ?? null;
        if (!is_array($override)) {
            return $base;
        }

        $merged = array_merge($base, $override);
        $merged['roles'] = self::normalizeRoleList($merged['roles'] ?? []);
        $merged['category'] = self::normalizeCategory((string)($merged['category'] ?? 'system'));
        $merged['severity'] = self::normalizeSeverity((string)($merged['severity'] ?? 'info'));
        $merged['allow_direct_user'] = (bool)($merged['allow_direct_user'] ?? true);
        $merged['fallback_global'] = (bool)($merged['fallback_global'] ?? true);
        return $merged;
    }

    /**
     * @return array<string, array<string,mixed>>
     */
    private static function readOverridePolicyMap(): array
    {
        try {
            $raw = Settings::getInstance()->get('NOTIFICATION_POLICY_OVERRIDES', []);
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                $raw = is_array($decoded) ? $decoded : [];
            }
            if (!is_array($raw)) {
                return [];
            }

            $result = [];
            foreach ($raw as $type => $policy) {
                if (!is_string($type) || trim($type) === '' || !is_array($policy)) {
                    continue;
                }
                $result[trim($type)] = $policy;
            }
            return $result;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<int|string,mixed> $roles
     * @return array<int,string>
     */
    private static function normalizeRoleList(array $roles): array
    {
        $normalized = [];
        foreach ($roles as $role) {
            $slug = trim((string)$role);
            if ($slug === '') {
                continue;
            }
            $normalized[$slug] = true;
        }
        return array_keys($normalized);
    }

    /**
     * @param array<string,mixed> $policy
     * @param array<int,string> $roles
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function attachMetadata(
        string $type,
        array $policy,
        array $roles,
        ?string $recipientUser,
        array $data
    ): array {
        $meta = [
            'type' => $type,
            'category' => self::normalizeCategory((string)($policy['category'] ?? 'system')),
            'severity' => self::normalizeSeverity((string)($policy['severity'] ?? 'info')),
            'target_roles' => $roles,
            'target_user' => $recipientUser,
        ];

        $existingMeta = [];
        if (isset($data['notification_meta']) && is_array($data['notification_meta'])) {
            $existingMeta = $data['notification_meta'];
        }
        $data['notification_meta'] = array_merge($meta, $existingMeta);
        return $data;
    }

    private static function normalizeCategory(string $category): string
    {
        $value = strtolower(trim($category));
        $allowed = ['workflow', 'governance', 'operations', 'security', 'data_quality', 'system'];
        return in_array($value, $allowed, true) ? $value : 'system';
    }

    private static function normalizeSeverity(string $severity): string
    {
        $value = strtolower(trim($severity));
        $allowed = ['info', 'success', 'warning', 'error'];
        return in_array($value, $allowed, true) ? $value : 'info';
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
