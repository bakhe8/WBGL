<?php
declare(strict_types=1);

/**
 * WBGL Restore Drill Script (A09)
 *
 * Usage:
 *   php app/Scripts/restore-drill.php
 *   php app/Scripts/restore-drill.php --backup=storage/database/backups/latest.dump --target-db=wbgl_restore_drill --cleanup
 *   php app/Scripts/restore-drill.php --dry-run
 */

require_once __DIR__ . '/../Support/autoload.php';

use App\Support\Settings;

/**
 * @return array{
 *   backup:?string,
 *   backup_dir:string,
 *   target_db:string,
 *   cleanup:bool,
 *   dry_run:bool,
 *   report:?string
 * }
 */
function wbgl_restore_parse_args(array $args): array
{
    $options = [
        'backup' => null,
        'backup_dir' => 'storage/database/backups',
        'target_db' => 'wbgl_restore_drill',
        'cleanup' => false,
        'dry_run' => false,
        'report' => null,
    ];

    foreach ($args as $arg) {
        if ($arg === '--cleanup') {
            $options['cleanup'] = true;
            continue;
        }
        if ($arg === '--dry-run') {
            $options['dry_run'] = true;
            continue;
        }
        if (str_starts_with($arg, '--backup=')) {
            $value = trim((string)substr($arg, 9));
            if ($value !== '') {
                $options['backup'] = $value;
            }
            continue;
        }
        if (str_starts_with($arg, '--backup-dir=')) {
            $value = trim((string)substr($arg, 13));
            if ($value !== '') {
                $options['backup_dir'] = $value;
            }
            continue;
        }
        if (str_starts_with($arg, '--target-db=')) {
            $value = preg_replace('/[^a-zA-Z0-9_-]/', '_', trim((string)substr($arg, 12)));
            if (is_string($value) && $value !== '') {
                $options['target_db'] = $value;
            }
            continue;
        }
        if (str_starts_with($arg, '--report=')) {
            $value = trim((string)substr($arg, 9));
            if ($value !== '') {
                $options['report'] = $value;
            }
        }
    }

    return $options;
}

/**
 * @return array{code:int,stdout:string,stderr:string}
 */
function wbgl_restore_run_command(string $command, array $env = []): array
{
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptor, $pipes, null, array_merge($_ENV, $env));
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to spawn process: ' . $command);
    }

    fclose($pipes[0]);
    $stdout = (string)stream_get_contents($pipes[1]);
    $stderr = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = (int)proc_close($process);

    return [
        'code' => $code,
        'stdout' => $stdout,
        'stderr' => $stderr,
    ];
}

function wbgl_restore_resolve_path(string $projectRoot, string $path): string
{
    if (preg_match('#^([A-Za-z]:[\\\\/]|/)#', $path)) {
        return $path;
    }

    return $projectRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
}

/**
 * @return array{host:string,port:int,database:string,username:string,password:string,sslmode:string}
 */
function wbgl_restore_read_db_config(Settings $settings): array
{
    return [
        'host' => (string)$settings->get('DB_HOST', '127.0.0.1'),
        'port' => (int)$settings->get('DB_PORT', 5432),
        'database' => (string)$settings->get('DB_NAME', 'wbgl'),
        'username' => (string)$settings->get('DB_USER', ''),
        'password' => (string)$settings->get('DB_PASS', ''),
        'sslmode' => strtolower(trim((string)$settings->get('DB_SSLMODE', 'require'))) ?: 'require',
    ];
}

