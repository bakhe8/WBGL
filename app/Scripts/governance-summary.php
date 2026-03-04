<?php
declare(strict_types=1);

/**
 * WBGL Governance Summary Generator
 *
 * Usage:
 *   php app/Scripts/governance-summary.php
 *   php app/Scripts/governance-summary.php --drift=storage/logs/permissions-drift-report.json --integrity=storage/logs/data-integrity-report.json --output-md=storage/logs/governance-summary.md
 */

/** @var array<string,string|false>|false $options */
$options = getopt('', ['drift::', 'integrity::', 'output-md::']);

$defaultDrift = dirname(__DIR__, 2) . '/storage/logs/permissions-drift-report.json';
$defaultIntegrity = dirname(__DIR__, 2) . '/storage/logs/data-integrity-report.json';
$defaultOutput = dirname(__DIR__, 2) . '/storage/logs/governance-summary.md';

$driftPath = is_array($options) && isset($options['drift']) && is_string($options['drift'])
    ? $options['drift']
    : $defaultDrift;
$integrityPath = is_array($options) && isset($options['integrity']) && is_string($options['integrity'])
    ? $options['integrity']
    : $defaultIntegrity;
$outputPath = is_array($options) && isset($options['output-md']) && is_string($options['output-md'])
    ? $options['output-md']
    : $defaultOutput;

/**
 * @return array<string,mixed>
 */
function wbgl_read_json(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('Missing JSON file: ' . $path);
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException('Failed to read JSON file: ' . $path);
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid JSON structure in: ' . $path);
    }
    return $decoded;
}

try {
    $drift = wbgl_read_json($driftPath);
    $integrity = wbgl_read_json($integrityPath);

    $driftStatus = strtoupper((string)($drift['status'] ?? 'UNKNOWN'));
    $integrityStatus = strtoupper((string)($integrity['status'] ?? 'UNKNOWN'));
    $driftCounts = is_array($drift['counts'] ?? null) ? $drift['counts'] : [];
    $integritySummary = is_array($integrity['summary'] ?? null) ? $integrity['summary'] : [];

    $severity = 'PASS';
    if ($driftStatus === 'FAIL' || $integrityStatus === 'FAIL') {
        $severity = 'FAIL';
    } elseif ($driftStatus === 'WARN' || $integrityStatus === 'WARN') {
        $severity = 'WARN';
    }

    $lines = [];
    $lines[] = '# WBGL Governance Summary';
    $lines[] = '';
    $lines[] = '- Generated At: `' . gmdate('c') . '`';
    $lines[] = '- Overall: **' . $severity . '**';
    $lines[] = '- Drift Status: `' . $driftStatus . '`';
    $lines[] = '- Integrity Status: `' . $integrityStatus . '`';
    $lines[] = '';
    $lines[] = '## Permissions Drift';
    $lines[] = '';
    $lines[] = '| Metric | Value |';
    $lines[] = '|---|---:|';
    $lines[] = '| Missing in DB | ' . (int)($driftCounts['missing_in_db'] ?? 0) . ' |';
    $lines[] = '| Unknown in code | ' . (int)($driftCounts['unknown_in_code'] ?? 0) . ' |';
    $lines[] = '| Duplicate slugs | ' . (int)($driftCounts['duplicate_slugs'] ?? 0) . ' |';
    $lines[] = '| Orphan role_permissions | ' . (int)($driftCounts['orphan_role_permissions'] ?? 0) . ' |';
    $lines[] = '| Critical contract mismatches | ' . (int)($driftCounts['critical_contract_mismatches'] ?? 0) . ' |';
    $lines[] = '';
    $lines[] = '## Data Integrity';
    $lines[] = '';
    $lines[] = '| Metric | Value |';
    $lines[] = '|---|---:|';
    $lines[] = '| Checks total | ' . (int)($integritySummary['checks_total'] ?? 0) . ' |';
    $lines[] = '| Fail violations | ' . (int)($integritySummary['fail_violations'] ?? 0) . ' |';
    $lines[] = '| Warn violations | ' . (int)($integritySummary['warn_violations'] ?? 0) . ' |';
    $lines[] = '';
    $lines[] = '## Inputs';
    $lines[] = '';
    $lines[] = '- Drift JSON: `' . $driftPath . '`';
    $lines[] = '- Integrity JSON: `' . $integrityPath . '`';
    $lines[] = '';

    $outputDir = dirname($outputPath);
    if (!is_dir($outputDir) && !mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
        throw new RuntimeException('Failed to create output directory: ' . $outputDir);
    }

    file_put_contents($outputPath, implode(PHP_EOL, $lines) . PHP_EOL);

    echo 'WBGL Governance Summary' . PHP_EOL;
    echo 'Overall: ' . $severity . PHP_EOL;
    echo 'Drift status: ' . $driftStatus . PHP_EOL;
    echo 'Integrity status: ' . $integrityStatus . PHP_EOL;
    echo 'Output: ' . $outputPath . PHP_EOL;
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'governance-summary crashed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
