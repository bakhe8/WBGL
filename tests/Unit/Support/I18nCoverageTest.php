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

    public function testRenderedI18nAttributesExistInLocaleCatalogs(): void
    {
        $root = realpath(__DIR__ . '/../../../');
        $this->assertNotFalse($root);

        $knownKeys = $this->loadAllLocaleKeys((string)$root);

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
                $normalizedKey = trim((string)$key);
                if ($normalizedKey === '' || $this->isDynamicI18nExpression($normalizedKey)) {
                    continue;
                }
                $usedKeys[] = $normalizedKey;
            }
        }

        $usedKeys = array_values(array_unique(array_filter($usedKeys)));
        $missing = array_values(array_diff($usedKeys, $knownKeys));

        $this->assertSame([], $missing, 'Missing i18n keys in locale catalogs: ' . json_encode($missing));
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

    /**
     * @return list<string>
     */
    private function loadAllLocaleKeys(string $root): array
    {
        $keys = [];
        foreach (['ar', 'en'] as $locale) {
            $files = glob($root . '/public/locales/' . $locale . '/*.json') ?: [];
            foreach ($files as $file) {
                $decoded = json_decode((string)file_get_contents($file), true);
                if (!is_array($decoded)) {
                    continue;
                }
                $keys = array_merge($keys, array_keys($decoded));
            }
        }

        $keys = array_values(array_unique(array_filter(array_map('strval', $keys))));
        sort($keys);
        return $keys;
    }

    private function isDynamicI18nExpression(string $key): bool
    {
        if (str_contains($key, '<?') || str_contains($key, '?>')) {
            return true;
        }
        if (str_contains($key, '$') || str_contains($key, 'htmlspecialchars(')) {
            return true;
        }
        if (preg_match('/<\?=/', $key) === 1) {
            return true;
        }
        return false;
    }
}
