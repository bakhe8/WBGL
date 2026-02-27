<?php
declare(strict_types=1);

use App\Support\MigrationSqlAdapter;
use PHPUnit\Framework\TestCase;

final class MigrationSqlAdapterTest extends TestCase
{
    public function testSqliteDriverReturnsSqlWithoutChanges(): void
    {
        $sql = "CREATE TABLE sample (id INTEGER PRIMARY KEY AUTOINCREMENT, created_at DATETIME);";

        $normalized = MigrationSqlAdapter::normalizeForDriver($sql, 'sqlite');

        $this->assertSame($sql, $normalized);
    }

    public function testPgsqlDriverRewritesAutoincrementAndDatetime(): void
    {
        $sql = "CREATE TABLE sample (id INTEGER PRIMARY KEY AUTOINCREMENT, created_at DATETIME NOT NULL);";

        $normalized = MigrationSqlAdapter::normalizeForDriver($sql, 'pgsql');

        $this->assertStringContainsString('BIGSERIAL PRIMARY KEY', $normalized);
        $this->assertStringContainsString('created_at TIMESTAMP NOT NULL', $normalized);
        $this->assertStringNotContainsString('AUTOINCREMENT', $normalized);
        $this->assertStringNotContainsString('DATETIME', $normalized);
    }

    public function testPgsqlDriverRewritesInsertOrIgnoreStatements(): void
    {
        $sql = <<<SQL
INSERT OR IGNORE INTO permissions (name, slug) VALUES ('x', 'y');
INSERT OR IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug = 'x';
SQL;

        $normalized = MigrationSqlAdapter::normalizeForDriver($sql, 'pgsql');

        $this->assertStringNotContainsString('INSERT OR IGNORE', $normalized);
        $this->assertSame(2, preg_match_all('/ON CONFLICT DO NOTHING/i', $normalized));
    }
}
