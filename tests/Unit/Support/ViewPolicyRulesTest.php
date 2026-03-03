<?php

declare(strict_types=1);

use App\Support\ViewPolicy;
use PHPUnit\Framework\TestCase;

final class ViewPolicyRulesTest extends TestCase
{
    public function testConfidenceDemoIsDeveloperOnlyView(): void
    {
        $this->assertTrue(ViewPolicy::isDeveloperOnlyView('confidence-demo.php'));
        $this->assertTrue(ViewPolicy::isDeveloperOnlyView('/views/confidence-demo.php'));
    }

    public function testManageUsersViewsStillRequireManageUsersPermission(): void
    {
        $this->assertSame('manage_users', ViewPolicy::requiredPermissionForView('users.php'));
        $this->assertSame('manage_users', ViewPolicy::requiredPermissionForView('settings.php'));
        $this->assertSame('manage_users', ViewPolicy::requiredPermissionForView('maintenance.php'));
    }
}

