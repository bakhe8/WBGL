<?php
declare(strict_types=1);

require_once __DIR__ . '/../Support/autoload.php';

/**
 * WBGL Release Gate
 *
 * Enforces that required governance artifacts are present and in PASS state.
 * Usage:
 *   php app/Scripts/release-gate.php
 *   php app/Scripts/release-gate.php --integrity=storage/logs/data-integrity-report.json --drift=storage/logs/permissions-drift-report.json
 */

function wbgl_parse_cli_options(array $argv): array
{
    $options = [
        'integrity' => 'storage/logs/data-integrity-report.json',
        'drift' => 'storage/logs/permissions-drift-report.json',
    ];

    foreach ($argv as $arg) {
        if (!is_string($arg) || $arg === '' || $arg[0] !== '-') {
            continue;
        }
        if (str_starts_with($arg, '--integrity=')) {
            $options['integrity'] = trim(substr($arg, strlen('--integrity=')));
            continue;
        }
        if (str_starts_with($arg, '--drift=')) {
            $options['drift'] = trim(substr($arg, strlen('--drift=')));
            continue;
        }
    }

    return $options;
}

function wbgl_load_report(string $path, string $label): array
{
    if ($path === '' || !is_file($path)) {
        throw new RuntimeException($label . " report file is missing: {$path}");
    }

    $raw = (string)file_get_contents($path);
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException($label . " report is not valid JSON: {$path}");
    }

    return $decoded;
}

function wbgl_assert_report_status(array $report, string $label): void
{
    $status = strtolower(trim((string)($report['status'] ?? '')));
    if ($label === 'Data integrity') {
        $failViolations = (int)($report['summary']['fail_violations'] ?? -1);
        if ($failViolations > 0) {
            throw new RuntimeException($label . " report has fail violations (count={$failViolations}).");
        }
        if (!in_array($status, ['pass', 'warn'], true)) {
            throw new RuntimeException($label . " report status is invalid (status={$status}).");
        }
        return;
    }

    if ($status !== 'pass') {
        throw new RuntimeException($label . " report status is not pass (status={$status}).");
    }
}

try {
    $options = wbgl_parse_cli_options($argv);

    $integrity = wbgl_load_report((string)$options['integrity'], 'Data integrity');
    $drift = wbgl_load_report((string)$options['drift'], 'Permissions drift');

    wbgl_assert_report_status($integrity, 'Data integrity');
    wbgl_assert_report_status($drift, 'Permissions drift');

    echo "WBGL release gate passed." . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'WBGL release gate failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
