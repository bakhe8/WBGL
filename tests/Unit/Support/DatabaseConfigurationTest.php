<?php
declare(strict_types=1);

use App\Support\Database;
use PHPUnit\Framework\TestCase;

final class DatabaseConfigurationTest extends TestCase
{
    /** @var array<string,string|false> */
    private array $envBackup = [];

    /** @var string[] */
    private array $keys = [
        'WBGL_DB_DRIVER',
        'WBGL_DB_DATABASE',
        'WBGL_DB_HOST',
        'WBGL_DB_PORT',
        'WBGL_DB_NAME',
        'WBGL_DB_USER',
        'WBGL_DB_PASS',
        'WBGL_DB_SSLMODE',
    ];

    protected function setUp(): void
    {
        foreach ($this->keys as $key) {
            $this->envBackup[$key] = getenv($key);
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
        Database::reset();
    }

    protected function tearDown(): void
    {
        foreach ($this->keys as $key) {
            $value = $this->envBackup[$key] ?? false;
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv($key . '=' . $value);
                $_ENV[$key] = (string)$value;
                $_SERVER[$key] = (string)$value;
            }
        }
        Database::reset();
    }

    public function testDefaultsToSettingsConfigurationWhenNoEnvOverrides(): void
    {
        $summary = Database::configurationSummary();

        $this->assertSame('pgsql', (string)($summary['driver'] ?? ''));
        $this->assertSame('127.0.0.1', (string)($summary['host'] ?? ''));
        $this->assertSame(5432, (int)($summary['port'] ?? 0));
        $this->assertSame('wbgl', (string)($summary['database'] ?? ''));
    }

    public function testEnvOverridesAreSecondaryWhenSettingsArePresent(): void
    {
        putenv('WBGL_DB_DRIVER=pgsql');
        putenv('WBGL_DB_HOST=10.10.10.5');
        putenv('WBGL_DB_PORT=5544');
        putenv('WBGL_DB_NAME=wbgl_enterprise');
        putenv('WBGL_DB_USER=wbgl_user');
        putenv('WBGL_DB_PASS=secret');
        putenv('WBGL_DB_SSLMODE=require');
        Database::reset();

        $summary = Database::configurationSummary();

        $this->assertSame('pgsql', (string)($summary['driver'] ?? ''));
        // Settings are now the primary runtime source; env is fallback only.
        $this->assertSame('127.0.0.1', (string)($summary['host'] ?? ''));
        $this->assertSame(5432, (int)($summary['port'] ?? 0));
        $this->assertSame('wbgl', (string)($summary['database'] ?? ''));
        $this->assertSame('prefer', (string)($summary['sslmode'] ?? ''));
    }
}
