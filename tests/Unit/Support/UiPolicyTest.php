<?php

declare(strict_types=1);

use App\Support\UiPolicy;
use PHPUnit\Framework\TestCase;

final class UiPolicyTest extends TestCase
{
    public function testCanResolvesCapabilityToBackendPermission(): void
    {
        $allowed = UiPolicy::can(['manage_users'], 'navigation', 'view-users');
        $denied = UiPolicy::can(['manage_data'], 'navigation', 'view-users');

        $this->assertTrue($allowed);
        $this->assertFalse($denied);
    }

    public function testWildcardPermissionGrantsCapability(): void
    {
        $this->assertTrue(UiPolicy::can(['*'], 'users', 'manage'));
    }

    public function testPermissionWildcardForResourceIsSupported(): void
    {
        $this->assertTrue(UiPolicy::can(['reports:*'], 'reports', 'view'));
        $this->assertFalse(UiPolicy::can(['reports:view'], 'reports', 'edit'));
    }
}
