<?php
declare(strict_types=1);

/**
 * PostgreSQL activation rehearsal for WBGL without switching runtime driver.
 *
 * Usage:
 *   php maint/pgsql-activation-rehearsal.php
 *   php maint/pgsql-activation-rehearsal.php --apply-migrations
 *   php maint/pgsql-activation-rehearsal.php --host=127.0.0.1 --port=5432 --database=wbgl --user=wbgl --password=secret
 *   php maint/pgsql-activation-rehearsal.php --skip-connectivity
 *   php maint/pgsql-activation-rehearsal.php --json
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Settings;

function wbglPgRehearsalHasFlag(string $name, array $argv): bool
{
    return in_array($name, $argv, true);
}

function wbglPgRehearsalOption(string $prefix, array $argv): ?string
{
    foreach ($argv as $arg) {
        if (!is_string($arg) || !str_starts_with($arg, $prefix)) {
            continue;
        }
        return (string)substr($arg, strlen($prefix));
    }

    return null;
}

/**
 * @return array{exit_code:int,stdout:string,stderr:string}
 */
function wbglPgRehearsalRunScript(string $scriptRelative, array $args = []): array
{
    $scriptPath = base_path($scriptRelative);
    if (!is_file($scriptPath)) {
        return [
            'exit_code' => 127,
            'stdout' => '',
            'stderr' => 'Script not found: ' . $scriptRelative,
        ];
    }

    $commandParts = [PHP_BINARY, $scriptPath];
    foreach ($args as $arg) {
        $commandParts[] = (string)$arg;
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open(
        $commandParts,
        $descriptors,
        $pipes,
        base_path(),
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        $escaped = array_map(static fn(string $part): string => escapeshellarg($part), $commandParts);
        $command = implode(' ', $escaped);
        $process = proc_open($command, $descriptors, $pipes, base_path());
        if (!is_resource($process)) {
            return [
                'exit_code' => 1,
                'stdout' => '',
                'stderr' => 'Failed to start process: ' . $scriptRelative,
            ];
        }
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    return [
        'exit_code' => is_int($exitCode) ? $exitCode : 1,
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
    ];
}

/**
 * @return array<string,mixed>|null
 */
function wbglPgRehearsalJsonOutput(array $commandResult): ?array
{
    $stdout = trim((string)($commandResult['stdout'] ?? ''));
    if ($stdout === '') {
        return null;
    }

    $decoded = json_decode($stdout, true);
    return is_array($decoded) ? $decoded : null;
}

function wbglPgRehearsalLooksLikeDbConnectionError(array $commandResult): bool
{
    $text = strtolower(trim(
        (string)($commandResult['stdout'] ?? '') . "\n" . (string)($commandResult['stderr'] ?? '')
    ));

    if ($text === '') {
        return false;
    }

    return str_contains($text, 'database connection error');
}

/**
 * @param array<string,mixed> $config
 */
function wbglPgRehearsalApplyEnv(array $config): void
{
    putenv('WBGL_DB_DRIVER=pgsql');
    putenv('WBGL_DB_HOST=' . (string)($config['host'] ?? '127.0.0.1'));
    putenv('WBGL_DB_PORT=' . (string)($config['port'] ?? 5432));
    putenv('WBGL_DB_NAME=' . (string)($config['database'] ?? 'wbgl'));
    putenv('WBGL_DB_USER=' . (string)($config['user'] ?? ''));
    putenv('WBGL_DB_PASS=' . (string)($config['password'] ?? ''));
    putenv('WBGL_DB_SSLMODE=' . (string)($config['sslmode'] ?? 'prefer'));
}

$argv = $argv ?? [];
$asJson = wbglPgRehearsalHasFlag('--json', $argv);
$applyMigrations = wbglPgRehearsalHasFlag('--apply-migrations', $argv);
$skipConnectivity = wbglPgRehearsalHasFlag('--skip-connectivity', $argv);

$settings = Settings::getInstance();
$config = [
    'host' => (string)$settings->get('DB_HOST', '127.0.0.1'),
    'port' => (int)$settings->get('DB_PORT', 5432),
    'database' => (string)$settings->get('DB_NAME', 'wbgl'),
    'user' => (string)$settings->get('DB_USER', ''),
    'password' => (string)$settings->get('DB_PASS', ''),
    'sslmode' => (string)$settings->get('DB_SSLMODE', 'prefer'),
];

$hostOpt = wbglPgRehearsalOption('--host=', $argv);
if (is_string($hostOpt) && trim($hostOpt) !== '') {
    $config['host'] = trim($hostOpt);
}

$portOpt = wbglPgRehearsalOption('--port=', $argv);
if (is_string($portOpt) && is_numeric($portOpt)) {
    $config['port'] = (int)$portOpt;
}

$dbOpt = wbglPgRehearsalOption('--database=', $argv);
if (is_string($dbOpt) && trim($dbOpt) !== '') {
    $config['database'] = trim($dbOpt);
}

$userOpt = wbglPgRehearsalOption('--user=', $argv);
if (is_string($userOpt)) {
    $config['user'] = $userOpt;
}

$passwordOpt = wbglPgRehearsalOption('--password=', $argv);
if (is_string($passwordOpt)) {
    $config['password'] = $passwordOpt;
}

$sslOpt = wbglPgRehearsalOption('--sslmode=', $argv);
if (is_string($sslOpt) && trim($sslOpt) !== '') {
    $config['sslmode'] = trim($sslOpt);
}

wbglPgRehearsalApplyEnv($config);

$checks = [
    'extensions' => [
        'pdo_pgsql' => extension_loaded('pdo_pgsql'),
        'pgsql' => extension_loaded('pgsql'),
    ],
    'driver_status' => null,
    'portability' => null,
    'migrate' => null,
    'migration_status' => null,
    'fingerprint' => null,
];

$driverStatus = wbglPgRehearsalRunScript('maint/db-driver-status.php', ['--json']);
$driverStatusJson = wbglPgRehearsalJsonOutput($driverStatus);
$driverStatusJsonValid = is_array($driverStatusJson)
    && array_key_exists('driver', $driverStatusJson)
    && is_array($driverStatusJson['connectivity'] ?? null);
$checks['driver_status'] = [
    'ok' => $driverStatusJsonValid
        && !wbglPgRehearsalLooksLikeDbConnectionError($driverStatus),
    'exit_code' => $driverStatus['exit_code'],
    'driver' => $driverStatusJsonValid ? (string)($driverStatusJson['driver'] ?? '') : '',
    'connectivity' => $driverStatusJsonValid ? (bool)($driverStatusJson['connectivity']['ok'] ?? false) : false,
    'error' => $driverStatusJsonValid
        ? (string)($driverStatusJson['connectivity']['error'] ?? '')
        : trim((string)($driverStatus['stderr'] ?? '') . "\n" . (string)($driverStatus['stdout'] ?? '')),
];

$portability = wbglPgRehearsalRunScript('maint/check-migration-portability.php', ['--json']);
$portabilityJson = wbglPgRehearsalJsonOutput($portability);
$portabilityJsonValid = is_array($portabilityJson) && is_array($portabilityJson['summary'] ?? null);
$checks['portability'] = [
    'ok' => $portability['exit_code'] === 0 && $portabilityJsonValid,
    'exit_code' => $portability['exit_code'],
    'high_blockers' => $portabilityJsonValid ? (int)($portabilityJson['summary']['high_blockers'] ?? 0) : -1,
    'medium_issues' => $portabilityJsonValid ? (int)($portabilityJson['summary']['medium_issues'] ?? 0) : -1,
];

if ($skipConnectivity) {
    $checks['migrate'] = [
        'ok' => false,
        'skipped' => true,
        'reason' => 'skip-connectivity flag enabled',
    ];
    $checks['migration_status'] = [
        'ok' => false,
        'skipped' => true,
        'reason' => 'skip-connectivity flag enabled',
    ];
    $checks['fingerprint'] = [
        'ok' => false,
        'skipped' => true,
        'reason' => 'skip-connectivity flag enabled',
    ];
} else {
    $migrateArgs = $applyMigrations ? [] : ['--dry-run'];
    $migrate = wbglPgRehearsalRunScript('maint/migrate.php', $migrateArgs);
    $checks['migrate'] = [
        'ok' => $migrate['exit_code'] === 0
            && !wbglPgRehearsalLooksLikeDbConnectionError($migrate)
            && !str_contains(strtolower((string)($migrate['stdout'] ?? '')), 'migration runner failed'),
        'exit_code' => $migrate['exit_code'],
        'mode' => $applyMigrations ? 'apply' : 'dry-run',
        'stderr' => trim((string)$migrate['stderr']),
    ];

    $migrationStatus = wbglPgRehearsalRunScript('maint/migration-status.php');
    $checks['migration_status'] = [
        'ok' => $migrationStatus['exit_code'] === 0
            && !wbglPgRehearsalLooksLikeDbConnectionError($migrationStatus)
            && !str_contains(strtolower((string)($migrationStatus['stdout'] ?? '')), 'status check failed'),
        'exit_code' => $migrationStatus['exit_code'],
        'stderr' => trim((string)$migrationStatus['stderr']),
    ];

    $fingerprint = wbglPgRehearsalRunScript('maint/db-cutover-fingerprint.php', ['--json']);
    $fingerprintJson = wbglPgRehearsalJsonOutput($fingerprint);
    $fingerprintJsonValid = is_array($fingerprintJson) && array_key_exists('fingerprint', $fingerprintJson);
    $checks['fingerprint'] = [
        'ok' => $fingerprint['exit_code'] === 0
            && $fingerprintJsonValid
            && !wbglPgRehearsalLooksLikeDbConnectionError($fingerprint),
        'exit_code' => $fingerprint['exit_code'],
        'driver' => $fingerprintJsonValid ? (string)($fingerprintJson['driver'] ?? '') : '',
        'tables_count' => $fingerprintJsonValid ? (int)($fingerprintJson['tables_count'] ?? 0) : 0,
        'stderr' => trim((string)$fingerprint['stderr']),
    ];
}

$extensionsReady = (bool)$checks['extensions']['pdo_pgsql'];
$portabilityReady = (bool)($checks['portability']['ok'] ?? false)
    && (int)($checks['portability']['high_blockers'] ?? 1) === 0;
$connectivityReady = (bool)($checks['driver_status']['ok'] ?? false)
    && (bool)($checks['driver_status']['connectivity'] ?? false);
$executionReady = !$skipConnectivity
    && (bool)($checks['migrate']['ok'] ?? false)
    && (bool)($checks['migration_status']['ok'] ?? false)
    && (bool)($checks['fingerprint']['ok'] ?? false);

$readyForPgActivation = $extensionsReady && $portabilityReady && $connectivityReady && ($skipConnectivity ? false : $executionReady);
$commandSuccessful = $skipConnectivity
    ? ($extensionsReady && $portabilityReady)
    : $readyForPgActivation;

$report = [
    'generated_at' => date('c'),
    'mode' => [
        'apply_migrations' => $applyMigrations,
        'skip_connectivity' => $skipConnectivity,
    ],
    'target' => [
        'driver' => 'pgsql',
        'host' => (string)$config['host'],
        'port' => (int)$config['port'],
        'database' => (string)$config['database'],
        'user' => (string)$config['user'],
        'sslmode' => (string)$config['sslmode'],
    ],
    'checks' => $checks,
    'summary' => [
        'extensions_ready' => $extensionsReady,
        'portability_ready' => $portabilityReady,
        'connectivity_ready' => $connectivityReady,
        'execution_ready' => $executionReady,
        'ready_for_pg_activation' => $readyForPgActivation,
    ],
];

$cutoverDir = base_path('storage/database/cutover');
if (!is_dir($cutoverDir)) {
    @mkdir($cutoverDir, 0777, true);
}

$outputPath = $cutoverDir . '/pgsql_activation_rehearsal_' . date('Ymd_His') . '.json';
file_put_contents(
    $outputPath,
    json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL
);

$latestPath = $cutoverDir . '/pgsql_activation_rehearsal_latest.json';
file_put_contents(
    $latestPath,
    json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL
);

if ($asJson) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo "WBGL PostgreSQL Activation Rehearsal\n";
    echo str_repeat('-', 72) . "\n";
    echo "Target Host         : " . (string)$config['host'] . ":" . (int)$config['port'] . "\n";
    echo "Target Database     : " . (string)$config['database'] . "\n";
    echo "Apply migrations    : " . ($applyMigrations ? 'yes' : 'no (dry-run)') . "\n";
    echo "Skip connectivity   : " . ($skipConnectivity ? 'yes' : 'no') . "\n";
    echo "Extensions ready    : " . ($extensionsReady ? 'yes' : 'no') . "\n";
    echo "Portability ready   : " . ($portabilityReady ? 'yes' : 'no') . "\n";
    echo "Connectivity ready  : " . ($connectivityReady ? 'yes' : 'no') . "\n";
    echo "Execution ready     : " . ($executionReady ? 'yes' : 'no') . "\n";
    echo "PG activation ready : " . ($readyForPgActivation ? 'yes' : 'no') . "\n";
    echo "Report              : " . $outputPath . "\n";
    echo "Latest pointer      : " . $latestPath . "\n";
}

exit($commandSuccessful ? 0 : 1);
