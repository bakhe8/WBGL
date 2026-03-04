<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PostgresLateMigrationsWiringTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = realpath(__DIR__ . '/../../') ?: dirname(__DIR__, 2);
    }

    public function testLateOperationalMigrationsUsePostgresNativeIdentityAndTimestampTypes(): void
    {
        $targets = [
            'database/migrations/20260226_000004_create_notifications_table.sql',
            'database/migrations/20260226_000007_create_print_events_table.sql',
            'database/migrations/20260226_000010_create_scheduler_dead_letters_table.sql',
        ];

        foreach ($targets as $path) {
            $sql = $this->readFile($path);
            $this->assertStringContainsString('BIGSERIAL PRIMARY KEY', $sql, $path);
            $this->assertStringNotContainsString('AUTOINCREMENT', $sql, $path);
            $this->assertStringNotContainsString('INTEGER PRIMARY KEY AUTOINCREMENT', $sql, $path);
            $this->assertStringContainsString('TIMESTAMP', $sql, $path);
        }
    }

    private function readFile(string $relativePath): string
    {
        $path = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $this->assertFileExists($path, 'Missing required file: ' . $relativePath);
        return (string)file_get_contents($path);
    }
}
