<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Support\AuthService;
use ReflectionClass;

final class RuntimeHarness
{
    /**
     * @param string[] $permissions
     */
    public static function forceAuthAndPermissions(array $permissions): void
    {
        AuthService::forceAuthenticatedUser(new User(
            id: 9999,
            username: 'phpunit',
            passwordHash: '',
            fullName: 'PHPUnit User',
            roleId: 1
        ));

        $guardReflection = new ReflectionClass(\App\Support\Guard::class);

        $permissionsProperty = $guardReflection->getProperty('permissions');
        $permissionsProperty->setAccessible(true);
        $permissionsProperty->setValue(null, array_values(array_unique($permissions)));

        $overridesProperty = $guardReflection->getProperty('userOverrides');
        $overridesProperty->setAccessible(true);
        $overridesProperty->setValue(null, ['allow' => [], 'deny' => []]);

        $knownPermissionsProperty = $guardReflection->getProperty('knownPermissionSlugs');
        $knownPermissionsProperty->setAccessible(true);
        $knownPermissionsProperty->setValue(null, []);
    }
}

