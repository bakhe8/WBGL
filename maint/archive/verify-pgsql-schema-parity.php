<?php
declare(strict_types=1);

/**
 * Verify PostgreSQL schema/data parity against WBGL SQLite baseline.
 *
 * Usage:
 *   php maint/archive/verify-pgsql-schema-parity.php --json
 *   php maint/archive/verify-pgsql-schema-parity.php --sqlite=storage/database/app.sqlite --host=127.0.0.1 --port=5432 --database=wbgl --user=wbgl_user --password=secret
 */

require_once __DIR__ . '/../../app/Support/autoload.php';

use App\Support\Settings;

function wbglParityHasFlag(string $flag, array $argv): bool
{
    return in_array($flag, $argv, true);
}

function wbglParityOption(string $prefix, array $argv): ?string
{
    foreach ($argv as $arg) {
        if (!is_string($arg) || !str_starts_with($arg, $prefix)) {
            continue;
        }
        return (string)substr($arg, strlen($prefix));
    }
    return null;
}

function wbglParityResolveDefaultWaiverFile(): string
{
    $repoDocsPath = base_path('docs/PGSQL_PARITY_WAIVERS.json');
    $workspaceDocsPath = dirname(base_path()) . DIRECTORY_SEPARATOR . 'Docs' . DIRECTORY_SEPARATOR . 'PGSQL_PARITY_WAIVERS.json';

    if (is_file($repoDocsPath)) {
        return $repoDocsPath;
    }
    if (is_file($workspaceDocsPath)) {
        return $workspaceDocsPath;
    }

    return $repoDocsPath;
}

/**
 * @return array{
 *   file:string,
 *   loaded:bool,
 *   error:string,
 *   row_count:array<string,array{reason:string,max_delta:?int}>,
 *   type_mismatch:array<string,array{reason:string,sqlite_type:string,pgsql_type:string}>
 * }
 */
function wbglParityLoadWaivers(string $waiverFile): array
{
    $result = [
        'file' => $waiverFile,
        'loaded' => false,
        'error' => '',
        'row_count' => [],
        'type_mismatch' => [],
    ];

    if (!is_file($waiverFile)) {
        return $result;
    }

    $raw = file_get_contents($waiverFile);
    if (!is_string($raw) || trim($raw) === '') {
        $result['error'] = 'Waiver file exists but is empty.';
        return $result;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        $result['error'] = 'Waiver file is not valid JSON.';
        return $result;
    }

    $rowCountEntries = $decoded['row_count'] ?? [];
    if (is_array($rowCountEntries)) {
        foreach ($rowCountEntries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $table = trim((string)($entry['table'] ?? ''));
            if ($table === '') {
                continue;
            }
            $maxDelta = null;
            if (isset($entry['max_delta']) && is_numeric($entry['max_delta'])) {
                $maxDelta = max(0, (int)$entry['max_delta']);
            }
            $result['row_count'][$table] = [
                'reason' => trim((string)($entry['reason'] ?? '')),
                'max_delta' => $maxDelta,
            ];
        }
    }

    $typeEntries = $decoded['type_mismatch'] ?? [];
    if (is_array($typeEntries)) {
        foreach ($typeEntries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $table = trim((string)($entry['table'] ?? ''));
            $column = trim((string)($entry['column'] ?? ''));
            if ($table === '' || $column === '') {
                continue;
            }
            $key = $table . '.' . $column;
            $result['type_mismatch'][$key] = [
                'reason' => trim((string)($entry['reason'] ?? '')),
                'sqlite_type' => strtolower(trim((string)($entry['sqlite_type'] ?? ''))),
                'pgsql_type' => strtolower(trim((string)($entry['pgsql_type'] ?? ''))),
            ];
        }
    }

    $result['loaded'] = true;
    return $result;
}

/**
 * @param array<string,mixed> $waivers
 * @return array{reason:string,max_delta:?int}|null
 */
function wbglParityRowCountWaiver(array $waivers, string $table, int $delta): ?array
{
    $entry = $waivers['row_count'][$table] ?? null;
    if (!is_array($entry)) {
        return null;
    }

    $maxDelta = $entry['max_delta'] ?? null;
    if (is_int($maxDelta) && $delta > $maxDelta) {
        return null;
    }

    return [
        'reason' => (string)($entry['reason'] ?? ''),
        'max_delta' => is_int($maxDelta) ? $maxDelta : null,
    ];
}

