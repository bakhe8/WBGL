<?php

declare(strict_types=1);

use App\Support\StabilityFreezeGate;
use PHPUnit\Framework\TestCase;

final class StabilityFreezeGateTest extends TestCase
{
    public function testGateIsSkippedOutsidePhaseOne(): void
    {
        $gate = StabilityFreezeGate::fromManifest($this->sampleManifest());
        $result = $gate->evaluate(
            ['api/get-record.php'],
            ["M\tapi/get-record.php"],
            '',
            'P2-01'
        );

        $this->assertFalse($result['enforced']);
        $this->assertSame([], $result['errors']);
    }

    public function testSensitiveChangesRequireStabilityRefsDuringPhaseOne(): void
    {
        $gate = StabilityFreezeGate::fromManifest($this->sampleManifest());
        $result = $gate->evaluate(
            ['api/get-record.php'],
            ["M\tapi/get-record.php"],
            "PLAN-REF: WBGL_MASTER_UPGRADE_PLAN\nCHANGE-TYPE: bugfix",
            'P1-01'
        );

        $this->assertTrue($result['enforced']);
        $this->assertNotSame([], $result['errors']);
        $this->assertStringContainsString('Missing STABILITY-REFS', implode(' ', $result['errors']));
    }

    public function testValidPhaseOneRefsPassForSensitiveChanges(): void
    {
        $gate = StabilityFreezeGate::fromManifest($this->sampleManifest());
        $result = $gate->evaluate(
            ['api/get-record.php', 'app/Services/RecordService.php'],
            [
                "M\tapi/get-record.php",
                "M\tapp/Services/RecordService.php",
            ],
            "CHANGE-TYPE: bugfix\nSTABILITY-REFS: P1-02, FR-03, R15\n",
            'P1-02'
        );

        $this->assertTrue($result['enforced']);
        $this->assertSame([], $result['errors']);
    }

    public function testUnknownStabilityRefsAreRejected(): void
    {
        $gate = StabilityFreezeGate::fromManifest($this->sampleManifest());
        $result = $gate->evaluate(
            ['app/Services/RecordService.php'],
            ["M\tapp/Services/RecordService.php"],
            "CHANGE-TYPE: bugfix\nSTABILITY-REFS: P1-01, FR-03, FR-99",
            'P1-01'
        );

        $this->assertTrue($result['enforced']);
        $this->assertNotSame([], $result['errors']);
        $this->assertStringContainsString('unknown IDs', implode(' ', $result['errors']));
    }

    public function testAddingNewFeatureSurfaceFileIsBlockedDuringPhaseOne(): void
    {
        $gate = StabilityFreezeGate::fromManifest($this->sampleManifest());
        $result = $gate->evaluate(
            ['api/new-feature.php'],
            ["A\tapi/new-feature.php"],
            "CHANGE-TYPE: bugfix\nSTABILITY-REFS: P1-01, FR-25",
            'P1-01'
        );

        $this->assertTrue($result['enforced']);
        $this->assertNotSame([], $result['errors']);
        $this->assertStringContainsString('Feature freeze violation', implode(' ', $result['errors']));
    }

    public function testDocsOnlyChangesDoNotRequireRefs(): void
    {
        $gate = StabilityFreezeGate::fromManifest($this->sampleManifest());
        $result = $gate->evaluate(
            ['Docs/INDEX-DOCS-AR.md'],
            ["M\tDocs/INDEX-DOCS-AR.md"],
            '',
            'P1-01'
        );

        $this->assertTrue($result['enforced']);
        $this->assertFalse($result['sensitive_changes']);
        $this->assertSame([], $result['errors']);
    }

    public function testSensitiveChangesRequireChangeTypeDuringPhaseOne(): void
    {
        $gate = StabilityFreezeGate::fromManifest($this->sampleManifest());
        $result = $gate->evaluate(
            ['api/get-record.php'],
            ["M\tapi/get-record.php"],
            'STABILITY-REFS: P1-01, FR-25',
            'P1-01'
        );

        $this->assertTrue($result['enforced']);
        $this->assertNotSame([], $result['errors']);
        $this->assertStringContainsString('Missing CHANGE-TYPE', implode(' ', $result['errors']));
    }

    public function testFeatureChangeTypeIsRejectedDuringPhaseOne(): void
    {
        $gate = StabilityFreezeGate::fromManifest($this->sampleManifest());
        $result = $gate->evaluate(
            ['app/Services/RecordService.php'],
            ["M\tapp/Services/RecordService.php"],
            "CHANGE-TYPE: feature\nSTABILITY-REFS: P1-01, FR-25",
            'P1-01'
        );

        $this->assertTrue($result['enforced']);
        $this->assertNotSame([], $result['errors']);
        $this->assertStringContainsString('CHANGE-TYPE cannot be feature/enhancement', implode(' ', $result['errors']));
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleManifest(): array
    {
        return [
            'phases' => [
                [
                    'id' => 'PHASE-1-STABILITY',
                    'steps' => [
                        [
                            'id' => 'P1-01',
                            'coverage_ids' => ['R03', 'G03', 'FR-25'],
                        ],
                        [
                            'id' => 'P1-02',
                            'coverage_ids' => ['FR-03', 'R15'],
                        ],
                    ],
                ],
                [
                    'id' => 'PHASE-2-CLARITY',
                    'steps' => [
                        [
                            'id' => 'P2-01',
                            'coverage_ids' => ['FR-12'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
