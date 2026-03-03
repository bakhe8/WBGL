<?php

declare(strict_types=1);

/**
 * WBGL i18n quality scan + gate.
 *
 * Detects translation quality regressions that bypass simple key-drift checks:
 * - TODO placeholders in locale values.
 * - Corrupted strings (e.g. ???? or replacement glyphs).
 * - Arabic leakage inside EN locale catalogs.
 * - Key drift (missing keys between ar/en).
 * - Raw placeholder leaks in source files.
 *
 * Usage:
 *   php app/Scripts/i18n-quality-gate.php scan
 *   php app/Scripts/i18n-quality-gate.php gate
 */

$projectRoot = dirname(__DIR__, 2);
$localesRoot = $projectRoot . '/public/locales';
$outputDir = $projectRoot . '/storage/i18n';
$reportPath = $outputDir . '/quality-report.json';

$command = $argv[1] ?? 'scan';
if (!in_array($command, ['scan', 'gate'], true)) {
    fwrite(STDERR, "Invalid command. Use: scan | gate\n");
    exit(2);
}

ensureDirectory($outputDir);

$localeScan = scanLocaleCatalogs($localesRoot);
$sourceScan = scanSourceLeaks($projectRoot);

$violations = array_merge($localeScan['violations'], $sourceScan['violations']);
$ruleCounts = [];
$severityCounts = ['error' => 0, 'warning' => 0];
foreach ($violations as $violation) {
    $rule = (string)$violation['rule'];
    $severity = (string)$violation['severity'];
    $ruleCounts[$rule] = ($ruleCounts[$rule] ?? 0) + 1;
    $severityCounts[$severity] = ($severityCounts[$severity] ?? 0) + 1;
}
arsort($ruleCounts);

$report = [
    'generated_at' => date('c'),
    'command' => $command,
    'summary' => [
        'locale_files_scanned' => $localeScan['summary']['locale_files_scanned'],
        'locale_entries_scanned' => $localeScan['summary']['locale_entries_scanned'],
        'source_files_scanned' => $sourceScan['summary']['source_files_scanned'],
        'total_violations' => count($violations),
        'severity_counts' => $severityCounts,
        'rule_counts' => $ruleCounts,
    ],
    'locale_summary' => $localeScan['summary'],
    'source_summary' => $sourceScan['summary'],
    'violations' => $violations,
];

writeJson($reportPath, $report);

if ($command === 'scan') {
    echo "I18N_QUALITY_SCAN: DONE\n";
    echo "REPORT_JSON: {$reportPath}\n";
    echo "TOTAL_VIOLATIONS: " . count($violations) . "\n";
    echo "ERRORS: " . ($severityCounts['error'] ?? 0) . "\n";
    echo "WARNINGS: " . ($severityCounts['warning'] ?? 0) . "\n";
    exit(0);
}

$errorViolations = array_values(array_filter(
    $violations,
    static fn (array $violation): bool => (($violation['severity'] ?? '') === 'error')
));

if ($errorViolations === []) {
    echo "I18N_QUALITY_GATE: PASS\n";
    exit(0);
}

echo "I18N_QUALITY_GATE: FAIL\n";
echo "ERROR_VIOLATIONS: " . count($errorViolations) . "\n";
foreach (array_slice($errorViolations, 0, 40) as $violation) {
    $location = buildViolationLocation($violation);
    echo "- {$violation['rule']} | {$location} | {$violation['snippet']}\n";
}
if (count($errorViolations) > 40) {
    echo "... and " . (count($errorViolations) - 40) . " more\n";
}
exit(1);

function buildViolationLocation(array $violation): string
{
    if (isset($violation['file']) && isset($violation['line'])) {
        return (string)$violation['file'] . ':' . (string)$violation['line'];
    }

    if (isset($violation['locale'], $violation['namespace'], $violation['key'])) {
        return (string)$violation['locale'] . '/' . (string)$violation['namespace'] . ':' . (string)$violation['key'];
    }

    if (isset($violation['locale'], $violation['namespace'])) {
        return (string)$violation['locale'] . '/' . (string)$violation['namespace'];
    }

    return 'unknown';
}

function scanLocaleCatalogs(string $localesRoot): array
{
    $supportedLocales = ['ar', 'en'];
    $localeFiles = [];
    $flattened = [];
    $violations = [];
    $entryCount = 0;

    foreach ($supportedLocales as $locale) {
        $dir = $localesRoot . '/' . $locale;
        if (!is_dir($dir)) {
            continue;
        }
        $files = glob($dir . '/*.json') ?: [];
        sort($files);
        foreach ($files as $filePath) {
            $namespace = (string)pathinfo($filePath, PATHINFO_FILENAME);
            $localeFiles[] = $filePath;
            $decoded = decodeJsonFile($filePath, "{$locale}/{$namespace}");
            $pairs = flattenLocaleValues($decoded);
            foreach ($pairs as $key => $value) {
                $entryCount++;
                $flattened[$locale][$namespace][$key] = $value;
                $violations = array_merge($violations, detectLocaleViolations($locale, $namespace, $key, $value));
            }
        }
    }

    $drift = detectLocaleDrift($flattened);
    $violations = array_merge($violations, $drift['violations']);

    return [
        'summary' => [
            'locale_files_scanned' => count($localeFiles),
            'locale_entries_scanned' => $entryCount,
            'missing_in_ar_total' => $drift['missing_in_ar_total'],
            'missing_in_en_total' => $drift['missing_in_en_total'],
            'namespaces_with_drift' => $drift['namespaces_with_drift'],
        ],
        'violations' => $violations,
    ];
}

