<?php

declare(strict_types=1);

namespace Tests;

use App\Services\NavigationService;
use PHPUnit\Framework\TestCase;
use Tests\Support\RuntimeHarness;

final class NavigationServiceFiltersTest extends TestCase
{
    protected function setUp(): void
    {
        RuntimeHarness::forceAuthAndPermissions(['ui_full_filters_view']);
    }

    public function testReadyFilterTargetsDataEntryQueueOnly(): void
    {
        $result = NavigationService::buildFilterConditions('ready', null, null, false);

        $this->assertStringContainsString("d.status = 'ready'", $result['sql']);
        $this->assertStringContainsString("d.workflow_step = 'draft'", $result['sql']);
        $this->assertStringContainsString("(d.active_action IS NULL OR d.active_action = '')", $result['sql']);
    }

    public function testReleasedFilterSelectsLockedRecordsOnly(): void
    {
        RuntimeHarness::forceAuthAndPermissions(['ui_full_filters_view', 'manage_data']);
        $result = NavigationService::buildFilterConditions('released', null, null, false);

        $this->assertStringContainsString("d.is_locked = TRUE", $result['sql']);
        $this->assertStringNotContainsString("d.status = 'ready'", $result['sql']);
    }

    public function testPrintReadyFilterRequiresManageDataPermission(): void
    {
        RuntimeHarness::forceAuthAndPermissions(['ui_full_filters_view']);
        $denied = NavigationService::buildFilterConditions('print_ready', null, null, false);
        $this->assertSame(' AND 1=0', $denied['sql']);

        RuntimeHarness::forceAuthAndPermissions(['ui_full_filters_view', 'manage_data']);
        $allowed = NavigationService::buildFilterConditions('print_ready', null, null, false);
        $this->assertStringContainsString("d.workflow_step = 'signed'", $allowed['sql']);
        $this->assertStringContainsString("(d.active_action IS NOT NULL AND d.active_action <> '')", $allowed['sql']);
    }

    public function testSearchModeAddsSearchPredicate(): void
    {
        RuntimeHarness::forceAuthAndPermissions(['ui_full_filters_view', 'manage_data']);
        $result = NavigationService::buildFilterConditions('all', 'JLG6605193', null, false);

        $this->assertStringContainsString('g.guarantee_number LIKE :search_any', $result['sql']);
        $this->assertStringContainsString('g.raw_data::text LIKE :search_any', $result['sql']);
        $this->assertStringContainsString('s.official_name LIKE :search_any', $result['sql']);
        $this->assertSame('%JLG6605193%', $result['params']['search_any'] ?? null);
    }
}

