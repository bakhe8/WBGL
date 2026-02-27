<?php
declare(strict_types=1);

/**
 * WBGL Migration Status
 *
 * Usage:
 *   php maint/migration-status.php
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;

function wbglMigrationStatusTableSql(): string
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

try {
    $db = Database::connect();
    $driver = Database::currentDriver();
    $db->exec(wbglMigrationStatusTableSql());

    $dir = base_path('database/migrations');
    $files = glob($dir . DIRECTORY_SEPARATOR . '*.sql') ?: [];
    sort($files, SORT_STRING);
    $fileNames = array_map(static fn(string $p): string => basename($p), $files);

    $rows = $db->query('SELECT migration, applied_at FROM schema_migrations ORDER BY migration ASC')->fetchAll(\PDO::FETCH_ASSOC);
    $appliedMap = [];
    foreach ($rows as $row) {
        $appliedMap[(string)$row['migration']] = (string)$row['applied_at'];
    }

    echo "WBGL Migration Status\n";
    echo "Directory: {$dir}\n";
    echo "Driver: {$driver}\n";
    echo str_repeat('-', 72) . "\n";

    if (empty($fileNames)) {
        echo "No migration files found.\n";
        exit(0);
    }

    $pending = 0;
    foreach ($fileNames as $name) {
        if (isset($appliedMap[$name])) {
            echo "[APPLIED] {$name} @ {$appliedMap[$name]}\n";
        } else {
            echo "[PENDING] {$name}\n";
            $pending++;
        }
    }

    echo str_repeat('-', 72) . "\n";
    echo "Total: " . count($fileNames) . " | Pending: {$pending} | Applied: " . (count($fileNames) - $pending) . "\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Status check failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
