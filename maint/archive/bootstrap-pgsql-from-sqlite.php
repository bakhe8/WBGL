<?php
declare(strict_types=1);

/**
 * Bootstrap PostgreSQL from WBGL SQLite baseline schema/data.
 *
 * Usage:
 *   php maint/archive/bootstrap-pgsql-from-sqlite.php
 *   php maint/archive/bootstrap-pgsql-from-sqlite.php --schema-only
 *   php maint/archive/bootstrap-pgsql-from-sqlite.php --sqlite=storage/database/app.sqlite --host=127.0.0.1 --port=5432 --database=wbgl --user=wbgl_user --password=secret
 */

require_once __DIR__ . '/../../app/Support/autoload.php';

use App\Support\MigrationSqlAdapter;
use App\Support\Settings;

function wbglBootHasFlag(string $flag, array $argv): bool
{
    return in_array($flag, $argv, true);
}

function wbglBootOption(string $prefix, array $argv): ?string
{
    foreach ($argv as $arg) {
        if (!is_string($arg) || !str_starts_with($arg, $prefix)) {
            continue;
        }
        return (string)substr($arg, strlen($prefix));
    }
    return null;
}

function wbglBootTableDefinitions(PDO $sqlite): array
{
    $stmt = $sqlite->query(
        "SELECT name, sql
         FROM sqlite_master
         WHERE type = 'table'
           AND name NOT LIKE 'sqlite_%'
         ORDER BY name ASC"
    );
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    return is_array($rows) ? $rows : [];
}

function wbglBootTableDependencies(PDO $sqlite, string $table): array
{
    $quoted = '"' . str_replace('"', '""', $table) . '"';
    $rows = $sqlite->query('PRAGMA foreign_key_list(' . $quoted . ')');
    if (!$rows) {
        return [];
    }

    $deps = [];
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $parent = (string)($row['table'] ?? '');
        if ($parent !== '') {
            $deps[$parent] = true;
        }
    }

    return array_keys($deps);
}

function wbglBootTopoSort(array $tables, array $deps): array
{
    $inDegree = [];
    $children = [];
    foreach ($tables as $table) {
        $inDegree[$table] = 0;
        $children[$table] = [];
    }

    foreach ($deps as $table => $parents) {
        foreach ($parents as $parent) {
            if (!isset($inDegree[$table]) || !isset($inDegree[$parent])) {
                continue;
            }
            $inDegree[$table]++;
            $children[$parent][] = $table;
        }
    }

    $queue = [];
    foreach ($inDegree as $table => $count) {
        if ($count === 0) {
            $queue[] = $table;
        }
    }
    sort($queue, SORT_STRING);

    $order = [];
    while (!empty($queue)) {
        $current = array_shift($queue);
        if ($current === null) {
            break;
        }
        $order[] = $current;
        foreach ($children[$current] as $child) {
            $inDegree[$child]--;
            if ($inDegree[$child] === 0) {
                $queue[] = $child;
            }
        }
        sort($queue, SORT_STRING);
    }

    if (count($order) !== count($tables)) {
        // Fallback to deterministic alphabetical order if cycle/ambiguity appears.
        $fallback = $tables;
        sort($fallback, SORT_STRING);
        return $fallback;
    }

    return $order;
}

function wbglBootNormalizeCreateSqlForPgsql(string $sql): string
{
    $normalized = MigrationSqlAdapter::normalizeForDriver($sql, 'pgsql');

    $replacedNum = preg_replace('/\bNUM\b/i', 'TEXT', $normalized);
    if (is_string($replacedNum)) {
        $normalized = $replacedNum;
    }

    $replacedBool = preg_replace('/\bBOOLEAN\s+DEFAULT\s+0\b/i', 'BOOLEAN DEFAULT FALSE', $normalized);
    if (is_string($replacedBool)) {
        $normalized = $replacedBool;
    }

    $replacedBoolTrue = preg_replace('/\bBOOLEAN\s+DEFAULT\s+1\b/i', 'BOOLEAN DEFAULT TRUE', $normalized);
    if (is_string($replacedBoolTrue)) {
        $normalized = $replacedBoolTrue;
    }

    if (preg_match('/^\s*CREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS)/i', $normalized) === 1) {
        $normalized = preg_replace('/^\s*CREATE\s+TABLE\s+/i', 'CREATE TABLE IF NOT EXISTS ', $normalized) ?: $normalized;
    }

    return $normalized;
}

