<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class I18nCoverageTest extends TestCase
{
    public function testCommonLocaleFilesHaveMatchingKeys(): void
    {
        $root = realpath(__DIR__ . '/../../../');
        $this->assertNotFalse($root);

        $ar = $this->loadJson($root . '/public/locales/ar/common.json');
        $en = $this->loadJson($root . '/public/locales/en/common.json');

        $arKeys = array_keys($ar);
        $enKeys = array_keys($en);
        sort($arKeys);
        sort($enKeys);

        $this->assertSame($arKeys, $enKeys, 'Mismatch between ar/en common locale keys');
    }

    public function testRenderedI18nAttributesExistInCommonLocaleNamespace(): void
    {
        $root = realpath(__DIR__ . '/../../../');
        $this->assertNotFalse($root);

        $commonAr = $this->loadJson($root . '/public/locales/ar/common.json');
        $commonEn = $this->loadJson($root . '/public/locales/en/common.json');
        $knownKeys = array_unique(array_merge(array_keys($commonAr), array_keys($commonEn)));

        $usedKeys = [];
        $targets = [
            $root . '/index.php',
            $root . '/partials/unified-header.php',
            $root . '/views/login.php',
        ];

        foreach ($targets as $path) {
            $this->assertFileExists($path);
            $content = (string)file_get_contents($path);
            preg_match_all('/data-i18n(?:-placeholder|-title|-content)?="([^"]+)"/', $content, $matches);
            foreach (($matches[1] ?? []) as $key) {
                $usedKeys[] = trim((string)$key);
            }
        }

        $usedKeys = array_values(array_unique(array_filter($usedKeys)));
        $missing = array_values(array_diff($usedKeys, $knownKeys));

        $this->assertSame([], $missing, 'Missing i18n keys in common locale files: ' . json_encode($missing));
    }

    /**
     * @return array<string,string>
     */
    private function loadJson(string $path): array
    {
        $this->assertFileExists($path);
        $decoded = json_decode((string)file_get_contents($path), true);
        $this->assertIsArray($decoded, 'Invalid JSON: ' . $path);
        return $decoded;
    }
}
