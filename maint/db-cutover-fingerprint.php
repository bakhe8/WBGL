<?php
declare(strict_types=1);

/**
 * Generate table-level rowcount/schema fingerprint for cutover verification.
 *
 * Usage:
 *   php maint/db-cutover-fingerprint.php
 *   php maint/db-cutover-fingerprint.php --json
 *   php maint/db-cutover-fingerprint.php --output=storage/database/cutover/source_fingerprint.json
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;

function wbglFingerprintHasFlag(string $name, array $argv): bool
{
    return in_array($name, $argv, true);
}

function wbglFingerprintOption(string $prefix, array $argv): ?string
{
    foreach ($argv as $arg) {
        if (!str_starts_with((string)$arg, $prefix)) {
            continue;
        }
        return (string)substr((string)$arg, strlen($prefix));
    }
    return null;
}

/**
 * @return string[]
 */
function wbglFingerprintListTables(PDO $db): array
{
    $stmt = $db->query(
        "SELECT table_name
         FROM information_schema.tables
         WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
         ORDER BY table_name ASC"
    );
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    return array_values(array_map(static fn(array $row): string => (string)$row['table_name'], $rows));
}

/**
 * @return string[]
 */
function wbglFingerprintTableColumns(PDO $db, string $table): array
{
    $stmt = $db->prepare(
        "SELECT column_name
         FROM information_schema.columns
         WHERE table_schema = 'public' AND table_name = :table
         ORDER BY ordinal_position ASC"
    );
    $stmt->execute(['table' => $table]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return array_values(array_map(static fn(array $row): string => (string)$row['column_name'], $rows));
}

function wbglFingerprintTableCount(PDO $db, string $table): int
{
    $stmt = $db->query('SELECT COUNT(*) FROM ' . $table);
    return $stmt ? (int)$stmt->fetchColumn() : 0;
}

$asJson = wbglFingerprintHasFlag('--json', $argv ?? []);
$customOutput = wbglFingerprintOption('--output=', $argv ?? []);

try {
    $db = Database::connect();
    $driver = Database::currentDriver();
    $config = Database::configurationSummary();

    $tables = wbglFingerprintListTables($db);
    $tableStats = [];

    foreach ($tables as $table) {
        $columns = wbglFingerprintTableColumns($db, $table);
        $rowCount = wbglFingerprintTableCount($db, $table);
        $schemaHash = hash('sha256', json_encode($columns, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $tableStats[] = [
            'table' => $table,
            'row_count' => $rowCount,
            'columns' => $columns,
            'schema_hash' => $schemaHash,
        ];
    }

    $parts = [];
    foreach ($tableStats as $row) {
        $parts[] = implode(':', [
            (string)$row['table'],
            (string)$row['row_count'],
            (string)$row['schema_hash'],
        ]);
    }
    sort($parts, SORT_STRING);
    $fingerprint = hash('sha256', implode('|', $parts));

    $payload = [
        'generated_at' => date('c'),
        'driver' => $driver,
        'configuration' => $config,
        'tables_count' => count($tableStats),
        'fingerprint' => $fingerprint,
        'tables' => $tableStats,
    ];

    $cutoverDir = base_path('storage/database/cutover');
    if (!is_dir($cutoverDir)) {
        @mkdir($cutoverDir, 0777, true);
    }

    $defaultPath = $cutoverDir . '/fingerprint_' . $driver . '_' . date('Ymd_His') . '.json';
    $outputPath = $defaultPath;
    if (is_string($customOutput) && trim($customOutput) !== '') {
        $candidate = trim($customOutput);
        $isAbsolute = preg_match('/^[A-Za-z]:[\\\\\\/]/', $candidate) === 1 || str_starts_with($candidate, '/');
        $outputPath = $isAbsolute ? $candidate : base_path($candidate);
        $outDir = dirname($outputPath);
        if (!is_dir($outDir)) {
            @mkdir($outDir, 0777, true);
        }
    }

    file_put_contents(
        $outputPath,
        json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL
    );

    $latestPath = $cutoverDir . '/fingerprint_' . $driver . '_latest.json';
    file_put_contents(
        $latestPath,
        json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL
    );

    if ($asJson) {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        echo "WBGL DB Cutover Fingerprint\n";
        echo str_repeat('-', 72) . "\n";
        echo "Driver        : {$driver}\n";
        echo "Tables        : " . count($tableStats) . "\n";
        echo "Fingerprint   : {$fingerprint}\n";
        echo "Output        : {$outputPath}\n";
        echo "Latest pointer: {$latestPath}\n";
    }

    exit(0);
} catch (Throwable $e) {
    if ($asJson) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        echo "WBGL DB Cutover Fingerprint failed: " . $e->getMessage() . "\n";
    }
    exit(1);
}
