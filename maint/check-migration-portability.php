<?php
declare(strict_types=1);

/**
 * Check SQL migration portability from SQLite-oriented syntax to PostgreSQL.
 *
 * Usage:
 *   php maint/check-migration-portability.php
 *   php maint/check-migration-portability.php --json
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\MigrationSqlAdapter;

/**
 * @return array<string,mixed>
 */
function wbglPortabilityAnalyzeMigration(string $path): array
{
    $rawContent = (string)file_get_contents($path);
    $content = MigrationSqlAdapter::normalizeForDriver($rawContent, 'pgsql');
    $name = basename($path);

    $patterns = [
        [
            'code' => 'sqlite_autoincrement',
            'regex' => '/\bAUTOINCREMENT\b/i',
            'severity' => 'high',
            'message' => 'Still contains AUTOINCREMENT after conversion; manual rewrite required.',
        ],
        [
            'code' => 'sqlite_insert_or_ignore',
            'regex' => '/\bINSERT\s+OR\s+IGNORE\b/i',
            'severity' => 'medium',
            'message' => 'Still contains INSERT OR IGNORE after conversion; manual rewrite required.',
        ],
        [
            'code' => 'sqlite_datetime_type',
            'regex' => '/\bDATETIME\b/i',
            'severity' => 'high',
            'message' => 'Still contains DATETIME after conversion; manual rewrite required.',
        ],
    ];

    $issues = [];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern['regex'], $content) === 1) {
            $issues[] = [
                'code' => $pattern['code'],
                'severity' => $pattern['severity'],
                'message' => $pattern['message'],
            ];
        }
    }

    $hasHigh = false;
    foreach ($issues as $issue) {
        if (($issue['severity'] ?? '') === 'high') {
            $hasHigh = true;
            break;
        }
    }

    return [
        'migration' => $name,
        'transform_applied' => $rawContent !== $content,
        'issues' => $issues,
        'portable' => !$hasHigh,
        'high_blockers' => array_values(array_filter(
            $issues,
            static fn(array $issue): bool => (string)($issue['severity'] ?? '') === 'high'
        )),
    ];
}

function wbglPortabilityHasFlag(string $name, array $argv): bool
{
    return in_array($name, $argv, true);
}

$asJson = wbglPortabilityHasFlag('--json', $argv ?? []);
$migrationFiles = glob(base_path('database/migrations/*.sql')) ?: [];
sort($migrationFiles, SORT_STRING);

$results = [];
$highBlockers = 0;
$mediumIssues = 0;

foreach ($migrationFiles as $path) {
    $result = wbglPortabilityAnalyzeMigration($path);
    $results[] = $result;

    $highBlockers += count($result['high_blockers'] ?? []);
    foreach (($result['issues'] ?? []) as $issue) {
        if (($issue['severity'] ?? '') === 'medium') {
            $mediumIssues++;
        }
    }
}

$portableMigrations = 0;
foreach ($results as $result) {
    if (($result['portable'] ?? false) === true) {
        $portableMigrations++;
    }
}

$summary = [
    'generated_at' => date('c'),
    'total_migrations' => count($results),
    'portable_migrations' => $portableMigrations,
    'high_blockers' => $highBlockers,
    'medium_issues' => $mediumIssues,
    'ready_for_pgsql_cutover' => $highBlockers === 0,
];

$report = [
    'summary' => $summary,
    'results' => $results,
];

$reportDir = base_path('storage/database/cutover');
if (!is_dir($reportDir)) {
    @mkdir($reportDir, 0777, true);
}
$reportPath = $reportDir . '/migration_portability_report.json';
@file_put_contents(
    $reportPath,
    json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL
);

if ($asJson) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($summary['ready_for_pgsql_cutover'] ? 0 : 1);
}

echo "WBGL Migration Portability Check\n";
echo str_repeat('-', 72) . "\n";
echo "Total migrations     : " . $summary['total_migrations'] . "\n";
echo "Portable migrations  : " . $summary['portable_migrations'] . "\n";
echo "High blockers        : " . $summary['high_blockers'] . "\n";
echo "Medium issues        : " . $summary['medium_issues'] . "\n";
echo "Ready for PG cutover : " . ($summary['ready_for_pgsql_cutover'] ? 'yes' : 'no') . "\n";
echo "Report file          : " . $reportPath . "\n";

if (!$summary['ready_for_pgsql_cutover']) {
    echo "\nTop blockers:\n";
    foreach ($results as $result) {
        $blockers = $result['high_blockers'] ?? [];
        if (empty($blockers)) {
            continue;
        }
        echo "- " . (string)$result['migration'] . "\n";
        foreach ($blockers as $issue) {
            echo "  * " . (string)($issue['code'] ?? 'issue') . ": " . (string)($issue['message'] ?? '') . "\n";
        }
    }
}

exit($summary['ready_for_pgsql_cutover'] ? 0 : 1);
