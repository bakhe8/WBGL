<?php

declare(strict_types=1);

use App\Support\Settings;
use App\Support\TestDataVisibility;
use PHPUnit\Framework\TestCase;

final class TestDataVisibilityTest extends TestCase
{
    private string $tmpPrimary;
    private string $tmpLocal;

    protected function setUp(): void
    {
        parent::setUp();
        $suffix = bin2hex(random_bytes(6));
        $this->tmpPrimary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "wbgl-settings-{$suffix}.json";
        $this->tmpLocal = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "wbgl-settings-local-{$suffix}.json";
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpPrimary);
        @unlink($this->tmpLocal);
        parent::tearDown();
    }

    public function testProductionAlwaysHidesTestData(): void
    {
        $settings = $this->makeSettings(true);
        $this->assertFalse(TestDataVisibility::includeTestData($settings, ['include_test_data' => '1']));
        $this->assertFalse(TestDataVisibility::includeTestData($settings, ['include_test_data' => 'true']));
    }

    public function testNonProductionDefaultsToShowUnlessExplicitlyHidden(): void
    {
        $settings = $this->makeSettings(false);
        $this->assertTrue(TestDataVisibility::includeTestData($settings, []));
        $this->assertFalse(TestDataVisibility::includeTestData($settings, ['include_test_data' => '0']));
        $this->assertFalse(TestDataVisibility::includeTestData($settings, ['include_test_data' => 'false']));
        $this->assertTrue(TestDataVisibility::includeTestData($settings, ['include_test_data' => '1']));
        $this->assertTrue(TestDataVisibility::includeTestData($settings, ['include_test_data' => 'true']));
    }

    public function testWithQueryFlagAlwaysAddsExplicitIncludeFlag(): void
    {
        $params = TestDataVisibility::withQueryFlag(['filter' => 'all'], true);
        $this->assertSame('1', (string)($params['include_test_data'] ?? ''));

        $params = TestDataVisibility::withQueryFlag(['filter' => 'all', 'include_test_data' => '1'], false);
        $this->assertSame('0', (string)($params['include_test_data'] ?? ''));
        $this->assertSame('all', (string)($params['filter'] ?? ''));
    }

    public function testIsTestLikeBatchDetectsIdentifierPatterns(): void
    {
        $this->assertTrue(TestDataVisibility::isTestLikeBatch('excel_20260219_035314_Copyof22026'));
        $this->assertTrue(TestDataVisibility::isTestLikeBatch('test_hist_20260214'));
        $this->assertTrue(TestDataVisibility::isTestLikeBatch('email_import_draft'));
        $this->assertTrue(TestDataVisibility::isTestLikeBatch('excel_20260228_041817_sim_import_10rows'));
    }

    public function testIsTestLikeBatchDetectsNameOrNotesPatterns(): void
    {
        $this->assertTrue(TestDataVisibility::isTestLikeBatch(
            'manual_paste_20260112',
            'دفعة اختبار (أرشيف)',
            ''
        ));
        $this->assertTrue(TestDataVisibility::isTestLikeBatch(
            'manual_paste_20260112',
            'دفعة إدخال',
            'Copy Of legacy training run'
        ));
    }

    public function testIsTestLikeBatchKeepsOperationalBatchAsNonTest(): void
    {
        $this->assertFalse(TestDataVisibility::isTestLikeBatch(
            'manual_paste_20260214',
            'دفعة إدخال يدوي/ذكي (2026/02/14)',
            ''
        ));
    }

    private function makeSettings(bool $production): Settings
    {
        file_put_contents($this->tmpPrimary, json_encode([
            'PRODUCTION_MODE' => $production,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        file_put_contents($this->tmpLocal, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return new Settings($this->tmpPrimary, $this->tmpLocal);
    }
}