function detectLocaleDrift(array $flattened): array
{
    $violations = [];
    $missingInArTotal = 0;
    $missingInEnTotal = 0;
    $namespacesWithDrift = [];

    $namespaces = array_unique(array_merge(
        array_keys($flattened['ar'] ?? []),
        array_keys($flattened['en'] ?? [])
    ));
    sort($namespaces);

    foreach ($namespaces as $namespace) {
        $arKeys = array_keys($flattened['ar'][$namespace] ?? []);
        $enKeys = array_keys($flattened['en'][$namespace] ?? []);
        sort($arKeys);
        sort($enKeys);

        $missingInAr = array_values(array_diff($enKeys, $arKeys));
        $missingInEn = array_values(array_diff($arKeys, $enKeys));

        if ($missingInAr !== [] || $missingInEn !== []) {
            $namespacesWithDrift[] = $namespace;
        }

        foreach ($missingInAr as $key) {
            $missingInArTotal++;
            $violations[] = [
                'severity' => 'error',
                'rule' => 'missing_key_in_ar',
                'locale' => 'ar',
                'namespace' => $namespace,
                'key' => $key,
                'snippet' => 'Missing key in ar locale catalog',
            ];
        }
        foreach ($missingInEn as $key) {
            $missingInEnTotal++;
            $violations[] = [
                'severity' => 'error',
                'rule' => 'missing_key_in_en',
                'locale' => 'en',
                'namespace' => $namespace,
                'key' => $key,
                'snippet' => 'Missing key in en locale catalog',
            ];
        }
    }

    return [
        'missing_in_ar_total' => $missingInArTotal,
        'missing_in_en_total' => $missingInEnTotal,
        'namespaces_with_drift' => $namespacesWithDrift,
        'violations' => $violations,
    ];
}

function detectLocaleViolations(string $locale, string $namespace, string $key, string $value): array
{
    $violations = [];
    $normalized = normalizeSnippet($value);

    if ($normalized === '') {
        $violations[] = [
            'severity' => 'error',
            'rule' => 'empty_locale_value',
            'locale' => $locale,
            'namespace' => $namespace,
            'key' => $key,
            'snippet' => '[empty]',
        ];
        return $violations;
    }

    if (hasTodoMarker($normalized)) {
        $violations[] = [
            'severity' => 'error',
            'rule' => 'todo_placeholder',
            'locale' => $locale,
            'namespace' => $namespace,
            'key' => $key,
            'snippet' => $normalized,
        ];
    }

    if (hasCorruptionMarker($normalized)) {
        $violations[] = [
            'severity' => 'error',
            'rule' => 'corrupted_text_marker',
            'locale' => $locale,
            'namespace' => $namespace,
            'key' => $key,
            'snippet' => $normalized,
        ];
    }

    if (looksLikeGeneratedKey($normalized)) {
        $violations[] = [
            'severity' => 'error',
            'rule' => 'untranslated_key_like_value',
            'locale' => $locale,
            'namespace' => $namespace,
            'key' => $key,
            'snippet' => $normalized,
        ];
    }

    if ($locale === 'en' && hasArabicLetters($normalized)) {
        $violations[] = [
            'severity' => 'error',
            'rule' => 'arabic_text_in_en_locale',
            'locale' => $locale,
            'namespace' => $namespace,
            'key' => $key,
            'snippet' => $normalized,
        ];
    }

    return $violations;
}

function scanSourceLeaks(string $projectRoot): array
{
    $targets = [
        'index.php',
        'views',
        'partials',
        'templates',
        'public/js',
        'api',
    ];
    $extensions = ['php', 'html', 'js', 'mjs'];

    $files = collectFiles($projectRoot, $targets, $extensions);
    $violations = [];

    foreach ($files as $absolutePath) {
        $relativePath = ltrim(str_replace('\\', '/', str_replace($projectRoot, '', $absolutePath)), '/');
        $lines = @file($absolutePath, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            continue;
        }

        foreach ($lines as $lineNumber => $line) {
            if (!is_string($line) || trim($line) === '') {
                continue;
            }
            if (shouldIgnoreSourceLine($line)) {
                continue;
            }
            $snippet = normalizeSnippet($line);

            if (hasTodoMarker($line)) {
                $violations[] = [
                    'severity' => 'error',
                    'rule' => 'source_todo_placeholder',
                    'file' => $relativePath,
                    'line' => $lineNumber + 1,
                    'snippet' => $snippet,
                ];
            }

            if (hasCorruptionMarker($line)) {
                $violations[] = [
                    'severity' => 'error',
                    'rule' => 'source_corrupted_marker',
                    'file' => $relativePath,
                    'line' => $lineNumber + 1,
                    'snippet' => $snippet,
                ];
            }
        }
    }

    return [
        'summary' => [
            'source_files_scanned' => count($files),
        ],
        'violations' => $violations,
    ];
}

