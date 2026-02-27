<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/_bootstrap.php';

final class ApiBootstrapTest extends TestCase
{
    public function testBootstrapHelpersAreDefined(): void
    {
        $this->assertTrue(function_exists('wbgl_api_json_headers'));
        $this->assertTrue(function_exists('wbgl_api_fail'));
        $this->assertTrue(function_exists('wbgl_api_request_id'));
        $this->assertTrue(function_exists('wbgl_api_require_login'));
        $this->assertTrue(function_exists('wbgl_api_require_permission'));
        $this->assertTrue(function_exists('wbgl_api_require_csrf'));
        $this->assertTrue(function_exists('wbgl_api_current_user_display'));
    }

    public function testCurrentUserDisplayFallsBackToSystemWhenNoSessionUser(): void
    {
        $this->assertSame('النظام', wbgl_api_current_user_display());
    }
}
