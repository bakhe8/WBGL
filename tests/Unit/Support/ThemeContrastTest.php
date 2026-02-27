<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ThemeContrastTest extends TestCase
{
    public function testThemeFilesExposeExpectedTokenBlocks(): void
    {
        $root = realpath(__DIR__ . '/../../../');
        $this->assertNotFalse($root);

        $themesCss = (string)file_get_contents($root . '/public/css/themes.css');
        $this->assertStringContainsString(":root[data-theme='dark']", $themesCss);
        $this->assertStringContainsString(":root[data-theme='desert']", $themesCss);
        $this->assertStringContainsString('--text-primary', $themesCss);
        $this->assertStringContainsString('--bg-body', $themesCss);
    }

    public function testTextContrastMeetsMinimumRatioForCoreThemes(): void
    {
        $root = realpath(__DIR__ . '/../../../');
        $this->assertNotFalse($root);

        $designSystem = (string)file_get_contents($root . '/public/css/design-system.css');
        $themesCss = (string)file_get_contents($root . '/public/css/themes.css');

        $lightText = $this->extractVarFromBlock($designSystem, ':root', '--text-primary');
        $lightBg = $this->extractVarFromBlock($designSystem, ':root', '--bg-body');
        $darkText = $this->extractVarFromBlock($themesCss, ":root[data-theme='dark']", '--text-primary');
        $darkBg = $this->extractVarFromBlock($themesCss, ":root[data-theme='dark']", '--bg-body');
        $desertText = $this->extractVarFromBlock($themesCss, ":root[data-theme='desert']", '--text-primary');
        $desertBg = $this->extractVarFromBlock($themesCss, ":root[data-theme='desert']", '--bg-body');

        $this->assertGreaterThanOrEqual(4.5, $this->contrastRatio($lightText, $lightBg), 'Light theme contrast failed');
        $this->assertGreaterThanOrEqual(4.5, $this->contrastRatio($darkText, $darkBg), 'Dark theme contrast failed');
        $this->assertGreaterThanOrEqual(4.5, $this->contrastRatio($desertText, $desertBg), 'Desert theme contrast failed');
    }

    private function extractVarFromBlock(string $css, string $selector, string $varName): string
    {
        $selectorPattern = preg_quote($selector, '/');
        $pattern = '/' . $selectorPattern . '\s*\{([^}]*)\}/s';
        if (!preg_match($pattern, $css, $blockMatch)) {
            $this->fail('CSS selector block not found: ' . $selector);
        }
        $block = $blockMatch[1];

        $varPattern = '/' . preg_quote($varName, '/') . '\s*:\s*(#[0-9a-fA-F]{6})\s*;/';
        if (!preg_match($varPattern, $block, $valueMatch)) {
            $this->fail('CSS variable not found in block: ' . $varName . ' @ ' . $selector);
        }
        return strtolower($valueMatch[1]);
    }

    private function contrastRatio(string $foregroundHex, string $backgroundHex): float
    {
        $l1 = $this->relativeLuminance($foregroundHex);
        $l2 = $this->relativeLuminance($backgroundHex);
        $lighter = max($l1, $l2);
        $darker = min($l1, $l2);
        return ($lighter + 0.05) / ($darker + 0.05);
    }

    private function relativeLuminance(string $hex): float
    {
        [$r, $g, $b] = $this->hexToRgb($hex);
        $rgb = [$r / 255, $g / 255, $b / 255];
        $linear = array_map(static function (float $channel): float {
            return $channel <= 0.03928
                ? $channel / 12.92
                : ((($channel + 0.055) / 1.055) ** 2.4);
        }, $rgb);

        return (0.2126 * $linear[0]) + (0.7152 * $linear[1]) + (0.0722 * $linear[2]);
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private function hexToRgb(string $hex): array
    {
        $clean = ltrim($hex, '#');
        return [
            hexdec(substr($clean, 0, 2)),
            hexdec(substr($clean, 2, 2)),
            hexdec(substr($clean, 4, 2)),
        ];
    }
}