function wbgl_restore_find_latest_backup(string $backupDir): ?string
{
    $files = glob(rtrim($backupDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.dump') ?: [];
    if (empty($files)) {
        return null;
    }

    usort($files, static function (string $a, string $b): int {
        $timeA = (int)(@filemtime($a) ?: 0);
        $timeB = (int)(@filemtime($b) ?: 0);
        return $timeB <=> $timeA;
    });

    return $files[0] ?? null;
}

/**
 * @return array<string,int|string>
 */
function wbgl_restore_collect_counts(string $host, int $port, string $database, string $username, string $password, string $sslMode): array
{
    $dsn = sprintf(
        'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
        $host,
        $port,
        $database,
        $sslMode
    );
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $tables = [
        'schema_migrations',
        'guarantees',
        'guarantee_decisions',
        'guarantee_history',
        'guarantee_attachments',
    ];
    $result = [];
    foreach ($tables as $table) {
        try {
            $count = (int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
            $result[$table] = $count;
        } catch (Throwable) {
            $result[$table] = -1;
        }
    }

    return $result;
}

$projectRoot = dirname(__DIR__, 2);
$options = wbgl_restore_parse_args(array_slice($argv ?? [], 1));
$backupDir = wbgl_restore_resolve_path($projectRoot, $options['backup_dir']);
$backupPath = $options['backup'] !== null
    ? wbgl_restore_resolve_path($projectRoot, (string)$options['backup'])
    : wbgl_restore_find_latest_backup($backupDir);
$dryRun = (bool)$options['dry_run'];

try {
    $settings = Settings::getInstance();
    $db = wbgl_restore_read_db_config($settings);
    $targetDb = (string)$options['target_db'];
    $backupExists = is_string($backupPath) && $backupPath !== '' && is_file($backupPath);
    $backupPathForCommand = $backupExists
        ? (string)$backupPath
        : ($backupDir . DIRECTORY_SEPARATOR . 'MISSING_BACKUP.dump');
    $env = [
        'PGSSLMODE' => $db['sslmode'],
    ];
    if ($db['password'] !== '') {
        $env['PGPASSWORD'] = $db['password'];
    }

    $baseOptions = [
        '--host=' . escapeshellarg($db['host']),
        '--port=' . escapeshellarg((string)$db['port']),
    ];
    if ($db['username'] !== '') {
        $baseOptions[] = '--username=' . escapeshellarg($db['username']);
    }

    $dropCommand = implode(' ', array_merge(['dropdb', '--if-exists'], $baseOptions, [escapeshellarg($targetDb)]));
    $createCommand = implode(' ', array_merge(['createdb'], $baseOptions, [escapeshellarg($targetDb)]));
    $restoreCommand = implode(' ', array_merge(
        ['pg_restore', '--clean', '--if-exists', '--no-owner', '--no-privileges'],
        $baseOptions,
        [
            '--dbname=' . escapeshellarg($targetDb),
            escapeshellarg($backupPathForCommand),
        ]
    ));

    if ($dryRun) {
        echo json_encode([
            'ok' => true,
            'dry_run' => true,
            'backup' => $backupPathForCommand,
            'backup_exists' => $backupExists,
            'target_db' => $targetDb,
            'commands' => [$dropCommand, $createCommand, $restoreCommand],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(0);
    }

    if (!$backupExists) {
        throw new RuntimeException('Backup file not found. Use --backup=... or create a backup first.');
    }

    $startedAt = microtime(true);
    foreach ([$dropCommand, $createCommand, $restoreCommand] as $command) {
        $result = wbgl_restore_run_command($command, $env);
        if ((int)$result['code'] !== 0) {
            throw new RuntimeException(
                "Command failed ({$result['code']}): {$command}\n" . trim((string)$result['stderr'])
            );
        }
    }

    $sourceCounts = wbgl_restore_collect_counts(
        $db['host'],
        $db['port'],
        $db['database'],
        $db['username'],
        $db['password'],
        $db['sslmode']
    );
    $restoredCounts = wbgl_restore_collect_counts(
        $db['host'],
        $db['port'],
        $targetDb,
        $db['username'],
        $db['password'],
        $db['sslmode']
    );

    if ($options['cleanup']) {
        $cleanupResult = wbgl_restore_run_command($dropCommand, $env);
        if ((int)$cleanupResult['code'] !== 0) {
            throw new RuntimeException(
                "Cleanup failed ({$cleanupResult['code']}): " . trim((string)$cleanupResult['stderr'])
            );
        }
    }

    $durationMs = (int)round((microtime(true) - $startedAt) * 1000);
    $summary = [
        'ok' => true,
        'timestamp' => date('c'),
        'backup_file' => (string)$backupPath,
        'backup_size_bytes' => (int)filesize((string)$backupPath),
        'source_db' => $db['database'],
        'target_db' => $targetDb,
        'cleanup_after_drill' => (bool)$options['cleanup'],
        'duration_ms' => $durationMs,
        'source_counts' => $sourceCounts,
        'restored_counts' => $restoredCounts,
    ];

    $defaultReport = $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs'
        . DIRECTORY_SEPARATOR . 'restore-drill-' . date('Ymd_His') . '.json';
    $reportPath = $options['report'] !== null
        ? wbgl_restore_resolve_path($projectRoot, (string)$options['report'])
        : $defaultReport;
    $reportDir = dirname($reportPath);
    if (!is_dir($reportDir)) {
        mkdir($reportDir, 0755, true);
    }
    file_put_contents($reportPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    echo json_encode(array_merge($summary, ['report' => $reportPath]), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[WBGL_RESTORE_DRILL_ERROR] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
