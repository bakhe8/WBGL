<?php
declare(strict_types=1);

/**
 * Create PostgreSQL backup artifact.
 *
 * Usage:
 *   php maint/backup-db.php
 *   php maint/backup-db.php --retention-days=30
 *   php maint/backup-db.php --json
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;
use App\Support\Settings;

/**
 * @return array<string,mixed>
 */
function wbglBackupParseOptions(array $argv): array
{
    $out = [
        'json' => false,
        'retention_days' => 30,
    ];

    foreach ($argv as $arg) {
        if ($arg === '--json') {
            $out['json'] = true;
            continue;
        }
        if (str_starts_with((string)$arg, '--retention-days=')) {
            $value = (int)substr((string)$arg, strlen('--retention-days='));
            $out['retention_days'] = max(1, $value);
        }
    }

    return $out;
}

/**
 * @return array{deleted:int,kept:int}
 */
function wbglBackupApplyRetention(string $backupDir, int $retentionDays): array
{
    $files = glob($backupDir . DIRECTORY_SEPARATOR . '*.sql') ?: [];
    $cutoff = time() - ($retentionDays * 86400);
    $deleted = 0;
    $kept = 0;

    foreach ($files as $file) {
        if (!is_file($file)) {
            continue;
        }

        $mtime = filemtime($file);
        if ($mtime !== false && $mtime < $cutoff) {
            if (@unlink($file)) {
                $deleted++;
                continue;
            }
        }
        $kept++;
    }

    return ['deleted' => $deleted, 'kept' => $kept];
}

/**
 * @param array<string,mixed> $payload
 */
function wbglBackupExit(array $payload, bool $asJson, int $code): void
{
    if ($asJson) {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        if (($payload['success'] ?? false) === true) {
            echo "WBGL DB Backup completed.\n";
            echo "Driver      : " . ($payload['driver'] ?? '-') . "\n";
            echo "Database    : " . ($payload['database'] ?? '-') . "\n";
            echo "Backup      : " . ($payload['backup'] ?? '-') . "\n";
            echo "Backup SHA  : " . ($payload['backup_sha256'] ?? '-') . "\n";
            echo "Retention   : " . ($payload['retention_days'] ?? '-') . " days\n";
            echo "Deleted old : " . ($payload['deleted_old_backups'] ?? 0) . "\n";
        } else {
            echo "WBGL DB Backup failed: " . ($payload['error'] ?? 'Unknown error') . "\n";
            if (!empty($payload['stderr'])) {
                echo "stderr      : " . $payload['stderr'] . "\n";
            }
        }
    }

    exit($code);
}

function wbglBackupPickConfigValue(Settings $settings, string $settingsKey, string $envKey, string $fallback = ''): string
{
    $settingsValue = trim((string)$settings->get($settingsKey, ''));
    if ($settingsValue !== '') {
        return $settingsValue;
    }

    $envValue = getenv($envKey);
    if ($envValue !== false && trim((string)$envValue) !== '') {
        return trim((string)$envValue);
    }

    return $fallback;
}

function wbglBackupQuoteArg(string $value): string
{
    return '"' . str_replace('"', '""', $value) . '"';
}

function wbglBackupResolvePgDumpPath(string $configured): string
{
    $candidate = trim($configured);
    $candidates = [];
    if ($candidate !== '') {
        $candidates[] = $candidate;
    }

    $candidates[] = 'C:\\PostgreSQL\\16\\bin\\pg_dump.exe';
    $candidates[] = 'C:\\Program Files\\PostgreSQL\\16\\bin\\pg_dump.exe';
    $candidates[] = 'C:\\Program Files (x86)\\PostgreSQL\\16\\bin\\pg_dump.exe';
    $candidates[] = 'pg_dump';

    foreach ($candidates as $item) {
        $isPath = str_contains($item, '\\') || str_contains($item, '/');
        if ($isPath) {
            if (is_file($item)) {
                return $item;
            }
            continue;
        }
        return $item;
    }

    return 'pg_dump';
}

function wbglBackupExecutableArg(string $value): string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return 'pg_dump';
    }
    if (str_contains($trimmed, ' ') || str_contains($trimmed, '\\') || str_contains($trimmed, '/')) {
        return wbglBackupQuoteArg($trimmed);
    }
    return $trimmed;
}

/**
 * @return array{exit_code:int,stdout:string,stderr:string}
 */
