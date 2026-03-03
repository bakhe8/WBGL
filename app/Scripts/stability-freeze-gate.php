<?php
declare(strict_types=1);

/**
 * Enforce phase-1 stability freeze on pull requests.
 *
 * Inputs (from CI env):
 * - WBGL_CHANGED_FILES: multi-line list of changed files
 * - WBGL_CHANGED_STATUSES: multi-line output of `git diff --name-status`
 * - WBGL_PR_BODY: pull request body text
 */

require_once dirname(__DIR__) . '/Support/autoload.php';

use App\Support\StabilityFreezeGate;

$projectRoot = dirname(__DIR__, 2);
$manifestPath = $projectRoot . '/Docs/WBGL-EXECUTION-SEQUENCE-AR.json';
$statePath = $projectRoot . '/Docs/WBGL-EXECUTION-STATE-AR.json';

$manifest = loadJsonFile($manifestPath, 'manifest');
$state = loadJsonFile($statePath, 'state');

$activeStep = detectActiveStep($state);
$changedFiles = readLinesFromEnv('WBGL_CHANGED_FILES');
$changedStatuses = readLinesFromEnv('WBGL_CHANGED_STATUSES');
$prBody = getenv('WBGL_PR_BODY');
$prBody = is_string($prBody) ? $prBody : '';

$gate = StabilityFreezeGate::fromManifest($manifest);
$result = $gate->evaluate($changedFiles, $changedStatuses, $prBody, $activeStep);

if (!$result['enforced']) {
    $label = $result['active_step'] ?? 'none';
    echo 'Stability freeze gate skipped: active step is ' . $label . '.' . PHP_EOL;
    exit(0);
}

if ($result['errors'] !== []) {
    echo 'Stability freeze gate failed.' . PHP_EOL;
    foreach ($result['errors'] as $error) {
        echo '- ' . $error . PHP_EOL;
    }
    exit(1);
}

echo 'Stability freeze gate passed for active step ' . ($result['active_step'] ?? 'P1') . '.' . PHP_EOL;
if ($result['sensitive_changes']) {
    echo 'STABILITY-REFS: ' . implode(', ', $result['stability_refs']) . PHP_EOL;
}
exit(0);

/**
 * @return array<string, mixed>
 */
function loadJsonFile(string $path, string $label): array
{
    if (!is_file($path)) {
        fail('Missing ' . $label . ' file: ' . $path);
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        fail('Unable to read ' . $label . ' file: ' . $path);
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        fail('Invalid JSON in ' . $label . ' file: ' . $path);
    }
    return $decoded;
}

/**
 * @param array<string, mixed> $state
 */
function detectActiveStep(array $state): ?string
{
    $steps = $state['steps'] ?? null;
    if (!is_array($steps)) {
        return null;
    }

    foreach ($steps as $stepId => $row) {
        if (!is_array($row)) {
            continue;
        }
        if (($row['status'] ?? null) === 'in_progress') {
            return (string)$stepId;
        }
    }

    return null;
}

/**
 * @return string[]
 */
function readLinesFromEnv(string $key): array
{
    $value = getenv($key);
    if (!is_string($value) || trim($value) === '') {
        return [];
    }

    $lines = preg_split('/\R/', $value) ?: [];
    $out = [];
    foreach ($lines as $line) {
        $trimmed = trim((string)$line);
        if ($trimmed === '') {
            continue;
        }
        $out[] = $trimmed;
    }
    return $out;
}

function fail(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}
