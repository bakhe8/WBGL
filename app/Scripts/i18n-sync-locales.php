<?php

declare(strict_types=1);

/**
 * Sync and validate locale files from i18n catalog.
 *
 * Usage:
 *   php app/Scripts/i18n-sync-locales.php sync
 *   php app/Scripts/i18n-sync-locales.php check
 */

$projectRoot = dirname(__DIR__, 2);
$catalogPath = $projectRoot . '/storage/i18n/i18n-catalog.json';
$localesRoot = $projectRoot . '/public/locales';
$reportPath = $projectRoot . '/storage/i18n/locale-sync-report.json';

$command = $argv[1] ?? 'sync';
if (!in_array($command, ['sync', 'check'], true)) {
    fwrite(STDERR, "Invalid command. Use: sync | check\n");
    exit(2);
}

if (!is_file($catalogPath)) {
    fwrite(STDERR, "Missing catalog: {$catalogPath}\n");
    exit(1);
}

$catalog = decodeJsonFile($catalogPath, 'catalog');
$entries = $catalog['entries'] ?? null;
if (!is_array($entries)) {
    fwrite(STDERR, "Invalid catalog format: entries missing\n");
    exit(1);
}

$requiredNamespaces = [
    'common',
    'auth',
    'settings',
    'users',
    'index',
    'batches',
    'batch_detail',
    'statistics',
    'maintenance',
    'timeline',
    'modals',
    'messages',
];

$locales = ['ar', 'en'];
$data = [];
$changedFiles = [];
$addedByLocale = ['ar' => 0, 'en' => 0];

foreach ($locales as $locale) {
    $dir = $localesRoot . '/' . $locale;
    ensureDirectory($dir);
    foreach ($requiredNamespaces as $namespace) {
        $path = $dir . '/' . $namespace . '.json';
        if (is_file($path)) {
            $data[$locale][$namespace] = decodeJsonFile($path, "{$locale}/{$namespace}");
            if (!is_array($data[$locale][$namespace])) {
                $data[$locale][$namespace] = [];
            }
        } else {
            $data[$locale][$namespace] = [];
            $changedFiles[$path] = true;
        }
    }
}

$touchedEntries = 0;
foreach ($entries as $entry) {
    if (!is_array($entry)) {
        continue;
    }

    $eligible = (bool)($entry['sync_eligible'] ?? false);
    if (!$eligible) {
        continue;
    }

    $namespace = normalizeNamespace((string)($entry['namespace'] ?? ''));
    $key = normalizeKey((string)($entry['proposed_key'] ?? ''));
    $text = normalizeText((string)($entry['text'] ?? ''));
    if ($namespace === '' || $key === '' || $text === '') {
        continue;
    }

    if (!isset($data['ar'][$namespace])) {
        $data['ar'][$namespace] = [];
    }
    if (!isset($data['en'][$namespace])) {
        $data['en'][$namespace] = [];
    }

    if (!array_key_exists($key, $data['ar'][$namespace])) {
        $data['ar'][$namespace][$key] = inferArValue($entry, $text, $key);
        $addedByLocale['ar']++;
        $changedFiles[$localesRoot . '/ar/' . $namespace . '.json'] = true;
    }
    if (!array_key_exists($key, $data['en'][$namespace])) {
        $data['en'][$namespace][$key] = inferEnValue($entry, $text, $key);
        $addedByLocale['en']++;
        $changedFiles[$localesRoot . '/en/' . $namespace . '.json'] = true;
    }
    $touchedEntries++;
}

$drift = computeKeyDrift($data, $requiredNamespaces);

foreach ($locales as $locale) {
    foreach ($requiredNamespaces as $namespace) {
        if (!isset($data[$locale][$namespace])) {
            $data[$locale][$namespace] = [];
        }
        ksort($data[$locale][$namespace]);
    }
}

if ($command === 'sync') {
    foreach ($locales as $locale) {
        foreach ($requiredNamespaces as $namespace) {
            $path = $localesRoot . '/' . $locale . '/' . $namespace . '.json';
            writeJsonFile($path, $data[$locale][$namespace]);
        }
    }
}

