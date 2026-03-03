<?php

declare(strict_types=1);

/**
 * Generate and map i18n keys from merged catalog.
 *
 * Usage:
 *   php app/Scripts/i18n-keygen.php
 */

$projectRoot = dirname(__DIR__, 2);
$catalogPath = $projectRoot . '/storage/i18n/i18n-catalog.json';
$reportPath = $projectRoot . '/storage/i18n/keygen-report.json';

if (!is_file($catalogPath)) {
    fwrite(STDERR, "Missing catalog file: {$catalogPath}\n");
    exit(1);
}

$catalog = decodeJsonFile($catalogPath, 'catalog');
$entries = $catalog['entries'] ?? null;
if (!is_array($entries)) {
    fwrite(STDERR, "Invalid catalog format: entries missing\n");
    exit(1);
}

$localeIndex = buildLocaleIndex($projectRoot);
$knownKeys = $localeIndex['known_keys'];
$valueIndexByNamespace = $localeIndex['value_index_by_namespace'];
$valueIndexGlobal = $localeIndex['value_index_global'];

$generatedCount = 0;
$mappedCount = 0;
$translatedCount = 0;
$eligibleCount = 0;
$statusCounts = [];
$namespaceCounts = [];
$usedKeys = [];

foreach ($entries as $idx => $entry) {
    if (!is_array($entry)) {
        continue;
    }

    $text = normalizeText((string)($entry['text'] ?? ''));
    if ($text === '') {
        continue;
    }

    $preferredNamespace = chooseNamespace($entry);
    $match = findExistingKeyMatch($text, $preferredNamespace, $valueIndexByNamespace, $valueIndexGlobal);

    if ($match !== null) {
        $namespace = (string)$match['namespace'];
        $key = (string)$match['key'];
        $entry['namespace'] = $namespace;
        $entry['proposed_key'] = $key;
        $entry['key_origin'] = 'existing';
        $entry['status'] = inferStatusFromLocaleKey($knownKeys, $namespace, $key);
        $mappedCount++;
    } else {
        $namespace = $preferredNamespace;
        $baseKey = generateKeyBase($namespace, $entry, $text);
        $key = ensureUniqueKey($baseKey, $knownKeys, $usedKeys);
        $entry['namespace'] = $namespace;
        $entry['proposed_key'] = $key;
        $entry['key_origin'] = 'generated';
        $entry['status'] = 'new';
        $generatedCount++;
    }

    $entry['sync_eligible'] = isEligibleForSyncEntry($entry, $text);
    if ($entry['sync_eligible']) {
        $eligibleCount++;
    }

    $usedKeys[$namespace . '|' . $key] = true;
    $status = (string)$entry['status'];
    $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
    $namespaceCounts[$namespace] = ($namespaceCounts[$namespace] ?? 0) + 1;
    if ($status === 'translated' || $status === 'reviewed') {
        $translatedCount++;
    }

    $entries[$idx] = $entry;
}

arsort($statusCounts);
arsort($namespaceCounts);

$catalog['generated_at'] = date('c');
$catalog['summary']['keygen'] = [
    'entries_total' => count($entries),
    'generated_keys' => $generatedCount,
    'mapped_existing_keys' => $mappedCount,
    'translated_or_reviewed' => $translatedCount,
    'sync_eligible' => $eligibleCount,
    'sync_excluded' => count($entries) - $eligibleCount,
    'status_counts' => $statusCounts,
    'namespace_counts' => $namespaceCounts,
];
$catalog['entries'] = $entries;

writeJsonFile($catalogPath, $catalog);
writeJsonFile($reportPath, [
    'generated_at' => date('c'),
    'summary' => $catalog['summary']['keygen'],
    'examples' => array_slice(array_map(static function (array $entry): array {
        return [
            'text' => (string)($entry['text'] ?? ''),
            'namespace' => (string)($entry['namespace'] ?? ''),
            'proposed_key' => (string)($entry['proposed_key'] ?? ''),
            'status' => (string)($entry['status'] ?? ''),
            'key_origin' => (string)($entry['key_origin'] ?? ''),
        ];
    }, $entries), 0, 50),
]);

