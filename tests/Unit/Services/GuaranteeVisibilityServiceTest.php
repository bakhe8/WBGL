<?php

declare(strict_types=1);

use App\Services\GuaranteeVisibilityService;
use PHPUnit\Framework\TestCase;

final class GuaranteeVisibilityServiceTest extends TestCase
{
    protected function setUp(): void
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            $_SESSION = [];
        }
        unset($_SESSION['user_id'], $_SESSION['username']);
    }

    public function testBuildSqlFilterDeniesWhenNoUserSession(): void
    {
        $filter = GuaranteeVisibilityService::buildSqlFilter('g', 'd');
        $this->assertStringContainsString('1=0', $filter['sql']);
    }
}
