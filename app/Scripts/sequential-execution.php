<?php
declare(strict_types=1);

/**
 * Mandatory sequential execution controller for WBGL.
 *
 * Usage:
 *   php app/Scripts/sequential-execution.php status
 *   php app/Scripts/sequential-execution.php start
 *   php app/Scripts/sequential-execution.php complete P1-01 --evidence="..." --refs="Docs/a.md,api/b.php"
 *   php app/Scripts/sequential-execution.php guard
 *   php app/Scripts/sequential-execution.php help
 */

const STATE_PENDING = 'pending';
const STATE_IN_PROGRESS = 'in_progress';
const STATE_DONE = 'done';
const STATE_BLOCKED = 'blocked';

$projectRoot = dirname(__DIR__, 2);
$manifestPath = $projectRoot . '/Docs/WBGL-EXECUTION-SEQUENCE-AR.json';
$statePath = $projectRoot . '/Docs/WBGL-EXECUTION-STATE-AR.json';
$logPath = $projectRoot . '/Docs/WBGL-EXECUTION-LOG-AR.md';

$command = $argv[1] ?? 'status';
[$positionals, $options] = parseArgs(array_slice($argv, 2));

$manifest = loadManifest($manifestPath);
$orderedSteps = flattenSteps($manifest);
$stepMap = [];
foreach ($orderedSteps as $step) {
    $stepMap[$step['id']] = $step;
}

$allowInitState = $command !== 'guard';
$state = loadState($statePath, $manifest, $orderedSteps, $allowInitState);
[$integrityOk, $integrityMessage] = validateStateIntegrity($state, $orderedSteps, false);
if (!$integrityOk) {
    fail($integrityMessage);
}

