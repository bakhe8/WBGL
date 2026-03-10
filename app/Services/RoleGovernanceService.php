<?php

declare(strict_types=1);

namespace App\Services;

/**
 * RoleGovernanceService
 *
 * Protects core operational roles from destructive governance operations.
 */
final class RoleGovernanceService
{
    /**
     * Core workflow roles that must not be deleted.
     *
     * @var array<int,string>
     */
    private const PROTECTED_ROLE_SLUGS = [
        'developer',
        'data_entry',
        'data_auditor',
        'analyst',
        'supervisor',
        'approver',
        'signatory',
    ];

    /**
     * @return array<int,string>
     */
    public static function protectedRoleSlugs(): array
    {
        return self::PROTECTED_ROLE_SLUGS;
    }

    public static function isProtectedRoleSlug(string $slug): bool
    {
        $normalized = strtolower(trim($slug));
        if ($normalized === '') {
            return false;
        }
        return in_array($normalized, self::PROTECTED_ROLE_SLUGS, true);
    }

    public static function deleteBlockedReason(string $slug): ?string
    {
        if (!self::isProtectedRoleSlug($slug)) {
            return null;
        }

        return 'لا يمكن حذف دور نظام أساسي لأنه مرتبط بمسار التشغيل والاعتماد والخطابات.';
    }
}

