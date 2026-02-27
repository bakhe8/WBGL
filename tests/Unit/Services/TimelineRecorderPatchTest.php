<?php

declare(strict_types=1);

use App\Services\TimelineRecorder;
use PHPUnit\Framework\TestCase;

final class TimelineRecorderPatchTest extends TestCase
{
    public function testCreatePatchDetectsAddReplaceAndRemoveOperations(): void
    {
        $old = [
            'amount' => 1000,
            'status' => 'pending',
            'bank_name' => 'Bank A',
        ];

        $new = [
            'amount' => 1500,      // replace
            'status' => 'ready',   // replace
            'supplier_name' => 'Supplier X', // add
            // bank_name removed
        ];

        $patch = TimelineRecorder::createPatch($old, $new);

        $this->assertPatchContains($patch, [
            'op' => 'replace',
            'path' => '/amount',
            'value' => 1500,
        ]);

        $this->assertPatchContains($patch, [
            'op' => 'replace',
            'path' => '/status',
            'value' => 'ready',
        ]);

        $this->assertPatchContains($patch, [
            'op' => 'add',
            'path' => '/supplier_name',
            'value' => 'Supplier X',
        ]);

        $this->assertPatchContains($patch, [
            'op' => 'remove',
            'path' => '/bank_name',
        ]);
    }

    private function assertPatchContains(array $patch, array $expected): void
    {
        foreach ($patch as $entry) {
            $allMatched = true;
            foreach ($expected as $k => $v) {
                if (!array_key_exists($k, $entry) || $entry[$k] !== $v) {
                    $allMatched = false;
                    break;
                }
            }
            if ($allMatched) {
                $this->assertTrue(true);
                return;
            }
        }

        $this->fail('Expected patch fragment not found: ' . json_encode($expected, JSON_UNESCAPED_UNICODE));
    }
}
