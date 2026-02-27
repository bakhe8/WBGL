<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\LocaleResolver;
use App\Support\Settings;
use PHPUnit\Framework\TestCase;

final class LocaleResolverTest extends TestCase
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

    public function testUserPreferenceHasHighestPriority(): void
    {
        $settings = $this->settingsWith([
            'DEFAULT_LOCALE' => 'en',
        ]);

        $user = $this->userWithLocale('ar');
        $resolved = LocaleResolver::resolve($user, $settings, 'en-US,en;q=0.9');

        $this->assertSame('ar', $resolved['locale']);
        $this->assertSame('user_preference', $resolved['source']);
    }

    public function testOrgDefaultIsUsedWhenUserPreferenceMissing(): void
    {
        $settings = $this->settingsWith([
            'DEFAULT_LOCALE' => 'en',
        ]);

        $user = $this->userWithLocale('');
        $resolved = LocaleResolver::resolve($user, $settings, 'ar-SA,ar;q=0.9');

        $this->assertSame('en', $resolved['locale']);
        $this->assertSame('org_default', $resolved['source']);
    }

    public function testBrowserLocaleIsUsedWhenNoUserAndNoOrgDefault(): void
    {
        $settings = $this->settingsWith([
            'DEFAULT_LOCALE' => '',
        ]);

        $resolved = LocaleResolver::resolve(null, $settings, 'en-US,en;q=0.9');

        $this->assertSame('en', $resolved['locale']);
        $this->assertSame('browser', $resolved['source']);
    }

    public function testFallbackLocaleIsArabicWhenNothingMatches(): void
    {
        $settings = $this->settingsWith([
            'DEFAULT_LOCALE' => '',
        ]);

        $resolved = LocaleResolver::resolve(null, $settings, 'fr-FR,fr;q=0.9');

        $this->assertSame('ar', $resolved['locale']);
        $this->assertSame('fallback', $resolved['source']);
    }

    private function settingsWith(array $overrides): Settings
    {
        $file = tempnam(sys_get_temp_dir(), 'wbgl_settings_');
        if ($file === false) {
            throw new \RuntimeException('Unable to create temp settings file');
        }
        $this->tempFiles[] = $file;
        file_put_contents($file, json_encode($overrides, JSON_UNESCAPED_UNICODE));
        return new Settings($file);
    }

    private function userWithLocale(string $locale): User
    {
        return new User(
            id: 1,
            username: 'locale_test',
            passwordHash: '',
            fullName: 'Locale Test',
            email: null,
            roleId: null,
            preferredLanguage: $locale,
            preferredTheme: 'system',
            preferredDirection: 'auto',
            lastLogin: null,
            createdAt: date('Y-m-d H:i:s')
        );
    }
}