switch ($command) {
    case 'status':
        printStatus($manifest, $state, $orderedSteps);
        exit(0);

    case 'start':
        $inProgress = findStepByStatus($state, STATE_IN_PROGRESS);
        if ($inProgress !== null) {
            echo "Execution already active on step: {$inProgress}" . PHP_EOL;
            exit(0);
        }

        $next = findFirstByStatus($state, $orderedSteps, STATE_PENDING);
        if ($next === null) {
            echo "No pending steps. Sequence already completed." . PHP_EOL;
            exit(0);
        }
        $state['steps'][$next]['status'] = STATE_IN_PROGRESS;
        $state['steps'][$next]['started_at'] = nowUtc();
        $state['updated_at'] = nowUtc();
        saveState($statePath, $state);
        echo "Started step {$next}." . PHP_EOL;
        exit(0);

    case 'complete':
        $stepId = trim((string)($positionals[0] ?? ''));
        if ($stepId === '') {
            fail('Missing step id. Example: complete P1-01 --evidence="..." --refs="Docs/x.md,api/y.php"');
        }
        if (!isset($stepMap[$stepId])) {
            fail("Unknown step id: {$stepId}");
        }

        $current = findStepByStatus($state, STATE_IN_PROGRESS);
        if ($current === null) {
            fail('No in-progress step. Run start first.');
        }
        if ($current !== $stepId) {
            fail("Sequential lock active. Current step is {$current}, not {$stepId}.");
        }

        $evidence = trim((string)($options['evidence'] ?? ''));
        if (mb_strlen($evidence) < 10) {
            fail('Evidence is required and must be at least 10 chars.');
        }
        $refs = parseRefs((string)($options['refs'] ?? ''));
        if ($refs === []) {
            fail('Refs are required. Example: --refs="Docs/INDEX-DOCS-AR.md,api/get-record.php"');
        }
        assertRefsExist($refs, $projectRoot);

        $state['steps'][$stepId]['status'] = STATE_DONE;
        $state['steps'][$stepId]['completed_at'] = nowUtc();
        $state['steps'][$stepId]['blocked_at'] = null;
        $state['steps'][$stepId]['evidence'] = $evidence;
        $state['steps'][$stepId]['refs'] = $refs;
        $state['steps'][$stepId]['note'] = null;

        $nextPending = findFirstByStatus($state, $orderedSteps, STATE_PENDING);
        if ($nextPending !== null) {
            $state['steps'][$nextPending]['status'] = STATE_IN_PROGRESS;
            if ($state['steps'][$nextPending]['started_at'] === null) {
                $state['steps'][$nextPending]['started_at'] = nowUtc();
            }
        }

        $state['updated_at'] = nowUtc();
        saveState($statePath, $state);
        appendLog($logPath, $stepMap[$stepId], $evidence, $refs, $nextPending);

        echo "Step {$stepId} marked as done." . PHP_EOL;
        if ($nextPending !== null) {
            echo "Next step started: {$nextPending}" . PHP_EOL;
        } else {
            echo "All steps are done. Sequence complete." . PHP_EOL;
        }
        exit(0);

    case 'block':
        $stepId = trim((string)($positionals[0] ?? ''));
        $reason = trim((string)($options['reason'] ?? ''));
        if ($stepId === '') {
            fail('Missing step id. Example: block P2-01 --reason="Dependency issue"');
        }
        if ($reason === '') {
            fail('Missing block reason. Example: --reason="Dependency issue"');
        }
        if (!isset($stepMap[$stepId])) {
            fail("Unknown step id: {$stepId}");
        }
        $current = findStepByStatus($state, STATE_IN_PROGRESS);
        if ($current !== $stepId) {
            fail("Only current in-progress step can be blocked. Current: " . ($current ?? 'none'));
        }
        $state['steps'][$stepId]['status'] = STATE_BLOCKED;
        $state['steps'][$stepId]['blocked_at'] = nowUtc();
        $state['steps'][$stepId]['note'] = $reason;
        $state['updated_at'] = nowUtc();
        saveState($statePath, $state);
        echo "Step {$stepId} is now blocked." . PHP_EOL;
        exit(0);

    case 'unblock':
        $stepId = trim((string)($positionals[0] ?? ''));
        if ($stepId === '') {
            fail('Missing step id. Example: unblock P2-01');
        }
        if (!isset($stepMap[$stepId])) {
            fail("Unknown step id: {$stepId}");
        }
        $inProgress = findStepByStatus($state, STATE_IN_PROGRESS);
        if ($inProgress !== null) {
            fail("Cannot unblock while another step is in progress ({$inProgress}).");
        }
        if ($state['steps'][$stepId]['status'] !== STATE_BLOCKED) {
            fail("Step {$stepId} is not blocked.");
        }
        $state['steps'][$stepId]['status'] = STATE_IN_PROGRESS;
        $state['steps'][$stepId]['note'] = null;
        if ($state['steps'][$stepId]['started_at'] === null) {
            $state['steps'][$stepId]['started_at'] = nowUtc();
        }
        $state['updated_at'] = nowUtc();
        saveState($statePath, $state);
        echo "Step {$stepId} moved back to in_progress." . PHP_EOL;
        exit(0);

    case 'guard':
        [$ok, $message] = validateStateIntegrity($state, $orderedSteps, true);
        if (!$ok) {
            fail("Guard failed: {$message}");
        }
        foreach ($orderedSteps as $step) {
            $id = $step['id'];
            $row = $state['steps'][$id];
            if ($row['status'] === STATE_DONE) {
                $evidence = trim((string)($row['evidence'] ?? ''));
                $refs = $row['refs'] ?? [];
                if ($evidence === '' || !is_array($refs) || count($refs) === 0) {
                    fail("Guard failed: step {$id} is done without evidence/refs.");
                }
                assertRefsExist($refs, $projectRoot);
            }
        }
        echo 'Guard passed: sequential state is valid.' . PHP_EOL;
        exit(0);

    case 'help':
    default:
        printHelp();
        exit($command === 'help' ? 0 : 1);
}

function printHelp(): void
{
    $lines = [
        'WBGL Sequential Execution CLI',
        '',
        'Commands:',
        '  status',
        '  start',
        '  complete <STEP_ID> --evidence="..." --refs="Docs/a.md,api/b.php"',
        '  block <STEP_ID> --reason="..."',
        '  unblock <STEP_ID>',
        '  guard',
        '  help',
    ];
    echo implode(PHP_EOL, $lines) . PHP_EOL;
}

