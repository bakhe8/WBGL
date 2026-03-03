<?php

declare(strict_types=1);

/**
 * Merge static/runtime i18n findings into one catalog.
 *
 * Usage:
 *   php app/Scripts/i18n-catalog-merge.php
 */

$projectRoot = dirname(__DIR__, 2);
$dir = $projectRoot . '/storage/i18n';
$staticPath = $dir . '/static-findings.json';
$runtimePath = $dir . '/runtime-findings.json';
$catalogPath = $dir . '/i18n-catalog.json';

if (!is_file($staticPath)) {
    fwrite(STDERR, "Missing static findings file: {$staticPath}\n");
    exit(1);
}
if (!is_file($runtimePath)) {
    fwrite(STDERR, "Missing runtime findings file: {$runtimePath}\n");
    exit(1);
}

$static = decodeJsonFile($staticPath, 'static findings');
$runtime = decodeJsonFile($runtimePath, 'runtime findings');

$catalog = [];
$fromStatic = 0;
$fromRuntime = 0;
$staticEntries = [];
$runtimeEntries = [];

foreach (($static['findings'] ?? []) as $finding) {
    if (!is_array($finding)) {
        continue;
    }
    $text = normalizeText((string)($finding['snippet'] ?? ''));
    if ($text === '') {
        continue;
    }

    $entryId = upsertCatalogEntry($catalog, $text);
    $catalog[$entryId]['sources'][] = [
        'type' => 'static',
        'rule' => (string)($finding['rule'] ?? ''),
        'file' => (string)($finding['file'] ?? ''),
        'line' => (int)($finding['line'] ?? 0),
    ];
    $fromStatic++;
    $staticEntries[$entryId] = true;
}

foreach (($runtime['pages'] ?? []) as $page) {
    if (!is_array($page)) {
        continue;
    }
    $route = (string)($page['route'] ?? '');
    $url = (string)($page['url'] ?? '');
    foreach (($page['records'] ?? []) as $record) {
        if (!is_array($record)) {
            continue;
        }
        $text = normalizeText((string)($record['text'] ?? ''));
        if ($text === '') {
            continue;
        }

        $entryId = upsertCatalogEntry($catalog, $text);
        $catalog[$entryId]['sources'][] = [
            'type' => 'runtime',
            'route' => $route,
            'url' => $url,
            'kind' => (string)($record['kind'] ?? ''),
            'attr' => $record['attr'] ?? null,
            'node_path' => (string)($record['node_path'] ?? ''),
            'has_i18n_binding' => (bool)($record['has_i18n_binding'] ?? false),
        ];
        $fromRuntime++;
        $runtimeEntries[$entryId] = true;
    }
}

foreach ($catalog as &$entry) {
    $entry['source_count'] = count($entry['sources']);
}
unset($entry);

usort($catalog, static function (array $a, array $b): int {
    return [$b['source_count'], $a['text']] <=> [$a['source_count'], $b['text']];
});

$result = [
    'generated_at' => date('c'),
    'summary' => [
        'total_entries' => count($catalog),
        'static_records' => $fromStatic,
        'runtime_records' => $fromRuntime,
        'entries_from_static' => count($staticEntries),
        'entries_from_runtime' => count($runtimeEntries),
        'entries_with_arabic' => count(array_filter($catalog, static fn(array $entry): bool => (bool)$entry['has_arabic'])),
        'entries_with_latin' => count(array_filter($catalog, static fn(array $entry): bool => (bool)$entry['has_latin'])),
    ],
    'entries' => $catalog,
];

writeJsonFile($catalogPath, $result);

echo "I18N_CATALOG_MERGE: DONE\n";
echo "CATALOG_JSON: {$catalogPath}\n";
echo "TOTAL_ENTRIES: " . (string)$result['summary']['total_entries'] . "\n";
echo "STATIC_RECORDS: " . (string)$fromStatic . "\n";
echo "RUNTIME_RECORDS: " . (string)$fromRuntime . "\n";
exit(0);

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

function upsertCatalogEntry(array &$catalog, string $text): string
{
    $normalized = normalizeText($text);
    $entryId = sha1($normalized);
    if (!isset($catalog[$entryId])) {
        $catalog[$entryId] = [
            'catalog_id' => $entryId,
            'text' => $text,
            'normalized_text' => $normalized,
            'has_arabic' => hasArabicLetters($text),
            'has_latin' => hasLatinLetters($text),
            'status' => 'new',
            'namespace' => null,
            'proposed_key' => null,
            'sources' => [],
            'source_count' => 0,
        ];
    }
    return $entryId;
}

function normalizeText(string $value): string
{
    $text = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace(["\r", "\n", "\t"], ' ', $text);
    $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
    return $text;
}

function hasArabicLetters(string $value): bool
{
    return (bool)preg_match('/[\x{0600}-\x{06FF}]/u', $value);
}

function hasLatinLetters(string $value): bool
{
    return (bool)preg_match('/[A-Za-z]/', $value);
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
