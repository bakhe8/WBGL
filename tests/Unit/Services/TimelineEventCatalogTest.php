<?php

declare(strict_types=1);

use App\Services\TimelineEventCatalog;
use PHPUnit\Framework\TestCase;

final class TimelineEventCatalogTest extends TestCase
{
    public function testReturnsWorkflowSpecificLabel(): void
    {
        $event = [
            'event_type' => 'status_change',
            'event_subtype' => 'workflow_advance',
            'event_details' => json_encode([
                'changes' => [
                    [
                        'field' => 'workflow_step',
                        'old_value' => 'draft',
                        'new_value' => 'audited',
                        'trigger' => 'workflow_advance',
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE),
        ];

        $this->assertSame('تم التدقيق', TimelineEventCatalog::getEventDisplayLabel($event));
        $this->assertSame('🔍', TimelineEventCatalog::getEventIcon($event));
    }

    public function testBankOnlyChangeIsAutoMatch(): void
    {
        $event = [
            'event_type' => 'modified',
            'event_subtype' => 'manual_edit',
            'event_details' => json_encode([
                'changes' => [
                    [
                        'field' => 'bank_id',
                        'old_value' => ['id' => 1, 'name' => 'Bank A'],
                        'new_value' => ['id' => 2, 'name' => 'Bank B'],
                        'trigger' => 'manual',
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE),
        ];

        $this->assertSame('تطابق تلقائي', TimelineEventCatalog::getEventDisplayLabel($event));
    }
}