/**
 * @param array<string,mixed> $waivers
 * @return array{reason:string}|null
 */
function wbglParityTypeMismatchWaiver(
    array $waivers,
    string $table,
    string $column,
    string $sqliteType,
    string $pgType
): ?array {
    $entry = $waivers['type_mismatch'][$table . '.' . $column] ?? null;
    if (!is_array($entry)) {
        return null;
    }

    $expectedSqlite = strtolower(trim((string)($entry['sqlite_type'] ?? '')));
    if ($expectedSqlite !== '' && $expectedSqlite !== strtolower($sqliteType)) {
        return null;
    }

    $expectedPg = strtolower(trim((string)($entry['pgsql_type'] ?? '')));
    if ($expectedPg !== '' && $expectedPg !== strtolower($pgType)) {
        return null;
    }

    return [
        'reason' => (string)($entry['reason'] ?? ''),
    ];
}

function wbglParityNormalizeSqliteType(string $type): string
{
    $t = strtoupper(trim($type));
    if ($t === '') {
        return 'text';
    }
    if (str_contains($t, 'INT')) {
        return 'integer';
    }
    if (str_contains($t, 'CHAR') || str_contains($t, 'CLOB') || str_contains($t, 'TEXT')) {
        return 'text';
    }
    if (str_contains($t, 'BLOB')) {
        return 'blob';
    }
    if (str_contains($t, 'REAL') || str_contains($t, 'FLOA') || str_contains($t, 'DOUB')) {
        return 'float';
    }
    if (str_contains($t, 'NUM') || str_contains($t, 'DEC')) {
        return 'numeric';
    }
    if (str_contains($t, 'BOOL')) {
        return 'boolean';
    }
    if (str_contains($t, 'DATE') || str_contains($t, 'TIME')) {
        return 'datetime';
    }
    if (str_contains($t, 'JSON')) {
        return 'json';
    }
    return 'text';
}

function wbglParityNormalizePgType(string $dataType, ?string $udtName = null): string
{
    $t = strtolower(trim($dataType));
    $udt = strtolower(trim((string)$udtName));

    if (in_array($t, ['smallint', 'integer', 'bigint'], true)) {
        return 'integer';
    }
    if (in_array($t, ['real', 'double precision'], true)) {
        return 'float';
    }
    if (in_array($t, ['numeric', 'decimal'], true)) {
        return 'numeric';
    }
    if (in_array($t, ['character varying', 'character', 'text'], true)) {
        return 'text';
    }
    if ($t === 'boolean') {
        return 'boolean';
    }
    if (str_starts_with($t, 'timestamp') || $t === 'date' || str_starts_with($t, 'time')) {
        return 'datetime';
    }
    if (in_array($t, ['json', 'jsonb'], true)) {
        return 'json';
    }
    if ($t === 'bytea') {
        return 'blob';
    }

    if ($udt !== '') {
        if (in_array($udt, ['int2', 'int4', 'int8'], true)) {
            return 'integer';
        }
        if (in_array($udt, ['float4', 'float8'], true)) {
            return 'float';
        }
        if ($udt === 'bool') {
            return 'boolean';
        }
    }

    return $t !== '' ? $t : 'unknown';
}

function wbglParityQuotedIdent(string $name): string
{
    return '"' . str_replace('"', '""', $name) . '"';
}

$argv = $argv ?? [];
$asJson = wbglParityHasFlag('--json', $argv);

$settings = Settings::getInstance();
$waiverFile = wbglParityOption('--waiver-file=', $argv) ?? wbglParityResolveDefaultWaiverFile();
if (!preg_match('/^[A-Za-z]:[\\\\\\/]/', $waiverFile) && !str_starts_with($waiverFile, '/')) {
    if (!is_file($waiverFile)) {
        $waiverFile = base_path($waiverFile);
    }
}
$waivers = wbglParityLoadWaivers($waiverFile);
$sqlitePath = wbglParityOption('--sqlite=', $argv);
if ($sqlitePath === null || trim($sqlitePath) === '') {
    $sqlitePath = base_path('storage/database/app.sqlite');
}
if (!preg_match('/^[A-Za-z]:[\\\\\\/]/', $sqlitePath) && !str_starts_with($sqlitePath, '/')) {
    $asGiven = $sqlitePath;
    if (!is_file($asGiven)) {
        $sqlitePath = base_path($sqlitePath);
    }
}