function wbglBootTableColumns(PDO $sqlite, string $table): array
{
    $quoted = '"' . str_replace('"', '""', $table) . '"';
    $stmt = $sqlite->query('PRAGMA table_info(' . $quoted . ')');
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $columns = [];
    foreach ($rows as $row) {
        $name = (string)($row['name'] ?? '');
        if ($name !== '') {
            $columns[] = $name;
        }
    }
    return $columns;
}

function wbglBootSelectAllRows(PDO $sqlite, string $table, array $columns): array
{
    if (empty($columns)) {
        return [];
    }

    $quotedTable = '"' . str_replace('"', '""', $table) . '"';
    $quotedColumns = array_map(
        static fn(string $c): string => '"' . str_replace('"', '""', $c) . '"',
        $columns
    );

    $stmt = $sqlite->query('SELECT ' . implode(', ', $quotedColumns) . ' FROM ' . $quotedTable);
    if (!$stmt) {
        return [];
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

function wbglBootNormalizeValue(mixed $value): mixed
{
    if (!is_string($value)) {
        return $value;
    }

    $trimmed = trim($value);
    if ($trimmed === '') {
        return $value;
    }

    // SQLite booleans are often stored as 0/1 text; keep them numeric-like for PDO casts.
    if ($trimmed === '0') {
        return 0;
    }
    if ($trimmed === '1') {
        return 1;
    }

    return $value;
}

function wbglBootResetIdentity(PDO $pgsql, string $table): void
{
    $quotedTable = '"' . str_replace('"', '""', $table) . '"';
    $hasIdStmt = $pgsql->prepare(
        "SELECT 1
         FROM information_schema.columns
         WHERE table_schema = 'public'
           AND table_name = :table
           AND column_name = 'id'
         LIMIT 1"
    );
    $hasIdStmt->execute(['table' => $table]);
    $hasId = (bool)$hasIdStmt->fetchColumn();
    if (!$hasId) {
        return;
    }

    $seqStmt = $pgsql->query("SELECT pg_get_serial_sequence('{$quotedTable}', 'id') AS seq");
    $seqRow = $seqStmt ? $seqStmt->fetch(PDO::FETCH_ASSOC) : null;
    $seq = is_array($seqRow) ? (string)($seqRow['seq'] ?? '') : '';
    if ($seq === '') {
        return;
    }

    $maxStmt = $pgsql->query('SELECT COALESCE(MAX(id), 0) AS max_id FROM ' . $quotedTable);
    $maxRow = $maxStmt ? $maxStmt->fetch(PDO::FETCH_ASSOC) : null;
    $maxId = is_array($maxRow) ? (int)($maxRow['max_id'] ?? 0) : 0;
    $target = $maxId > 0 ? $maxId : 1;
    $isCalled = $maxId > 0 ? 'true' : 'false';

    $setStmt = $pgsql->prepare('SELECT setval(:seq::regclass, :target, :called)');
    $setStmt->execute([
        'seq' => $seq,
        'target' => $target,
        'called' => $isCalled,
    ]);
}

function wbglBootConnectSqlite(string $path): PDO
{
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

function wbglBootConnectPgsql(array $cfg): PDO
{
    $dsn = sprintf(
        'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
        (string)$cfg['host'],
        (int)$cfg['port'],
        (string)$cfg['database'],
        (string)$cfg['sslmode']
    );
    $pdo = new PDO($dsn, (string)$cfg['user'], (string)$cfg['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

$argv = $argv ?? [];
$schemaOnly = wbglBootHasFlag('--schema-only', $argv);
$asJson = wbglBootHasFlag('--json', $argv);

$settings = Settings::getInstance();
$sqlitePath = wbglBootOption('--sqlite=', $argv);
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
    'host' => wbglBootOption('--host=', $argv) ?: (string)$settings->get('DB_HOST', '127.0.0.1'),
    'port' => (int)(wbglBootOption('--port=', $argv) ?: (string)$settings->get('DB_PORT', 5432)),
    'database' => wbglBootOption('--database=', $argv) ?: (string)$settings->get('DB_NAME', 'wbgl'),
    'user' => wbglBootOption('--user=', $argv) ?: (string)$settings->get('DB_USER', ''),
    'password' => wbglBootOption('--password=', $argv) ?: (string)$settings->get('DB_PASS', ''),
    'sslmode' => wbglBootOption('--sslmode=', $argv) ?: (string)$settings->get('DB_SSLMODE', 'prefer'),
];

$result = [
    'generated_at' => date(DATE_ATOM),
    'source' => ['sqlite' => $sqlitePath],
    'target' => [
        'host' => $cfg['host'],
        'port' => $cfg['port'],
        'database' => $cfg['database'],
        'user' => $cfg['user'],
        'sslmode' => $cfg['sslmode'],
    ],
    'mode' => [
        'schema_only' => $schemaOnly,
    ],
    'summary' => [
        'tables_total' => 0,
        'tables_created' => 0,
        'rows_copied' => 0,
        'row_errors' => 0,
        'ok' => false,
    ],
    'tables' => [],
    'error' => '',
];

try {
    if (!is_file($sqlitePath)) {
        throw new RuntimeException('SQLite source not found: ' . $sqlitePath);
    }
    if ((string)$cfg['user'] === '' || (string)$cfg['password'] === '') {
        throw new RuntimeException('PostgreSQL credentials are required (--user and --password).');
    }

    $sqlite = wbglBootConnectSqlite($sqlitePath);
    $pgsql = wbglBootConnectPgsql($cfg);

    $definitions = wbglBootTableDefinitions($sqlite);
    $tables = [];
    $createSqlMap = [];
    $deps = [];
    foreach ($definitions as $def) {
        $name = (string)($def['name'] ?? '');
        $sql = trim((string)($def['sql'] ?? ''));
        if ($name === '' || $sql === '') {
            continue;
        }
        $tables[] = $name;
        $createSqlMap[$name] = $sql;
        $deps[$name] = wbglBootTableDependencies($sqlite, $name);
    }

    $order = wbglBootTopoSort($tables, $deps);
    $result['summary']['tables_total'] = count($order);

    foreach ($order as $table) {
        $tableStat = [
            'table' => $table,
            'created' => false,
            'source_rows' => 0,
            'inserted_rows' => 0,
            'errors' => 0,
            'error_samples' => [],
        ];

        $createSql = wbglBootNormalizeCreateSqlForPgsql((string)$createSqlMap[$table]);
        $pgsql->exec($createSql);
        $tableStat['created'] = true;
        $result['summary']['tables_created']++;

        if (!$schemaOnly) {
            $columns = wbglBootTableColumns($sqlite, $table);
            $rows = wbglBootSelectAllRows($sqlite, $table, $columns);
            $tableStat['source_rows'] = count($rows);

            if (!empty($columns) && !empty($rows)) {
                $quotedTable = '"' . str_replace('"', '""', $table) . '"';
                $quotedCols = array_map(
                    static fn(string $c): string => '"' . str_replace('"', '""', $c) . '"',
                    $columns
                );
                $placeholders = implode(', ', array_fill(0, count($columns), '?'));
                $insertSql = 'INSERT INTO ' . $quotedTable
                    . ' (' . implode(', ', $quotedCols) . ') VALUES (' . $placeholders . ') ON CONFLICT DO NOTHING';
                $insertStmt = $pgsql->prepare($insertSql);

                foreach ($rows as $row) {
                    $values = [];
                    foreach ($columns as $col) {
                        $values[] = wbglBootNormalizeValue($row[$col] ?? null);
                    }

                    try {
                        $insertStmt->execute($values);
                        $tableStat['inserted_rows'] += $insertStmt->rowCount();
                    } catch (Throwable $rowError) {
                        $tableStat['errors']++;
                        $result['summary']['row_errors']++;
                        if (count($tableStat['error_samples']) < 3) {
                            $tableStat['error_samples'][] = $rowError->getMessage();
                        }
                    }
                }

                wbglBootResetIdentity($pgsql, $table);
            }
        }

        $result['summary']['rows_copied'] += (int)$tableStat['inserted_rows'];
        $result['tables'][] = $tableStat;
    }

    $result['summary']['ok'] = ((int)$result['summary']['tables_total'] > 0);
} catch (Throwable $e) {
    $result['error'] = $e->getMessage();
}

if ($asJson) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($result['summary']['ok'] ? 0 : 1);
}

echo "WBGL PostgreSQL bootstrap from SQLite\n";
echo "Source SQLite: {$result['source']['sqlite']}\n";
echo "Target PG: {$result['target']['host']}:{$result['target']['port']}/{$result['target']['database']} ({$result['target']['user']})\n";
echo "Mode: " . ($schemaOnly ? 'SCHEMA ONLY' : 'SCHEMA + DATA') . "\n";
echo "Tables total: {$result['summary']['tables_total']}\n";
echo "Tables created: {$result['summary']['tables_created']}\n";
echo "Rows copied: {$result['summary']['rows_copied']}\n";
echo "Row errors: {$result['summary']['row_errors']}\n";
if ($result['error'] !== '') {
    echo "Error: {$result['error']}\n";
    exit(1);
}

if ((int)$result['summary']['row_errors'] > 0) {
    echo "Warning: Some rows failed to copy. Re-run with --json to inspect samples.\n";
}

echo "Done.\n";
exit(0);
