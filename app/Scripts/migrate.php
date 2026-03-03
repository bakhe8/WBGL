<?php
declare(strict_types=1);

/**
 * WBGL SQL Migration Runner
 *
 * Usage:
 *   php app/Scripts/migrate.php
 *   php app/Scripts/migrate.php --dry-run
 */

require_once __DIR__ . '/../Support/autoload.php';

use App\Support\Database;
use App\Support\MigrationSqlAdapter;

$dryRun = in_array('--dry-run', $argv ?? [], true);

function wbglMigrationTableSql(): string
{
    return <<<SQL
CREATE TABLE IF NOT EXISTS schema_migrations (
    id BIGSERIAL PRIMARY KEY,
    migration VARCHAR(255) NOT NULL UNIQUE,
    checksum VARCHAR(128) NOT NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
SQL;
}

function wbglEnsureMigrationTable(PDO $db): void
{
    $db->exec(wbglMigrationTableSql());
}

/**
 * @return array<int,string>
 */
function wbglGetAppliedMigrations(PDO $db): array
{
    $stmt = $db->query('SELECT migration FROM schema_migrations ORDER BY migration ASC');
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    return array_map(static fn(array $r): string => (string)$r['migration'], $rows);
}

/**
 * @return array<int,string>
 */
function wbglCollectMigrationFiles(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $files = glob($dir . DIRECTORY_SEPARATOR . '*.sql') ?: [];
    sort($files, SORT_STRING);
    return $files;
}

try {
    $db = Database::connect();
    $driver = Database::currentDriver();
    wbglEnsureMigrationTable($db);

    $migrationDir = base_path('database/migrations');
    $files = wbglCollectMigrationFiles($migrationDir);
    $applied = wbglGetAppliedMigrations($db);

    echo "WBGL Migrations" . PHP_EOL;
    echo "Directory: {$migrationDir}" . PHP_EOL;
    echo "Driver: {$driver}" . PHP_EOL;
    echo "Mode: " . ($dryRun ? 'DRY-RUN' : 'APPLY') . PHP_EOL;

    if ($files === []) {
        echo "No migration files found." . PHP_EOL;
        exit(0);
    }

    $pending = 0;
    $appliedNow = 0;

    foreach ($files as $filePath) {
        $name = basename($filePath);
        if (in_array($name, $applied, true)) {
            continue;
        }

        $sourceSql = trim((string)file_get_contents($filePath));
        $checksum = hash('sha256', $sourceSql);
        $pending++;

        echo "- Pending: {$name}" . PHP_EOL;

        if ($dryRun) {
            continue;
        }

        if ($sourceSql === '') {
            throw new RuntimeException("Migration {$name} is empty.");
        }

        $sql = MigrationSqlAdapter::normalizeForDriver($sourceSql, $driver);

        $db->beginTransaction();
        try {
            $db->exec($sql);
            $stmt = $db->prepare('INSERT INTO schema_migrations (migration, checksum) VALUES (?, ?)');
            $stmt->execute([$name, $checksum]);
            $db->commit();
            $appliedNow++;
            echo "  Applied: {$name}" . PHP_EOL;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw new RuntimeException("Failed migration {$name}: " . $e->getMessage(), 0, $e);
        }
    }

    if ($pending === 0) {
        echo "All migrations are up to date." . PHP_EOL;
    } else {
        echo "Pending migrations: {$pending}" . PHP_EOL;
        if (!$dryRun) {
            echo "Applied now: {$appliedNow}" . PHP_EOL;
        }
    }

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Migration runner failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