$cfg = [
    'host' => wbglParityOption('--host=', $argv) ?: (string)$settings->get('DB_HOST', '127.0.0.1'),
    'port' => (int)(wbglParityOption('--port=', $argv) ?: (string)$settings->get('DB_PORT', 5432)),
    'database' => wbglParityOption('--database=', $argv) ?: (string)$settings->get('DB_NAME', 'wbgl'),
    'user' => wbglParityOption('--user=', $argv) ?: (string)$settings->get('DB_USER', ''),
    'password' => wbglParityOption('--password=', $argv) ?: (string)$settings->get('DB_PASS', ''),
    'sslmode' => wbglParityOption('--sslmode=', $argv) ?: (string)$settings->get('DB_SSLMODE', 'prefer'),
];

$report = [
    'generated_at' => date(DATE_ATOM),
    'source' => ['sqlite' => $sqlitePath],
    'target' => [
        'host' => $cfg['host'],
        'port' => $cfg['port'],
        'database' => $cfg['database'],
        'user' => $cfg['user'],
        'sslmode' => $cfg['sslmode'],
    ],
    'waivers' => [
        'file' => $waivers['file'],
        'loaded' => (bool)$waivers['loaded'],
        'error' => (string)$waivers['error'],
        'row_count_entries' => count($waivers['row_count']),
        'type_mismatch_entries' => count($waivers['type_mismatch']),
    ],
    'summary' => [
        'tables_in_sqlite' => 0,
        'tables_in_pgsql' => 0,
        'missing_tables_in_pgsql' => 0,
        'extra_tables_in_pgsql' => 0,
        'missing_columns_in_pgsql' => 0,
        'type_mismatches' => 0,
        'type_mismatches_waived' => 0,
        'row_count_mismatches' => 0,
        'row_count_mismatches_waived' => 0,
        'migration_files' => 0,
        'migration_pending_in_pgsql' => 0,
        'waiver_active' => false,
        'schema_parity_ok' => false,
        'migration_parity_ok' => false,
        'runtime_ready' => false,
    ],
    'tables' => [
        'missing_in_pgsql' => [],
        'extra_in_pgsql' => [],
        'common' => [],
    ],
    'migrations' => [
        'applied' => [],
        'pending' => [],
    ],
    'artifacts' => [
        'timestamped_report' => '',
        'latest_report' => '',
    ],
    'error' => '',
];