function parseArgs(array $args): array
{
    $positionals = [];
    $options = [];
    $count = count($args);
    for ($i = 0; $i < $count; $i++) {
        $arg = (string)$args[$i];
        if (!str_starts_with($arg, '--')) {
            $positionals[] = $arg;
            continue;
        }

        $eqPos = strpos($arg, '=');
        if ($eqPos !== false) {
            $key = substr($arg, 2, $eqPos - 2);
            $value = substr($arg, $eqPos + 1);
            $options[$key] = $value;
            continue;
        }

        $key = substr($arg, 2);
        $next = $args[$i + 1] ?? null;
        if ($next !== null && !str_starts_with((string)$next, '--')) {
            $options[$key] = (string)$next;
            $i++;
        } else {
            $options[$key] = '1';
        }
    }

    return [$positionals, $options];
}

function loadManifest(string $path): array
{
    if (!is_file($path)) {
        fail("Manifest not found: {$path}");
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        fail("Unable to read manifest: {$path}");
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        fail("Invalid manifest JSON: {$path}");
    }
    if (!isset($data['phases']) || !is_array($data['phases']) || count($data['phases']) === 0) {
        fail('Manifest must contain non-empty phases.');
    }
    return $data;
}

function flattenSteps(array $manifest): array
{
    $steps = [];
    $seen = [];
    foreach ($manifest['phases'] as $phase) {
        $phaseId = (string)($phase['id'] ?? '');
        $phaseTitle = (string)($phase['title_ar'] ?? $phaseId);
        $phaseSteps = $phase['steps'] ?? null;
        if ($phaseId === '' || !is_array($phaseSteps) || count($phaseSteps) === 0) {
            fail("Invalid phase definition in manifest: {$phaseId}");
        }
        foreach ($phaseSteps as $step) {
            $id = trim((string)($step['id'] ?? ''));
            if ($id === '') {
                fail("Step id missing in phase {$phaseId}");
            }
            if (isset($seen[$id])) {
                fail("Duplicate step id detected: {$id}");
            }
            $seen[$id] = true;
            $steps[] = [
                'id' => $id,
                'title_ar' => (string)($step['title_ar'] ?? $id),
                'coverage_ids' => is_array($step['coverage_ids'] ?? null) ? array_values($step['coverage_ids']) : [],
                'phase_id' => $phaseId,
                'phase_title_ar' => $phaseTitle,
            ];
        }
    }
    return $steps;
}

function loadState(string $path, array $manifest, array $orderedSteps, bool $allowInit): array
{
    if (!is_file($path)) {
        if (!$allowInit) {
            fail("State not found: {$path}. Run status/start first.");
        }
        $state = initializeState($manifest, $orderedSteps);
        saveState($path, $state);
        return $state;
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        fail("Unable to read state: {$path}");
    }
    $state = json_decode($raw, true);
    if (!is_array($state)) {
        fail("Invalid state JSON: {$path}");
    }

    if (!isset($state['steps']) || !is_array($state['steps'])) {
        $state['steps'] = [];
    }

    $state['manifest_file'] = 'Docs/WBGL-EXECUTION-SEQUENCE-AR.json';
    $state['manifest_version'] = (string)($manifest['version'] ?? 'unknown');
    $state['mode'] = 'mandatory_sequential';

    $knownIds = [];
    $mutated = false;
    foreach ($orderedSteps as $step) {
        $id = $step['id'];
        $knownIds[$id] = true;
        if (!isset($state['steps'][$id]) || !is_array($state['steps'][$id])) {
            $state['steps'][$id] = newStepState(STATE_PENDING);
            $mutated = true;
        } else {
            $normalized = normalizeStepState($state['steps'][$id]);
            if ($normalized !== $state['steps'][$id]) {
                $mutated = true;
            }
            $state['steps'][$id] = $normalized;
        }
    }

    foreach (array_keys($state['steps']) as $existingId) {
        if (!isset($knownIds[$existingId])) {
            unset($state['steps'][$existingId]);
            $mutated = true;
        }
    }

    if (findStepByStatus($state, STATE_IN_PROGRESS) === null && findFirstByStatus($state, $orderedSteps, STATE_PENDING) !== null) {
        $firstPending = findFirstByStatus($state, $orderedSteps, STATE_PENDING);
        if ($firstPending !== null) {
            $state['steps'][$firstPending]['status'] = STATE_IN_PROGRESS;
            if ($state['steps'][$firstPending]['started_at'] === null) {
                $state['steps'][$firstPending]['started_at'] = nowUtc();
            }
            $mutated = true;
        }
    }

    if ($mutated || !isset($state['updated_at'])) {
        $state['updated_at'] = nowUtc();
    }
    saveState($path, $state);
    return $state;
}