function wbglBackupRunCommand(string $command): array
{
    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptors, $pipes, base_path());
    if (!is_resource($process)) {
        return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'Failed to spawn backup process.'];
    }

    $stdout = (string)stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $stderr = (string)stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    return [
        'exit_code' => is_int($exitCode) ? $exitCode : 1,
        'stdout' => trim($stdout),
        'stderr' => trim($stderr),
    ];
}

$options = wbglBackupParseOptions($argv ?? []);
$asJson = (bool)$options['json'];
$retentionDays = (int)$options['retention_days'];

$driver = Database::currentDriver();
if ($driver !== 'pgsql') {
    wbglBackupExit([
        'success' => false,
        'driver' => $driver,
        'error' => 'Unsupported driver for backup. Expected pgsql.',
    ], $asJson, 1);
}

$summary = Database::configurationSummary();
$settings = Settings::getInstance();

$host = trim((string)($summary['host'] ?? '127.0.0.1'));
$port = (int)($summary['port'] ?? 5432);
$database = trim((string)($summary['database'] ?? 'wbgl'));
$username = wbglBackupPickConfigValue($settings, 'DB_USER', 'WBGL_DB_USER', '');
$password = wbglBackupPickConfigValue($settings, 'DB_PASS', 'WBGL_DB_PASS', '');
$pgDumpPath = wbglBackupResolvePgDumpPath(
    wbglBackupPickConfigValue($settings, 'PG_DUMP_PATH', 'WBGL_PG_DUMP_PATH', '')
);

if ($username === '') {
    wbglBackupExit([
        'success' => false,
        'driver' => $driver,
        'error' => 'DB_USER is empty; cannot run pg_dump backup.',
    ], $asJson, 1);
}

$backupDir = base_path('storage/database/backups');
if (!is_dir($backupDir) && !mkdir($backupDir, 0777, true) && !is_dir($backupDir)) {
    wbglBackupExit([
        'success' => false,
        'driver' => $driver,
        'error' => 'Failed to create backup directory: ' . $backupDir,
    ], $asJson, 1);
}

$stamp = date('Ymd_His');
$backupPath = $backupDir . DIRECTORY_SEPARATOR . 'wbgl_pg_' . $stamp . '.sql';

$commandParts = [
    wbglBackupExecutableArg($pgDumpPath),
    '--host=' . wbglBackupQuoteArg($host),
    '--port=' . $port,
    '--username=' . wbglBackupQuoteArg($username),
    '--dbname=' . wbglBackupQuoteArg($database),
    '--format=plain',
    '--encoding=UTF8',
    '--no-owner',
    '--no-privileges',
    '--file=' . wbglBackupQuoteArg($backupPath),
];

$previousPgPassword = getenv('PGPASSWORD');
if ($password !== '') {
    putenv('PGPASSWORD=' . $password);
}

$result = wbglBackupRunCommand(implode(' ', $commandParts));

if ($password !== '') {
    if ($previousPgPassword === false) {
        putenv('PGPASSWORD');
    } else {
        putenv('PGPASSWORD=' . $previousPgPassword);
    }
}
if (($result['exit_code'] ?? 1) !== 0 || !is_file($backupPath)) {
    @unlink($backupPath);
    wbglBackupExit([
        'success' => false,
        'driver' => $driver,
        'database' => $database,
        'error' => 'pg_dump failed.',
        'stdout' => (string)($result['stdout'] ?? ''),
        'stderr' => (string)($result['stderr'] ?? ''),
        'exit_code' => (int)($result['exit_code'] ?? 1),
    ], $asJson, 1);
}

$backupHash = strtoupper((string)hash_file('sha256', $backupPath));
if ($backupHash === '') {
    @unlink($backupPath);
    wbglBackupExit([
        'success' => false,
        'driver' => $driver,
        'database' => $database,
        'error' => 'Backup hash verification failed.',
    ], $asJson, 1);
}

$retention = wbglBackupApplyRetention($backupDir, $retentionDays);

$logPath = $backupDir . DIRECTORY_SEPARATOR . 'BACKUP_LOG.txt';
$logLine = sprintf(
    "%s | driver=pgsql | db=%s | backup=%s | backup_sha256=%s\n",
    $stamp,
    $database,
    basename($backupPath),
    $backupHash
);
file_put_contents($logPath, $logLine, FILE_APPEND);

wbglBackupExit([
    'success' => true,
    'driver' => $driver,
    'database' => $database,
    'backup' => $backupPath,
    'backup_sha256' => $backupHash,
    'retention_days' => $retentionDays,
    'deleted_old_backups' => $retention['deleted'],
], $asJson, 0);