function hasTodoMarker(string $value): bool
{
    return (bool)preg_match('/__TODO_(?:EN|AR)__|TODO_EN__|TODO_AR__/i', $value);
}

function shouldIgnoreSourceLine(string $line): bool
{
    // Internal sentinel declarations used by migration guards, not user-facing UI text.
    if (preg_match('/\b[A-Za-z0-9_]*Todo(?:Ar|En)Prefix\b/', $line) === 1) {
        return true;
    }

    return false;
}

function hasCorruptionMarker(string $value): bool
{
    if (str_contains($value, '�')) {
        return true;
    }
    return (bool)preg_match('/\?{3,}/', $value);
}

function looksLikeGeneratedKey(string $value): bool
{
    $trimmed = trim($value);
    if ($trimmed === '' || str_contains($trimmed, ' ')) {
        return false;
    }

    if (preg_match('/^[a-z0-9_.-]{10,}$/i', $trimmed) !== 1) {
        return false;
    }

    if (!str_contains($trimmed, '.')) {
        return false;
    }

    if (preg_match('/\.(ui|txt)_[a-f0-9]{4,}$/i', $trimmed) === 1) {
        return true;
    }

    return (bool)preg_match('/^[a-z]+(?:\.[a-z0-9_]+){2,}$/i', $trimmed);
}

function hasArabicLetters(string $value): bool
{
    return (bool)preg_match('/[\x{0600}-\x{06FF}]/u', $value);
}

function normalizeSnippet(string $value): string
{
    $snippet = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $snippet = str_replace(["\r", "\n", "\t"], ' ', $snippet);
    $snippet = preg_replace('/\s+/u', ' ', trim($snippet)) ?? trim($snippet);
    if (mb_strlen($snippet) > 220) {
        $snippet = mb_substr($snippet, 0, 220) . '...';
    }
    return $snippet;
}

function flattenLocaleValues(array $input, string $prefix = ''): array
{
    $flat = [];
    foreach ($input as $key => $value) {
        $segment = (string)$key;
        $path = ($prefix === '') ? $segment : ($prefix . '.' . $segment);
        if (is_array($value)) {
            $flat = array_merge($flat, flattenLocaleValues($value, $path));
            continue;
        }
        if (is_bool($value) || is_int($value) || is_float($value)) {
            $flat[$path] = (string)$value;
            continue;
        }
        $flat[$path] = is_string($value) ? $value : '';
    }
    ksort($flat);
    return $flat;
}

function collectFiles(string $root, array $targets, array $allowedExtensions): array
{
    $files = [];
    foreach ($targets as $target) {
        $absolute = $root . '/' . $target;
        if (is_file($absolute)) {
            if (isAllowedExtension($absolute, $allowedExtensions)) {
                $files[] = $absolute;
            }
            continue;
        }
        if (!is_dir($absolute)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo || !$item->isFile()) {
                continue;
            }
            $path = str_replace('\\', '/', $item->getPathname());
            if (str_contains($path, '/vendor/') || str_contains($path, '/node_modules/') || str_contains($path, '/.git/')) {
                continue;
            }
            if (str_contains($path, '.min.js')) {
                continue;
            }
            if (!isAllowedExtension($path, $allowedExtensions)) {
                continue;
            }
            $files[] = $item->getPathname();
        }
    }
    sort($files);
    return array_values(array_unique($files));
}

function isAllowedExtension(string $path, array $allowedExtensions): bool
{
    $extension = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
    return in_array($extension, $allowedExtensions, true);
}

function decodeJsonFile(string $path, string $label): array
{
    $raw = file_get_contents($path);
    if ($raw === false) {
        fwrite(STDERR, "Unable to read {$label}: {$path}\n");
        exit(1);
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "Invalid JSON in {$label}: {$path}\n");
        exit(1);
    }
    return $decoded;
}

function ensureDirectory(string $path): void
{
    if (is_dir($path)) {
        return;
    }
    if (!mkdir($path, 0777, true) && !is_dir($path)) {
        fwrite(STDERR, "Unable to create directory: {$path}\n");
        exit(1);
    }
}

function writeJson(string $path, array $payload): void
{
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        fwrite(STDERR, "Failed to encode JSON: {$path}\n");
        exit(1);
    }
    if (file_put_contents($path, $json . PHP_EOL) === false) {
        fwrite(STDERR, "Failed to write JSON: {$path}\n");
        exit(1);
    }
}
