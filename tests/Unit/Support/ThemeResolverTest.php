<?php

declare(strict_types=1);

use App\Support\Settings;
use App\Support\ThemeResolver;
use PHPUnit\Framework\TestCase;

final class ThemeResolverTest extends TestCase
{
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->tempFiles = [];
    }

    public function testUserThemeOverridesOrganizationDefault(): void
    {
        $settings = $this->settingsWith(['DEFAULT_THEME' => 'light']);
        $resolved = ThemeResolver::resolve('dark', $settings);

        $this->assertSame('dark', $resolved['theme']);
        $this->assertSame('user_preference', $resolved['source']);
    }

    public function testFallsBackToOrganizationThemeWhenUserThemeMissing(): void
    {
        $settings = $this->settingsWith(['DEFAULT_THEME' => 'desert']);
        $resolved = ThemeResolver::resolve(null, $settings);

        $this->assertSame('desert', $resolved['theme']);
        $this->assertSame('org_default', $resolved['source']);
    }

    public function testUnknownThemeNormalizesToSystem(): void
    {
        $settings = $this->settingsWith(['DEFAULT_THEME' => 'unknown-theme']);
        $resolved = ThemeResolver::resolve(null, $settings);

        $this->assertSame('system', $resolved['theme']);
    }

    private function settingsWith(array $overrides): Settings
    {
        $file = tempnam(sys_get_temp_dir(), 'wbgl_settings_theme_');
        if ($file === false) {
            throw new \RuntimeException('Unable to create temp settings file');
        }
        $this->tempFiles[] = $file;
        file_put_contents($file, json_encode($overrides, JSON_UNESCAPED_UNICODE));
        return new Settings($file);
    }
}
