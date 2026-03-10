<?php
declare(strict_types=1);

namespace App\Support;

class UiPolicy
{
    /**
     * UI capability -> backend permission slug.
     *
     * @var array<string, string>
     */
    private const CAPABILITY_MAP = [
        'navigation:view-settings' => 'manage_users',
        'navigation:view-maintenance' => 'manage_users',
        'navigation:view-state-inspector' => 'manage_users',
        'navigation:view-role-simulator' => 'manage_users',
        'navigation:view-users' => 'manage_users',
        'users:manage' => 'manage_users',
        'roles:manage' => 'manage_roles',
        'settings:manage' => 'manage_users',
        'imports:create' => 'import_excel',
        'guarantee:manual-entry' => 'manual_entry',
        'guarantee:mutate' => 'manage_data',
        'guarantee:save' => 'guarantee_save',
        'guarantee:extend' => 'guarantee_extend',
        'guarantee:reduce' => 'guarantee_reduce',
        'guarantee:release' => 'guarantee_release',
        'guarantee:print' => 'letters_print',
        'batch:print' => 'letters_print',
        'supplier:manage' => 'supplier_manage',
        'bank:manage' => 'bank_manage',
        'batch:reopen' => 'reopen_batch',
        'guarantee:reopen' => 'reopen_guarantee',
        'system:break-glass' => 'break_glass_override',
        'ui:change-language' => 'ui_change_language',
        'ui:change-direction' => 'ui_change_direction',
        'ui:change-theme' => 'ui_change_theme',
        'timeline:view' => 'timeline_view',
        'notes:view' => 'notes_view',
        'notes:create' => 'notes_create',
        'attachments:view' => 'attachments_view',
        'attachments:upload' => 'attachments_upload',
    ];

    /**
     * @param string[] $permissions
     */
    public static function can(array $permissions, string $resource, string $action, ?string $overridePermission = null): bool
    {
        $permissions = array_values(array_unique(array_filter(array_map('trim', $permissions))));
        if (in_array('*', $permissions, true)) {
            return true;
        }

        $capability = strtolower(trim($resource . ':' . $action));
        $required = $overridePermission ?: (self::CAPABILITY_MAP[$capability] ?? $capability);

        return self::hasPermission($permissions, $required);
    }

    /**
     * @return array<string, string>
     */
    public static function capabilityMap(): array
    {
        return self::CAPABILITY_MAP;
    }

    /**
     * @param string[] $permissions
     */
    private static function hasPermission(array $permissions, string $required): bool
    {
        if (in_array($required, $permissions, true)) {
            return true;
        }

        if (str_contains($required, ':')) {
            [$resource] = explode(':', $required, 2);
            if (in_array($resource . ':*', $permissions, true)) {
                return true;
            }
        }

        if (in_array($required . ':*', $permissions, true)) {
            return true;
        }

        return false;
    }
}