echo "I18N_KEYGEN: DONE\n";
echo "CATALOG_UPDATED: {$catalogPath}\n";
echo "REPORT_JSON: {$reportPath}\n";
echo "GENERATED_KEYS: {$generatedCount}\n";
echo "MAPPED_KEYS: {$mappedCount}\n";
echo "TRANSLATED_OR_REVIEWED: {$translatedCount}\n";
echo "SYNC_ELIGIBLE: {$eligibleCount}\n";
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

function buildLocaleIndex(string $projectRoot): array
{
    $localesRoot = $projectRoot . '/public/locales';
    $languages = ['ar', 'en'];
    $knownKeys = [];
    $valueIndexByNamespace = [];
    $valueIndexGlobal = [];

    foreach ($languages as $language) {
        $dir = $localesRoot . '/' . $language;
        if (!is_dir($dir)) {
            continue;
        }
        $files = glob($dir . '/*.json');
        if (!is_array($files)) {
            continue;
        }

        foreach ($files as $filePath) {
            $namespace = basename($filePath, '.json');
            $data = decodeJsonFile($filePath, "{$language}/{$namespace}");
            foreach ($data as $key => $value) {
                if (!is_string($key) || $key === '') {
                    continue;
                }
                if (!is_string($value)) {
                    continue;
                }

                if (!isset($knownKeys[$namespace])) {
                    $knownKeys[$namespace] = [];
                }
                if (!isset($knownKeys[$namespace][$key])) {
                    $knownKeys[$namespace][$key] = ['ar' => null, 'en' => null];
                }
                $knownKeys[$namespace][$key][$language] = $value;

                $normalizedValue = normalizeText($value);
                if ($normalizedValue === '') {
                    continue;
                }

                $valueIndexByNamespace[$namespace][$normalizedValue][] = $key;
                $valueIndexGlobal[$normalizedValue][] = [
                    'namespace' => $namespace,
                    'key' => $key,
                ];
            }
        }
    }

    foreach ($valueIndexByNamespace as $namespace => $rows) {
        foreach ($rows as $value => $keys) {
            $valueIndexByNamespace[$namespace][$value] = array_values(array_unique($keys));
        }
    }
    foreach ($valueIndexGlobal as $value => $rows) {
        $dedup = [];
        foreach ($rows as $row) {
            $dedup[$row['namespace'] . '|' . $row['key']] = $row;
        }
        $valueIndexGlobal[$value] = array_values($dedup);
    }

    return [
        'known_keys' => $knownKeys,
        'value_index_by_namespace' => $valueIndexByNamespace,
        'value_index_global' => $valueIndexGlobal,
    ];
}

function chooseNamespace(array $entry): string
{
    $scores = [];
    foreach (($entry['sources'] ?? []) as $source) {
        if (!is_array($source)) {
            continue;
        }
        $namespace = 'common';

        if (($source['type'] ?? '') === 'static') {
            $file = strtolower((string)($source['file'] ?? ''));
            $namespace = namespaceFromPath($file);
        } else {
            $route = strtolower((string)($source['route'] ?? ''));
            $url = strtolower((string)($source['url'] ?? ''));
            $namespace = namespaceFromRouteOrUrl($route !== '' ? $route : $url);
        }

        $scores[$namespace] = ($scores[$namespace] ?? 0) + 1;
    }

    if ($scores === []) {
        return 'common';
    }
    arsort($scores);
    return (string)array_key_first($scores);
}

function namespaceFromPath(string $path): string
{
    if ($path === '') {
        return 'common';
    }

    if (str_contains($path, 'views/login.php') || str_contains($path, 'auth')) {
        return 'auth';
    }
    if (str_contains($path, 'views/users.php') || str_contains($path, 'users-management.js')) {
        return 'users';
    }
    if (str_contains($path, 'views/settings.php')) {
        return 'settings';
    }
    if (str_contains($path, 'views/statistics.php') || str_contains($path, 'stats')) {
        return 'statistics';
    }
    if (str_contains($path, 'views/maintenance.php') || str_contains($path, '/maint/')) {
        return 'maintenance';
    }
    if (str_contains($path, 'views/batches.php')) {
        return 'batches';
    }
    if (str_contains($path, 'views/batch-detail.php')) {
        return 'batch_detail';
    }
    if (str_contains($path, 'timeline')) {
        return 'timeline';
    }
    if (str_contains($path, 'modal') || str_contains($path, 'input-modals.controller.js') || str_contains($path, 'smart-workstation.controller.js')) {
        return 'modals';
    }
    if (str_contains($path, 'records.controller.js') || str_contains($path, 'preview-formatter.js')) {
        return 'batch_detail';
    }
    if (str_contains($path, 'index.php') || str_contains($path, 'partials/unified-header.php')) {
        return 'index';
    }

    return 'common';
}

