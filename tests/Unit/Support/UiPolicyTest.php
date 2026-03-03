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

    public function testRolesCapabilityRequiresManageRolesPermission(): void
    {
        $allowed = UiPolicy::can(['manage_roles'], 'roles', 'manage');
        $denied = UiPolicy::can(['manage_users'], 'roles', 'manage');

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

    public function testGuaranteeActionsAreSplitByDedicatedPermissions(): void
    {
        $this->assertTrue(UiPolicy::can(['guarantee_save'], 'guarantee', 'save'));
        $this->assertFalse(UiPolicy::can(['guarantee_extend'], 'guarantee', 'save'));

        $this->assertTrue(UiPolicy::can(['guarantee_extend'], 'guarantee', 'extend'));
        $this->assertFalse(UiPolicy::can(['guarantee_save'], 'guarantee', 'extend'));

        $this->assertTrue(UiPolicy::can(['guarantee_reduce'], 'guarantee', 'reduce'));
        $this->assertFalse(UiPolicy::can(['guarantee_release'], 'guarantee', 'reduce'));

        $this->assertTrue(UiPolicy::can(['guarantee_release'], 'guarantee', 'release'));
        $this->assertFalse(UiPolicy::can(['guarantee_reduce'], 'guarantee', 'release'));

        $this->assertTrue(UiPolicy::can(['reopen_guarantee'], 'guarantee', 'reopen'));
        $this->assertFalse(UiPolicy::can(['guarantee_release'], 'guarantee', 'reopen'));
    }

    public function testReferenceEntityManageCapabilitiesMapToDedicatedPermissions(): void
    {
        $this->assertTrue(UiPolicy::can(['supplier_manage'], 'supplier', 'manage'));
        $this->assertFalse(UiPolicy::can(['bank_manage'], 'supplier', 'manage'));

        $this->assertTrue(UiPolicy::can(['bank_manage'], 'bank', 'manage'));
        $this->assertFalse(UiPolicy::can(['supplier_manage'], 'bank', 'manage'));
    }
}