function initializeState(array $manifest, array $orderedSteps): array
{
    $steps = [];
    foreach ($orderedSteps as $step) {
        $steps[$step['id']] = newStepState(STATE_PENDING);
    }
    if ($orderedSteps !== []) {
        $firstId = $orderedSteps[0]['id'];
        $steps[$firstId]['status'] = STATE_IN_PROGRESS;
        $steps[$firstId]['started_at'] = nowUtc();
    }
    return [
        'manifest_file' => 'Docs/WBGL-EXECUTION-SEQUENCE-AR.json',
        'manifest_version' => (string)($manifest['version'] ?? 'unknown'),
        'mode' => 'mandatory_sequential',
        'updated_at' => nowUtc(),
        'steps' => $steps,
    ];
}

function newStepState(string $status): array
{
    return [
        'status' => $status,
        'started_at' => null,
        'completed_at' => null,
        'blocked_at' => null,
        'evidence' => null,
        'refs' => [],
        'note' => null,
    ];
}

function normalizeStepState(array $stepState): array
{
    $status = (string)($stepState['status'] ?? STATE_PENDING);
    if (!in_array($status, [STATE_PENDING, STATE_IN_PROGRESS, STATE_DONE, STATE_BLOCKED], true)) {
        $status = STATE_PENDING;
    }
    return [
        'status' => $status,
        'started_at' => $stepState['started_at'] ?? null,
        'completed_at' => $stepState['completed_at'] ?? null,
        'blocked_at' => $stepState['blocked_at'] ?? null,
        'evidence' => $stepState['evidence'] ?? null,
        'refs' => is_array($stepState['refs'] ?? null) ? array_values($stepState['refs']) : [],
        'note' => $stepState['note'] ?? null,
    ];
}

function validateStateIntegrity(array $state, array $orderedSteps, bool $strictActive): array
{
    $inProgressCount = 0;
    $seenNonDone = false;
    $hasPending = false;
    foreach ($orderedSteps as $step) {
        $id = $step['id'];
        if (!isset($state['steps'][$id])) {
            return [false, "Missing state row for step {$id}."];
        }
        $status = (string)$state['steps'][$id]['status'];
        if (!in_array($status, [STATE_PENDING, STATE_IN_PROGRESS, STATE_DONE, STATE_BLOCKED], true)) {
            return [false, "Invalid status '{$status}' for step {$id}."];
        }

        if ($status === STATE_IN_PROGRESS) {
            $inProgressCount++;
        }
        if ($status === STATE_PENDING || $status === STATE_BLOCKED || $status === STATE_IN_PROGRESS) {
            $seenNonDone = true;
        }
        if ($status === STATE_DONE && $seenNonDone) {
            return [false, "Sequential violation: {$id} is done while an earlier step is not done."];
        }
        if ($status === STATE_PENDING) {
            $hasPending = true;
        }
    }

    if ($inProgressCount > 1) {
        return [false, 'Sequential violation: multiple in-progress steps detected.'];
    }
    if ($strictActive && $hasPending && $inProgressCount === 0) {
        return [false, 'No active in-progress step while pending work exists.'];
    }

    return [true, 'ok'];
}

function findStepByStatus(array $state, string $target): ?string
{
    foreach ($state['steps'] as $id => $row) {
        if (($row['status'] ?? null) === $target) {
            return (string)$id;
        }
    }
    return null;
}

function findFirstByStatus(array $state, array $orderedSteps, string $target): ?string
{
    foreach ($orderedSteps as $step) {
        $id = $step['id'];
        if (($state['steps'][$id]['status'] ?? null) === $target) {
            return $id;
        }
    }
    return null;
}

function parseRefs(string $raw): array
{
    $raw = str_replace(';', ',', $raw);
    $parts = array_map('trim', explode(',', $raw));
    $out = [];
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        $out[] = str_replace('\\', '/', $part);
    }
    return array_values(array_unique($out));
}

