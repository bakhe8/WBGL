<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class UxA11yWiringTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = realpath(__DIR__ . '/../../') ?: dirname(__DIR__, 2);
    }

    public function testA11yStylesheetHasCoreUtilities(): void
    {
        $css = $this->readFile('public/css/a11y.css');

        $this->assertStringContainsString(':focus-visible', $css);
        $this->assertStringContainsString('.sr-only', $css);
        $this->assertStringContainsString('prefers-reduced-motion', $css);
    }

    public function testCoreViewsLinkA11yStylesheet(): void
    {
        $index = $this->readFile('index.php');
        $settings = $this->readFile('views/settings.php');
        $batchDetail = $this->readFile('views/batch-detail.php');

        $this->assertStringContainsString('public/css/a11y.css', $index);
        $this->assertStringContainsString('../public/css/a11y.css', $settings);
        $this->assertStringContainsString('../public/css/a11y.css', $batchDetail);
    }

    public function testSettingsViewHasA11yTabsAndModalKeyboardHandling(): void
    {
        $settings = $this->readFile('views/settings.php');

        $this->assertStringContainsString('role="tablist"', $settings);
        $this->assertStringContainsString('role="tab"', $settings);
        $this->assertStringContainsString('role="tabpanel"', $settings);
        $this->assertStringContainsString("if (event.key === 'Escape')", $settings);
        $this->assertStringContainsString("event.key !== 'Tab'", $settings);
    }

    public function testBatchDetailHasA11yModalAndIconLabels(): void
    {
        $batchDetail = $this->readFile('views/batch-detail.php');

        $this->assertStringContainsString('role="dialog"', $batchDetail);
        $this->assertStringContainsString('aria-modal="true"', $batchDetail);
        $this->assertStringContainsString('Modal.bindA11y', $batchDetail);
        $this->assertStringContainsString('aria-label="تعديل اسم وملاحظات الدفعة"', $batchDetail);
        $this->assertStringContainsString('aria-label="إغلاق الدفعة"', $batchDetail);
    }

    private function readFile(string $relativePath): string
    {
        $path = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $this->assertFileExists($path, 'Missing required file: ' . $relativePath);
        return (string)file_get_contents($path);
    }
}