function namespaceFromRouteOrUrl(string $value): string
{
    if ($value === '') {
        return 'common';
    }
    $normalized = str_replace('\\', '/', $value);
    return namespaceFromPath($normalized);
}

function findExistingKeyMatch(string $text, string $preferredNamespace, array $valueIndexByNamespace, array $valueIndexGlobal): ?array
{
    $normalized = normalizeText($text);
    if ($normalized === '') {
        return null;
    }

    $namespaceMatches = $valueIndexByNamespace[$preferredNamespace][$normalized] ?? [];
    if (is_array($namespaceMatches) && count($namespaceMatches) === 1) {
        return [
            'namespace' => $preferredNamespace,
            'key' => (string)$namespaceMatches[0],
        ];
    }

    $global = $valueIndexGlobal[$normalized] ?? [];
    if (!is_array($global) || $global === []) {
        return null;
    }

    if (count($global) === 1) {
        return [
            'namespace' => (string)$global[0]['namespace'],
            'key' => (string)$global[0]['key'],
        ];
    }

    foreach ($global as $candidate) {
        if ((string)$candidate['namespace'] === $preferredNamespace) {
            return [
                'namespace' => (string)$candidate['namespace'],
                'key' => (string)$candidate['key'],
            ];
        }
    }

    return [
        'namespace' => (string)$global[0]['namespace'],
        'key' => (string)$global[0]['key'],
    ];
}

function inferStatusFromLocaleKey(array $knownKeys, string $namespace, string $key): string
{
    $row = $knownKeys[$namespace][$key] ?? null;
    if (!is_array($row)) {
        return 'mapped';
    }

    $ar = normalizeText((string)($row['ar'] ?? ''));
    $en = normalizeText((string)($row['en'] ?? ''));
    if ($ar !== '' && $en !== '') {
        return 'translated';
    }
    return 'mapped';
}

function generateKeyBase(string $namespace, array $entry, string $text): string
{
    $area = 'ui';
    foreach (($entry['sources'] ?? []) as $source) {
        if (!is_array($source)) {
            continue;
        }
        $file = '';
        if (($source['type'] ?? '') === 'static') {
            $file = strtolower((string)($source['file'] ?? ''));
        } else {
            $file = strtolower((string)($source['route'] ?? ''));
            if ($file === '') {
                $file = strtolower((string)($source['url'] ?? ''));
            }
        }

        if (str_contains($file, 'modal')) {
            $area = 'modal';
            break;
        }
        if (str_contains($file, 'header')) {
            $area = 'header';
            break;
        }
        if (str_contains($file, 'timeline')) {
            $area = 'timeline';
            break;
        }
        if (str_contains($file, 'table')) {
            $area = 'table';
            break;
        }
    }

    $semantic = semanticSlug($text);
    return $namespace . '.' . $area . '.' . $semantic;
}

function semanticSlug(string $text): string
{
    if (hasArabicLetters($text)) {
        return 'txt_' . substr(sha1($text), 0, 8);
    }

    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    if (!is_string($ascii)) {
        $ascii = $text;
    }
    $ascii = strtolower($ascii);
    $ascii = preg_replace('/[^a-z0-9]+/', '_', $ascii) ?? $ascii;
    $ascii = trim($ascii, '_');
    if ($ascii === '') {
        return 'txt_' . substr(sha1($text), 0, 8);
    }

    $parts = array_values(array_filter(explode('_', $ascii), static fn(string $part): bool => $part !== ''));
    if (count($parts) > 5) {
        $parts = array_slice($parts, 0, 5);
    }
    $slug = implode('_', $parts);
    if ($slug === '') {
        return 'txt_' . substr(sha1($text), 0, 8);
    }
    return $slug;
}