$report = [
    'generated_at' => date('c'),
    'command' => $command,
    'summary' => [
        'namespaces_tracked' => count($requiredNamespaces),
        'entries_processed' => $touchedEntries,
        'added_keys_ar' => $addedByLocale['ar'],
        'added_keys_en' => $addedByLocale['en'],
        'drift_namespaces' => count($drift['namespaces_with_drift']),
        'missing_keys_total_ar' => $drift['missing_in_ar_total'],
        'missing_keys_total_en' => $drift['missing_in_en_total'],
    ],
    'drift' => $drift,
];
writeJsonFile($reportPath, $report);

if ($command === 'sync') {
    echo "I18N_SYNC_LOCALES: DONE\n";
    echo "REPORT_JSON: {$reportPath}\n";
    echo "ADDED_AR: {$addedByLocale['ar']}\n";
    echo "ADDED_EN: {$addedByLocale['en']}\n";
    echo "DRIFT_NAMESPACES: " . count($drift['namespaces_with_drift']) . "\n";
    exit(0);
}

if ($drift['missing_in_ar_total'] === 0 && $drift['missing_in_en_total'] === 0) {
    echo "I18N_SYNC_CHECK: PASS (ar/en keys are aligned)\n";
    exit(0);
}

echo "I18N_SYNC_CHECK: FAIL\n";
echo "MISSING_IN_AR_TOTAL: {$drift['missing_in_ar_total']}\n";
echo "MISSING_IN_EN_TOTAL: {$drift['missing_in_en_total']}\n";
foreach ($drift['namespaces_with_drift'] as $namespace) {
    $arMissing = count($drift['missing_in_ar'][$namespace] ?? []);
    $enMissing = count($drift['missing_in_en'][$namespace] ?? []);
    echo "- {$namespace}: missing_in_ar={$arMissing}, missing_in_en={$enMissing}\n";
}
exit(1);

function normalizeNamespace(string $namespace): string
{
    $namespace = strtolower(trim($namespace));
    $namespace = preg_replace('/[^a-z0-9_]+/', '_', $namespace) ?? $namespace;
    return trim($namespace, '_');
}

function normalizeKey(string $key): string
{
    $key = trim($key);
    if ($key === '') {
        return '';
    }
    $key = preg_replace('/\s+/', '_', $key) ?? $key;
    $key = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $key) ?? $key;
    $key = preg_replace('/_+/', '_', $key) ?? $key;
    return trim($key, '._');
}

function inferArValue(array $entry, string $text, string $key): string
{
    if (!empty($entry['has_arabic'])) {
        return $text;
    }
    return "__TODO_AR__ {$key}";
}

function inferEnValue(array $entry, string $text, string $key): string
{
    if (!empty($entry['has_latin']) && empty($entry['has_arabic'])) {
        return $text;
    }
    return "__TODO_EN__ {$key}";
}

function computeKeyDrift(array $data, array $namespaces): array
{
    $missingInAr = [];
    $missingInEn = [];
    $missingArTotal = 0;
    $missingEnTotal = 0;
    $namespacesWithDrift = [];

    foreach ($namespaces as $namespace) {
        $arKeys = array_keys($data['ar'][$namespace] ?? []);
        $enKeys = array_keys($data['en'][$namespace] ?? []);
        sort($arKeys);
        sort($enKeys);

        $diffAr = array_values(array_diff($enKeys, $arKeys));
        $diffEn = array_values(array_diff($arKeys, $enKeys));
        if ($diffAr !== []) {
            $missingInAr[$namespace] = $diffAr;
            $missingArTotal += count($diffAr);
        }
        if ($diffEn !== []) {
            $missingInEn[$namespace] = $diffEn;
            $missingEnTotal += count($diffEn);
        }
        if ($diffAr !== [] || $diffEn !== []) {
            $namespacesWithDrift[] = $namespace;
        }
    }

    return [
        'missing_in_ar_total' => $missingArTotal,
        'missing_in_en_total' => $missingEnTotal,
        'namespaces_with_drift' => $namespacesWithDrift,
        'missing_in_ar' => $missingInAr,
        'missing_in_en' => $missingInEn,
    ];
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

function normalizeText(string $value): string
{
    $text = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace(["\r", "\n", "\t"], ' ', $text);
    $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
    return $text;
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

function writeJsonFile(string $path, array $payload): void
{
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        fwrite(STDERR, "Failed encoding JSON: {$path}\n");
        exit(1);
    }
    if (file_put_contents($path, $json . PHP_EOL) === false) {
        fwrite(STDERR, "Failed writing JSON: {$path}\n");
        exit(1);
    }
}
