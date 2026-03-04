<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SettingsDashboardWiringTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = realpath(__DIR__ . '/../../../') ?: dirname(__DIR__, 3);
    }

    public function testSettingsViewUsesDashboardServiceForBootstrapData(): void
    {
        $view = $this->readFile('views/settings.php');
        $service = $this->readFile('app/Services/SettingsDashboardService.php');

        $this->assertStringContainsString('use App\\Services\\SettingsDashboardService;', $view);
        $this->assertStringContainsString('SettingsDashboardService::buildViewModel(', $view);
        $this->assertStringNotContainsString('use App\\Support\\AuthService;', $view);
        $this->assertStringNotContainsString('use App\\Support\\LocaleResolver;', $view);
        $this->assertStringNotContainsString('use App\\Support\\DirectionResolver;', $view);
        $this->assertStringNotContainsString('use App\\Support\\Settings;', $view);

        $this->assertStringContainsString('final class SettingsDashboardService', $service);
        $this->assertStringContainsString('public static function buildViewModel(', $service);
    }

    private function readFile(string $relativePath): string
    {
        $path = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $this->assertFileExists($path, 'Missing required file: ' . $relativePath);
        return (string)file_get_contents($path);
    }
}