function assertRefsExist(array $refs, string $projectRoot): void
{
    foreach ($refs as $ref) {
        $normalized = str_replace('\\', '/', $ref);
        if (preg_match('/^[A-Za-z]:\//', $normalized) === 1 || str_starts_with($normalized, '/')) {
            $fullPath = $normalized;
        } else {
            $fullPath = $projectRoot . '/' . ltrim($normalized, '/');
        }
        if (!file_exists($fullPath)) {
            fail("Referenced file does not exist: {$ref}");
        }
    }
}

function saveState(string $path, array $state): void
{
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        fail('Failed to encode state JSON.');
    }

    $tmpPath = $path . '.tmp';
    if (file_put_contents($tmpPath, $json . PHP_EOL) === false) {
        fail("Failed to write temp state file: {$tmpPath}");
    }
    if (!@rename($tmpPath, $path)) {
        @unlink($tmpPath);
        fail("Failed to save state file: {$path}");
    }
}

function appendLog(string $logPath, array $step, string $evidence, array $refs, ?string $nextStepId): void
{
    if (!is_file($logPath)) {
        $header = [
            '# سجل التنفيذ المتتابع — WBGL',
            '',
            'هذا السجل يُحدّث تلقائيا عبر `app/Scripts/sequential-execution.php`.',
            '',
            '---',
            '',
        ];
        file_put_contents($logPath, implode(PHP_EOL, $header));
    }

    $lines = [];
    $lines[] = '## ' . nowUtc() . ' | ' . $step['id'];
    $lines[] = '- المرحلة: ' . $step['phase_id'] . ' — ' . $step['phase_title_ar'];
    $lines[] = '- المهمة: ' . $step['title_ar'];
    $coverage = $step['coverage_ids'] === [] ? '-' : implode(', ', $step['coverage_ids']);
    $lines[] = '- الربط المرجعي: ' . $coverage;
    $lines[] = '- الدليل: ' . $evidence;
    $lines[] = '- الملفات المرجعية:';
    foreach ($refs as $ref) {
        $lines[] = '  - ' . $ref;
    }
    if ($nextStepId !== null) {
        $lines[] = '- الخطوة التالية: ' . $nextStepId;
    } else {
        $lines[] = '- الحالة: جميع الخطوات مكتملة.';
    }
    $lines[] = '';
    file_put_contents($logPath, implode(PHP_EOL, $lines) . PHP_EOL, FILE_APPEND);
}

function printStatus(array $manifest, array $state, array $orderedSteps): void
{
    $total = count($orderedSteps);
    $done = 0;
    $blocked = 0;
    $inProgress = null;
    foreach ($orderedSteps as $step) {
        $id = $step['id'];
        $status = $state['steps'][$id]['status'];
        if ($status === STATE_DONE) {
            $done++;
        } elseif ($status === STATE_BLOCKED) {
            $blocked++;
        } elseif ($status === STATE_IN_PROGRESS) {
            $inProgress = $id;
        }
    }

    echo 'WBGL Sequential Execution Status' . PHP_EOL;
    echo 'Manifest version: ' . (string)($manifest['version'] ?? 'unknown') . PHP_EOL;
    echo 'Mode: mandatory_sequential' . PHP_EOL;
    echo 'Progress: ' . $done . '/' . $total . ' done' . PHP_EOL;
    echo 'Blocked: ' . $blocked . PHP_EOL;
    echo 'Active step: ' . ($inProgress ?? 'none') . PHP_EOL;
    echo 'Updated at: ' . (string)($state['updated_at'] ?? '-') . PHP_EOL;
    echo PHP_EOL;

    foreach ($orderedSteps as $step) {
        $id = $step['id'];
        $status = (string)$state['steps'][$id]['status'];
        echo sprintf('[%s] %s | %s', padStatus($status), $id, $step['title_ar']) . PHP_EOL;
    }
}

function padStatus(string $status): string
{
    return match ($status) {
        STATE_DONE => 'DONE',
        STATE_IN_PROGRESS => 'WIP ',
        STATE_BLOCKED => 'BLKD',
        default => 'TODO',
    };
}

function nowUtc(): string
{
    return gmdate('c');
}

function fail(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}