try {
    if (!is_file($sqlitePath)) {
        throw new RuntimeException('SQLite source not found: ' . $sqlitePath);
    }
    if ((string)$cfg['user'] === '' || (string)$cfg['password'] === '') {
        throw new RuntimeException('PostgreSQL credentials are required (--user and --password).');
    }

    $sqlite = new PDO('sqlite:' . $sqlitePath);
    $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $sqlite->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $dsn = sprintf(
        'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
        (string)$cfg['host'],
        (int)$cfg['port'],
        (string)$cfg['database'],
        (string)$cfg['sslmode']
    );
    $pgsql = new PDO($dsn, (string)$cfg['user'], (string)$cfg['password']);
    $pgsql->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pgsql->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $sqliteTablesStmt = $sqlite->query(
        "SELECT name
         FROM sqlite_master
         WHERE type = 'table'
           AND name NOT LIKE 'sqlite_%'
         ORDER BY name ASC"
    );
    $sqliteTables = $sqliteTablesStmt ? $sqliteTablesStmt->fetchAll(PDO::FETCH_COLUMN) : [];
    $sqliteTables = array_values(array_map('strval', is_array($sqliteTables) ? $sqliteTables : []));

    $pgTablesStmt = $pgsql->query(
        "SELECT table_name
         FROM information_schema.tables
         WHERE table_schema = 'public'
         ORDER BY table_name ASC"
    );
    $pgTables = $pgTablesStmt ? $pgTablesStmt->fetchAll(PDO::FETCH_COLUMN) : [];
    $pgTables = array_values(array_map('strval', is_array($pgTables) ? $pgTables : []));

    $report['summary']['tables_in_sqlite'] = count($sqliteTables);
    $report['summary']['tables_in_pgsql'] = count($pgTables);

    $missingTables = array_values(array_diff($sqliteTables, $pgTables));
    $extraTables = array_values(array_diff($pgTables, $sqliteTables));
    $report['tables']['missing_in_pgsql'] = $missingTables;
    $report['tables']['extra_in_pgsql'] = $extraTables;
    $report['summary']['missing_tables_in_pgsql'] = count($missingTables);
    $report['summary']['extra_tables_in_pgsql'] = count($extraTables);

    $common = array_values(array_intersect($sqliteTables, $pgTables));
    sort($common, SORT_STRING);

    foreach ($common as $table) {
        $sqliteColsStmt = $sqlite->query('PRAGMA table_info(' . wbglParityQuotedIdent($table) . ')');
        $sqliteColsRows = $sqliteColsStmt ? $sqliteColsStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $sqliteCols = [];
        foreach ($sqliteColsRows as $row) {
            $col = (string)($row['name'] ?? '');
            if ($col === '') {
                continue;
            }
            $sqliteCols[$col] = wbglParityNormalizeSqliteType((string)($row['type'] ?? ''));
        }

        $pgColsStmt = $pgsql->prepare(
            "SELECT column_name, data_type, udt_name
             FROM information_schema.columns
             WHERE table_schema = 'public'
               AND table_name = :table
             ORDER BY ordinal_position ASC"
        );
        $pgColsStmt->execute(['table' => $table]);
        $pgColsRows = $pgColsStmt->fetchAll(PDO::FETCH_ASSOC);
        $pgCols = [];
        foreach ($pgColsRows as $row) {
            $col = (string)($row['column_name'] ?? '');
            if ($col === '') {
                continue;
            }
            $pgCols[$col] = wbglParityNormalizePgType(
                (string)($row['data_type'] ?? ''),
                (string)($row['udt_name'] ?? '')
            );
        }

        $missingCols = array_values(array_diff(array_keys($sqliteCols), array_keys($pgCols)));
        $extraCols = array_values(array_diff(array_keys($pgCols), array_keys($sqliteCols)));
        $report['summary']['missing_columns_in_pgsql'] += count($missingCols);

        $mismatches = [];
        $waivedTypeMismatches = [];
        foreach ($sqliteCols as $col => $sqliteType) {
            if (!array_key_exists($col, $pgCols)) {
                continue;
            }
            $pgType = (string)$pgCols[$col];
            if ($sqliteType !== $pgType) {
                $waiver = wbglParityTypeMismatchWaiver($waivers, $table, $col, $sqliteType, $pgType);
                $payload = [
                    'column' => $col,
                    'sqlite_type' => $sqliteType,
                    'pgsql_type' => $pgType,
                ];
                if ($waiver !== null) {
                    $payload['waiver_reason'] = $waiver['reason'];
                    $waivedTypeMismatches[] = $payload;
                    $report['summary']['type_mismatches_waived']++;
                } else {
                    $mismatches[] = $payload;
                    $report['summary']['type_mismatches']++;
                }
            }
        }

        $sqliteCount = 0;
        $pgCount = 0;
        $sqliteCountStmt = $sqlite->query('SELECT COUNT(*) AS c FROM ' . wbglParityQuotedIdent($table));
        if ($sqliteCountStmt) {
            $sqliteCount = (int)$sqliteCountStmt->fetchColumn();
        }
        $pgCountStmt = $pgsql->query('SELECT COUNT(*) AS c FROM ' . wbglParityQuotedIdent($table));
        if ($pgCountStmt) {
            $pgCount = (int)$pgCountStmt->fetchColumn();
        }
        $countMatch = ($sqliteCount === $pgCount);
        $rowDelta = abs($sqliteCount - $pgCount);
        $rowCountWaiver = null;
        $rowCountWaived = false;
        if (!$countMatch) {
            $rowCountWaiver = wbglParityRowCountWaiver($waivers, $table, $rowDelta);
            if ($rowCountWaiver !== null) {
                $rowCountWaived = true;
                $report['summary']['row_count_mismatches_waived']++;
            } else {
                $report['summary']['row_count_mismatches']++;
            }
        }

        $report['tables']['common'][] = [
            'table' => $table,
            'missing_columns_in_pgsql' => $missingCols,
            'extra_columns_in_pgsql' => $extraCols,
            'type_mismatches' => $mismatches,
            'type_mismatches_waived' => $waivedTypeMismatches,
            'row_count' => [
                'sqlite' => $sqliteCount,
                'pgsql' => $pgCount,
                'match' => $countMatch,
                'delta' => $rowDelta,
                'waived' => $rowCountWaived,
                'waiver' => $rowCountWaiver,
            ],
        ];
    }

    $migrationFiles = glob(base_path('database/migrations/*.sql')) ?: [];
    $migrationNames = array_map(static fn(string $f): string => basename($f), $migrationFiles);
    sort($migrationNames, SORT_STRING);
    $report['summary']['migration_files'] = count($migrationNames);

    $applied = [];
    $hasSchemaMigrationsStmt = $pgsql->query(
        "SELECT 1
         FROM information_schema.tables
         WHERE table_schema='public' AND table_name='schema_migrations'
         LIMIT 1"
    );
    $hasSchemaMigrations = (bool)($hasSchemaMigrationsStmt && $hasSchemaMigrationsStmt->fetchColumn());
    if ($hasSchemaMigrations) {
        $appliedStmt = $pgsql->query('SELECT migration FROM schema_migrations ORDER BY migration ASC');
        $applied = $appliedStmt ? $appliedStmt->fetchAll(PDO::FETCH_COLUMN) : [];
        $applied = array_values(array_map('strval', is_array($applied) ? $applied : []));
    }
    $pending = array_values(array_diff($migrationNames, $applied));
    $report['migrations']['applied'] = $applied;
    $report['migrations']['pending'] = $pending;
    $report['summary']['migration_pending_in_pgsql'] = count($pending);

    $schemaParityOk =
        $report['summary']['missing_tables_in_pgsql'] === 0
        && $report['summary']['missing_columns_in_pgsql'] === 0;
    $migrationParityOk = $report['summary']['migration_pending_in_pgsql'] === 0;
    $runtimeReady =
        $schemaParityOk
        && $migrationParityOk
        && $report['summary']['row_count_mismatches'] === 0;
    $waiverActive =
        $report['waivers']['loaded']
        && (
            $report['summary']['row_count_mismatches_waived'] > 0
            || $report['summary']['type_mismatches_waived'] > 0
        );

    $report['summary']['schema_parity_ok'] = $schemaParityOk;
    $report['summary']['migration_parity_ok'] = $migrationParityOk;
    $report['summary']['waiver_active'] = $waiverActive;
    $report['summary']['runtime_ready'] = $runtimeReady;
} catch (Throwable $e) {
    $report['error'] = $e->getMessage();
}

