<?php

declare(strict_types=1);

use App\Services\SmartPaste\ParseResponseConfidenceGuard;
use PHPUnit\Framework\TestCase;

final class ParseResponseConfidenceGuardTest extends TestCase
{
    public function testStrengthenAddsConfidenceWhenMissing(): void
    {
        $result = [
            'success' => true,
            'extracted' => [
                'supplier' => 'الشركة التعاونية للتأمين',
                'bank' => 'البنك الأهلي السعودي',
                'amount' => 896503.00,
                'expiry_date' => '2027-02-03',
                'issue_date' => '2026-01-05',
            ],
        ];

        $strengthened = ParseResponseConfidenceGuard::strengthen(
            $result,
            'ضمان لصالح الشركة التعاونية للتأمين لدى البنك الأهلي السعودي'
        );

        $this->assertArrayHasKey('confidence', $strengthened);
        $this->assertArrayHasKey('overall_confidence', $strengthened);
        $this->assertIsArray($strengthened['confidence']);
        $this->assertGreaterThan(0, (int)$strengthened['overall_confidence']);
        $this->assertArrayHasKey('supplier', $strengthened['confidence']);
        $this->assertArrayHasKey('bank', $strengthened['confidence']);
    }

    public function testStrengthenDoesNotOverrideExistingConfidence(): void
    {
        $result = [
            'success' => true,
            'extracted' => [
                'supplier' => 'ABC',
            ],
            'confidence' => [
                'supplier' => [
                    'confidence' => 42,
                    'reason' => 'custom',
                    'accept' => false,
                ],
            ],
            'overall_confidence' => 42,
        ];

        $strengthened = ParseResponseConfidenceGuard::strengthen($result, 'ABC');

        $this->assertSame(42, (int)$strengthened['overall_confidence']);
        $this->assertSame(42, (int)$strengthened['confidence']['supplier']['confidence']);
        $this->assertSame('custom', (string)$strengthened['confidence']['supplier']['reason']);
    }
}