function isEligibleForSyncEntry(array $entry, string $text): bool
{
    $status = (string)($entry['status'] ?? '');
    if ($status === 'translated' || $status === 'reviewed' || $status === 'mapped') {
        return true;
    }

    if (mb_strlen($text) < 2 || mb_strlen($text) > 140) {
        return false;
    }
    if (!preg_match('/[\p{L}]/u', $text)) {
        return false;
    }
    if (isCodeLikeText($text)) {
        return false;
    }

    $sources = $entry['sources'] ?? [];
    if (is_array($sources)) {
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }
            if ((string)($source['type'] ?? '') === 'static') {
                $file = strtolower((string)($source['file'] ?? ''));
                if ($file !== '' && preg_match('/\.(php|js)$/', $file)) {
                    if (preg_match('/\b(index\.php|views\/login\.php|views\/users\.php|views\/settings\.php|views\/batches\.php|views\/batch-detail\.php|views\/statistics\.php|views\/maintenance\.php|partials\/)/', $file)) {
                        return true;
                    }
                }
            }
            if ((string)($source['type'] ?? '') === 'runtime') {
                if (!empty($source['has_i18n_binding'])) {
                    continue;
                }
                return true;
            }
        }
    }

    if (hasArabicLetters($text)) {
        return true;
    }

    if (!preg_match('/^[A-Za-z0-9\s.,:;!?\-()]+$/', $text)) {
        return false;
    }

    $tokens = array_values(array_filter(preg_split('/\s+/', $text) ?: [], static fn(string $part): bool => $part !== ''));
    if (count($tokens) === 1) {
        $token = $tokens[0];
        if (strlen($token) <= 6 && preg_match('/^[A-Za-z0-9.-]+$/', $token)) {
            return true;
        }
        return false;
    }

    return true;
}

function isCodeLikeText(string $text): bool
{
    if (str_contains($text, '<?') || str_contains($text, '?>')) {
        return true;
    }
    if (preg_match('/\$[A-Za-z_][A-Za-z0-9_]*/', $text)) {
        return true;
    }
    if (str_contains($text, '->') || str_contains($text, '::')) {
        return true;
    }
    if (preg_match('/\.(php|js|css|json|xlsx|xls|csv)\b/i', $text)) {
        return true;
    }
    if (str_contains($text, '/?') || str_contains($text, '://') || str_contains($text, '%"') || str_contains($text, '=&')) {
        return true;
    }
    if (preg_match('/^\s*[&\/%.#]/', $text)) {
        return true;
    }
    if (preg_match('/\b(CACHE-CONTROL|CONTENT-TYPE|PRAGMA|ORDER BY|LIMIT)\b/i', $text)) {
        return true;
    }
    if (preg_match('/\b(filter=|search=|stage=)\b/i', $text)) {
        return true;
    }
    if (preg_match('/^(btn|card|row|col|nav|tab|badge|modal|timeline|index|public|views|api|storage)[._-]/i', $text)) {
        return true;
    }
    if (preg_match('/[{}<>]/', $text)) {
        return true;
    }
    if (preg_match('/^[.#\[]/', $text)) {
        return true;
    }
    if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*\([^)]*\)$/', $text)) {
        return true;
    }
    if (preg_match('/\b(SELECT|FROM|WHERE|JOIN|INSERT|UPDATE|DELETE|AND|OR)\b/i', $text) && preg_match('/[=()]/', $text)) {
        return true;
    }
    if (preg_match('/^[a-z0-9_:\\/.#-]{8,}$/i', $text)) {
        return true;
    }
    return false;
}

function ensureUniqueKey(string $baseKey, array $knownKeys, array $usedKeys): string
{
    $parts = explode('.', $baseKey, 2);
    $namespace = $parts[0] ?? 'common';
    $candidate = $baseKey;
    $counter = 2;

    while (true) {
        $existsInLocale = isset($knownKeys[$namespace][$candidate]);
        $existsInBatch = isset($usedKeys[$namespace . '|' . $candidate]);
        if (!$existsInLocale && !$existsInBatch) {
            return $candidate;
        }
        $candidate = $baseKey . '_' . $counter;
        $counter++;
    }
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

function writeJsonFile(string $path, array $payload): void
{
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        fwrite(STDERR, "Failed encoding JSON for {$path}\n");
        exit(1);
    }
    if (file_put_contents($path, $json . PHP_EOL) === false) {
        fwrite(STDERR, "Failed writing JSON for {$path}\n");
        exit(1);
    }
}