if ($asJson) {
    $cutoverDir = base_path('storage/database/cutover');
    if (!is_dir($cutoverDir)) {
        @mkdir($cutoverDir, 0777, true);
    }
    $stamp = date('Ymd_His');
    $timestampedPath = $cutoverDir . DIRECTORY_SEPARATOR . 'pgsql_schema_parity_' . $stamp . '.json';
    $latestPath = $cutoverDir . DIRECTORY_SEPARATOR . 'pgsql_schema_parity_latest.json';
    $report['artifacts']['timestamped_report'] = $timestampedPath;
    $report['artifacts']['latest_report'] = $latestPath;
    @file_put_contents($timestampedPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    @file_put_contents($latestPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($report['error'] === '' ? 0 : 1);
}

echo "WBGL PostgreSQL parity verification\n";
echo "SQLite source: {$report['source']['sqlite']}\n";
echo "PG target: {$report['target']['host']}:{$report['target']['port']}/{$report['target']['database']} ({$report['target']['user']})\n";
echo "Tables (sqlite/pgsql): {$report['summary']['tables_in_sqlite']}/{$report['summary']['tables_in_pgsql']}\n";
echo "Missing tables in PG: {$report['summary']['missing_tables_in_pgsql']}\n";
echo "Missing columns in PG: {$report['summary']['missing_columns_in_pgsql']}\n";
echo "Type mismatches: {$report['summary']['type_mismatches']}\n";
echo "Type mismatches waived: {$report['summary']['type_mismatches_waived']}\n";
echo "Row count mismatches: {$report['summary']['row_count_mismatches']}\n";
echo "Row count mismatches waived: {$report['summary']['row_count_mismatches_waived']}\n";
echo "Pending migrations in PG: {$report['summary']['migration_pending_in_pgsql']}\n";
echo "Waiver active: " . ($report['summary']['waiver_active'] ? 'YES' : 'NO') . "\n";
echo "Schema parity: " . ($report['summary']['schema_parity_ok'] ? 'OK' : 'NOT OK') . "\n";
echo "Migration parity: " . ($report['summary']['migration_parity_ok'] ? 'OK' : 'NOT OK') . "\n";
echo "Runtime ready: " . ($report['summary']['runtime_ready'] ? 'YES' : 'NO') . "\n";
if ($report['error'] !== '') {
    echo "Error: {$report['error']}\n";
    exit(1);
}
exit(0);
