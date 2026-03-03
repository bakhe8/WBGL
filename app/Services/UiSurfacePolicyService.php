<?php

declare(strict_types=1);

namespace App\Services;

/**
 * UiSurfacePolicyService
 *
 * Converts canonical policy decision (visible/actionable/executable)
 * + effective permissions into server-side UI surface grants.
 */
final class UiSurfacePolicyService
{
    /**
     * @param array{visible:bool,actionable:bool,executable:bool,reasons?:array<int,string>} $policy
     * @param string[] $permissions
     * @return array{
     *   can_view_record:bool,
     *   can_view_identity:bool,
     *   can_view_timeline:bool,
     *   can_view_notes:bool,
     *   can_create_notes:bool,
     *   can_view_attachments:bool,
     *   can_upload_attachments:bool,
     *   can_execute_actions:bool,
     *   can_view_preview:bool
     * }
     */
    public static function forGuarantee(array $policy, array $permissions, ?string $recordStatus = null): array
    {
        $normalizedPermissions = self::normalizePermissions($permissions);
        $isVisible = (bool)($policy['visible'] ?? false);
        $canViewRecord = $isVisible;
        $canExecuteActions = $isVisible && (bool)($policy['executable'] ?? false);

        $status = strtolower(trim((string)$recordStatus));
        $canViewPreview = $canViewRecord && in_array(
            $status,
            ['ready', 'approved', 'issued', 'released', 'signed'],
            true
        );

        return [
            'can_view_record' => $canViewRecord,
            'can_view_identity' => $canViewRecord,
            'can_view_timeline' => $canViewRecord && self::has($normalizedPermissions, 'timeline_view'),
            'can_view_notes' => $canViewRecord && self::has($normalizedPermissions, 'notes_view'),
            'can_create_notes' => $canExecuteActions && self::has($normalizedPermissions, 'notes_create'),
            'can_view_attachments' => $canViewRecord && self::has($normalizedPermissions, 'attachments_view'),
            'can_upload_attachments' => $canExecuteActions && self::has($normalizedPermissions, 'attachments_upload'),
            'can_execute_actions' => $canExecuteActions,
            'can_view_preview' => $canViewPreview,
        ];
    }

    /**
     * @param string[] $permissions
     * @return string[]
     */
    private static function normalizePermissions(array $permissions): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn($permission): string => trim((string)$permission),
            $permissions
        ), static fn(string $permission): bool => $permission !== '')));
    }

    /**
     * @param string[] $permissions
     */
    private static function has(array $permissions, string $permission): bool
    {
        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }
}
