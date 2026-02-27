<?php
declare(strict_types=1);

/**
 * Validate Wave-4 DB cutover baseline readiness.
 *
 * Usage:
 *   php maint/db-cutover-check.php
 *   php maint/db-cutover-check.php --json
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;

function wbglCutoverHasFlag(string $name, array $argv): bool
{
    return in_array($name, $argv, true);
}

function wbglCutoverTableExists(PDO $db, string $table): bool
{
    try {
        $stmt = $db->prepare(
            "SELECT 1
             FROM information_schema.tables
             WHERE table_schema = 'public' AND table_name = :table
             LIMIT 1"
        );
        $stmt->execute(['table' => $table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @return string[]
 */
function wbglCutoverAppliedMigrations(PDO $db): array
{
    try {
        $rows = $db->query('SELECT migration FROM schema_migrations ORDER BY migration ASC');
        if (!$rows) {
            return [];
        }

        $applied = [];
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $migration = trim((string)($row['migration'] ?? ''));
            if ($migration !== '') {
                $applied[] = $migration;
            }
        }
        return $applied;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @return array<string,mixed>
 */
function wbglCutoverCollectStatus(): array
{
    $driver = Database::currentDriver();
    $summary = Database::configurationSummary();

    $db = null;
    $connectionOk = false;
    $connectionError = null;
    try {
        $db = Database::connect();
        $probe = $db->query('SELECT 1');
        $connectionOk = $probe !== false;
    } catch (Throwable $e) {
        $connectionError = $e->getMessage();
    }

    $maintDir = base_path('maint');
    $repoDocs = base_path('docs');
    $workspaceDocs = dirname(base_path('')) . DIRECTORY_SEPARATOR . 'Docs';
    $backupsDir = base_path('storage/database/backups');

    $scripts = [
        'db_driver_status' => is_file($maintDir . DIRECTORY_SEPARATOR . 'db-driver-status.php'),
        'db_cutover_check' => is_file($maintDir . DIRECTORY_SEPARATOR . 'db-cutover-check.php'),
        'backup_db' => is_file($maintDir . DIRECTORY_SEPARATOR . 'backup-db.php'),
        'migration_portability_check' => is_file($maintDir . DIRECTORY_SEPARATOR . 'check-migration-portability.php'),
        'cutover_fingerprint' => is_file($maintDir . DIRECTORY_SEPARATOR . 'db-cutover-fingerprint.php'),
        'migrate' => is_file($maintDir . DIRECTORY_SEPARATOR . 'migrate.php'),
        'migration_status' => is_file($maintDir . DIRECTORY_SEPARATOR . 'migration-status.php'),
    ];

    $runbooks = [
        'db_cutover_repo' => is_file($repoDocs . DIRECTORY_SEPARATOR . 'DB_CUTOVER_RUNBOOK.md'),
        'backup_restore_repo' => is_file($repoDocs . DIRECTORY_SEPARATOR . 'BACKUP_RESTORE_RUNBOOK.md'),
        'db_cutover_workspace' => is_file($workspaceDocs . DIRECTORY_SEPARATOR . 'DB_CUTOVER_RUNBOOK.md'),
        'backup_restore_workspace' => is_file($workspaceDocs . DIRECTORY_SEPARATOR . 'BACKUP_RESTORE_RUNBOOK.md'),
    ];

    $backupFiles = glob($backupsDir . DIRECTORY_SEPARATOR . '*.sql') ?: [];
    usort($backupFiles, static function (string $a, string $b): int {
        $ma = filemtime($a) ?: 0;
        $mb = filemtime($b) ?: 0;
        return $mb <=> $ma;
    });

    $latestBackup = $backupFiles[0] ?? null;
    $latestBackupAgeHours = null;
    if (is_string($latestBackup) && is_file($latestBackup)) {
        $mtime = filemtime($latestBackup);
        if ($mtime !== false) {
            $latestBackupAgeHours = round((time() - $mtime) / 3600, 2);
        }
    }

    $schemaMigrationsTable = false;
    $pendingMigrations = null;
    if ($connectionOk && $db instanceof PDO) {
        $schemaMigrationsTable = wbglCutoverTableExists($db, 'schema_migrations');
        if ($schemaMigrationsTable) {
            $migrationFiles = glob(base_path('database/migrations/*.sql')) ?: [];
            $fileNames = array_map(static fn(string $f): string => basename($f), $migrationFiles);
            sort($fileNames);
            $applied = wbglCutoverAppliedMigrations($db);
            $pendingMigrations = count(array_diff($fileNames, $applied));
        }
    }

    $runbooksReady = $runbooks['db_cutover_repo']
        && $runbooks['backup_restore_repo']
        && $runbooks['db_cutover_workspace']
        && $runbooks['backup_restore_workspace'];
    $scriptsReady = !in_array(false, $scripts, true);
    $backupsReady = is_dir($backupsDir) && count($backupFiles) > 0;

    $readiness = [
        'connection_ok' => $connectionOk,
        'scripts_ready' => $scriptsReady,
        'runbooks_ready' => $runbooksReady,
        'backups_ready' => $backupsReady,
        'schema_migrations_table' => $schemaMigrationsTable,
        'pending_migrations_zero' => $pendingMigrations === 0,
    ];

    $ready = !in_array(false, $readiness, true);

    return [
        'generated_at' => date('c'),
        'driver' => $driver,
        'configuration' => $summary,
        'connection' => [
            'ok' => $connectionOk,
            'error' => $connectionError,
        ],
        'scripts' => $scripts,
        'runbooks' => $runbooks,
        'backups' => [
            'directory' => $backupsDir,
            'count' => count($backupFiles),
            'latest' => $latestBackup,
            'latest_age_hours' => $latestBackupAgeHours,
        ],
        'migrations' => [
            'schema_migrations_table' => $schemaMigrationsTable,
            'pending' => $pendingMigrations,
        ],
        'readiness' => $readiness,
        'ready' => $ready,
    ];
}

$asJson = wbglCutoverHasFlag('--json', $argv ?? []);
$status = wbglCutoverCollectStatus();

if ($asJson) {
    echo json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(($status['ready'] ?? false) ? 0 : 1);
}

echo "WBGL DB Cutover Check\n";
echo str_repeat('-', 72) . "\n";
echo "Driver            : " . (string)($status['driver'] ?? '-') . "\n";
echo "Connection        : " . ((bool)($status['connection']['ok'] ?? false) ? 'OK' : 'FAILED') . "\n";
if (!empty($status['connection']['error'])) {
    echo "Connection Error  : " . (string)$status['connection']['error'] . "\n";
}
echo "Scripts ready     : " . ((bool)($status['readiness']['scripts_ready'] ?? false) ? 'yes' : 'no') . "\n";
echo "Runbooks ready    : " . ((bool)($status['readiness']['runbooks_ready'] ?? false) ? 'yes' : 'no') . "\n";
echo "Backups ready     : " . ((bool)($status['readiness']['backups_ready'] ?? false) ? 'yes' : 'no') . "\n";
echo "Schema migrations : " . ((bool)($status['readiness']['schema_migrations_table'] ?? false) ? 'yes' : 'no') . "\n";
echo "Pending migrations: " . (string)($status['migrations']['pending'] ?? 'unknown') . "\n";
echo "Overall ready     : " . ((bool)($status['ready'] ?? false) ? 'yes' : 'no') . "\n";

exit((bool)($status['ready'] ?? false) ? 0 : 1);
