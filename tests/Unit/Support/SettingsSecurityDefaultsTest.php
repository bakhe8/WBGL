<?php
declare(strict_types=1);

use App\Support\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsSecurityDefaultsTest extends TestCase
{
    private string $tempDir = '';

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/wbgl_settings_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $primary = $this->tempDir . '/settings.json';
        $local = $this->tempDir . '/settings.local.json';
        if (is_file($primary)) {
            @unlink($primary);
        }
        if (is_file($local)) {
            @unlink($local);
        }
        if (is_dir($this->tempDir)) {
            @rmdir($this->tempDir);
        }
    }

    public function testDefaultDbSslModeIsRequireWhenSettingsFilesAreEmpty(): void
    {
        $settings = new Settings(
            $this->tempDir . '/settings.json',
            $this->tempDir . '/settings.local.json'
        );

        $this->assertSame('require', (string)$settings->get('DB_SSLMODE'));
    }

    public function testPrimaryFileCanStillExplicitlyOverrideDbSslMode(): void
    {
        $primaryPath = $this->tempDir . '/settings.json';
        file_put_contents($primaryPath, json_encode(['DB_SSLMODE' => 'prefer'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $settings = new Settings(
            $primaryPath,
            $this->tempDir . '/settings.local.json'
        );

        $this->assertSame('prefer', (string)$settings->get('DB_SSLMODE'));
    }

    public function testApiTokenRateLimitDefaultsExist(): void
    {
        $settings = new Settings(
            $this->tempDir . '/settings.json',
            $this->tempDir . '/settings.local.json'
        );

        $this->assertSame(12, (int)$settings->get('API_TOKEN_RATE_LIMIT_MAX_ATTEMPTS'));
        $this->assertSame(60, (int)$settings->get('API_TOKEN_RATE_LIMIT_WINDOW_SECONDS'));
        $this->assertSame(120, (int)$settings->get('API_TOKEN_RATE_LIMIT_LOCKOUT_SECONDS'));
        $this->assertSame([], $settings->get('API_TOKEN_RATE_LIMIT_ALLOWLIST_IPS'));
        $this->assertSame([], $settings->get('API_TOKEN_RATE_LIMIT_ALLOWLIST_TOKEN_PREFIXES'));
        $this->assertTrue((bool)$settings->get('API_TOKEN_RATE_LIMIT_AUDIT_ENABLED'));
    }

    public function testParsePasteTransitionDefaultsExist(): void
    {
        $settings = new Settings(
            $this->tempDir . '/settings.json',
            $this->tempDir . '/settings.local.json'
        );

        $this->assertTrue((bool)$settings->get('PARSE_PASTE_V1_ENABLED'));
        $this->assertTrue((bool)$settings->get('PARSE_PASTE_USAGE_AUDIT_ENABLED'));
        $this->assertSame(5, (int)$settings->get('PARSE_PASTE_V1_SAFE_THRESHOLD_PERCENT'));
    }
}
