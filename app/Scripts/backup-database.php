<?php
declare(strict_types=1);

/**
 * WBGL Database Backup Script (A09)
 *
 * Usage:
 *   php app/Scripts/backup-database.php
 *   php app/Scripts/backup-database.php --output-dir=storage/database/backups --retention-days=14 --label=daily
 *   php app/Scripts/backup-database.php --dry-run
 */

require_once __DIR__ . '/../Support/autoload.php';

use App\Support\Settings;

/**
 * @return array{output_dir:string,retention_days:int,label:string,dry_run:bool}
 */
function wbgl_backup_parse_args(array $args): array
{
    $options = [
        'output_dir' => 'storage/database/backups',
        'retention_days' => 14,
        'label' => 'scheduled',
        'dry_run' => false,
    ];

    foreach ($args as $arg) {
        if ($arg === '--dry-run') {
            $options['dry_run'] = true;
            continue;
        }
        if (str_starts_with($arg, '--output-dir=')) {
            $value = trim((string)substr($arg, 13));
            if ($value !== '') {
                $options['output_dir'] = $value;
            }
            continue;
        }
        if (str_starts_with($arg, '--retention-days=')) {
            $value = (int)trim((string)substr($arg, 17));
            if ($value > 0) {
                $options['retention_days'] = $value;
            }
            continue;
        }
        if (str_starts_with($arg, '--label=')) {
            $value = preg_replace('/[^a-zA-Z0-9_-]/', '_', trim((string)substr($arg, 8)));
            if (is_string($value) && $value !== '') {
                $options['label'] = $value;
            }
        }
    }

    return $options;
}

/**
 * @return array{code:int,stdout:string,stderr:string}
 */
function wbgl_backup_run_command(string $command, array $env = []): array
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

/**
 * @return array{host:string,port:int,database:string,username:string,password:string,sslmode:string}
 */
function wbgl_backup_read_db_config(Settings $settings): array
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

/**
 * @return array{pruned:int}
 */
function wbgl_backup_apply_retention(string $outputDir, int $retentionDays): array
{
    $files = glob(rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.dump') ?: [];
    $cutoff = time() - ($retentionDays * 86400);
    $pruned = 0;

    foreach ($files as $filePath) {
        $modifiedAt = @filemtime($filePath);
        if ($modifiedAt === false || $modifiedAt >= $cutoff) {
            continue;
        }
        if (@unlink($filePath)) {
            $pruned++;
        }
    }

    return ['pruned' => $pruned];
}

$projectRoot = dirname(__DIR__, 2);
$options = wbgl_backup_parse_args(array_slice($argv ?? [], 1));
$outputDir = $options['output_dir'];
if (!preg_match('#^([A-Za-z]:[\\\\/]|/)#', $outputDir)) {
    $outputDir = $projectRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $outputDir);
}
$outputDir = rtrim($outputDir, DIRECTORY_SEPARATOR);
$dryRun = (bool)$options['dry_run'];

try {
    $settings = Settings::getInstance();
    $db = wbgl_backup_read_db_config($settings);

    if (!is_dir($outputDir) && !$dryRun) {
        if (!mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
            throw new RuntimeException('Unable to create backup directory: ' . $outputDir);
        }
    }

    $timestamp = date('Ymd_His');
    $databaseSlug = preg_replace('/[^a-zA-Z0-9_-]/', '_', $db['database']) ?: 'wbgl';
    $backupName = $databaseSlug . '_' . $timestamp . '_' . $options['label'] . '.dump';
    $backupPath = $outputDir . DIRECTORY_SEPARATOR . $backupName;

    $commandParts = [
        'pg_dump',
        '--format=custom',
        '--no-owner',
        '--no-privileges',
        '--file=' . escapeshellarg($backupPath),
        '--host=' . escapeshellarg($db['host']),
        '--port=' . escapeshellarg((string)$db['port']),
    ];
    if ($db['username'] !== '') {
        $commandParts[] = '--username=' . escapeshellarg($db['username']);
    }
    $commandParts[] = escapeshellarg($db['database']);
    $command = implode(' ', $commandParts);

    $env = [
        'PGSSLMODE' => $db['sslmode'],
    ];
    if ($db['password'] !== '') {
        $env['PGPASSWORD'] = $db['password'];
    }

    if ($dryRun) {
        echo json_encode([
            'ok' => true,
            'dry_run' => true,
            'command' => $command,
            'output_dir' => $outputDir,
            'backup_file' => $backupPath,
            'retention_days' => $options['retention_days'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(0);
    }

    $startedAt = microtime(true);
    $result = wbgl_backup_run_command($command, $env);
    if ((int)$result['code'] !== 0) {
        throw new RuntimeException(
            "pg_dump failed with code {$result['code']}: " . trim((string)$result['stderr'])
        );
    }

    if (!is_file($backupPath) || (int)filesize($backupPath) <= 0) {
        throw new RuntimeException('Backup file was not created or is empty: ' . $backupPath);
    }

    $retention = wbgl_backup_apply_retention($outputDir, (int)$options['retention_days']);
    $durationMs = (int)round((microtime(true) - $startedAt) * 1000);

    $summary = [
        'ok' => true,
        'backup_file' => $backupPath,
        'backup_size_bytes' => (int)filesize($backupPath),
        'retention_days' => (int)$options['retention_days'],
        'pruned_files' => (int)$retention['pruned'],
        'duration_ms' => $durationMs,
        'timestamp' => date('c'),
        'database' => $db['database'],
        'host' => $db['host'],
        'sslmode' => $db['sslmode'],
    ];

    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[WBGL_BACKUP_ERROR] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
