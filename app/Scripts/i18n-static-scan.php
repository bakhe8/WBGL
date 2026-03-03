<?php

declare(strict_types=1);

/**
 * WBGL i18n static scan + gate.
 *
 * Usage:
 *   php app/Scripts/i18n-static-scan.php scan
 *   php app/Scripts/i18n-static-scan.php scan --update-baseline
 *   php app/Scripts/i18n-static-scan.php gate
 */

$projectRoot = dirname(__DIR__, 2);
$outputDir = $projectRoot . '/storage/i18n';
$resultPath = $outputDir . '/static-findings.json';
$baselinePath = $outputDir . '/static-baseline.json';

$command = $argv[1] ?? 'scan';
$updateBaseline = in_array('--update-baseline', $argv, true);

$targets = [
    'index.php',
    'views',
    'partials',
    'templates',
    'public/js',
];

$allowedExtensions = ['php', 'html', 'js', 'mjs'];

if (!in_array($command, ['scan', 'gate'], true)) {
    fwrite(STDERR, "Invalid command. Use: scan | gate\n");
    exit(2);
}

ensureDirectory($outputDir);

$files = collectFiles($projectRoot, $targets, $allowedExtensions);
$scan = runStaticScan($projectRoot, $files);

writeJson($resultPath, $scan['result']);

if ($command === 'scan') {
    if ($updateBaseline) {
        $baseline = [
            'generated_at' => date('c'),
            'signature_count' => count($scan['signatures']),
            'signatures' => array_values($scan['signatures']),
        ];
        writeJson($baselinePath, $baseline);
        echo "BASELINE_UPDATED: {$baselinePath}\n";
    }

    echo "I18N_STATIC_SCAN: DONE\n";
    echo "RESULT_JSON: {$resultPath}\n";
    echo "FILES_SCANNED: " . (string)$scan['result']['summary']['total_files_scanned'] . "\n";
    echo "TOTAL_FINDINGS: " . (string)$scan['result']['summary']['total_findings'] . "\n";
    exit(0);
}

if (!is_file($baselinePath)) {
    fwrite(STDERR, "Missing baseline: {$baselinePath}\n");
    fwrite(STDERR, "Run: php app/Scripts/i18n-static-scan.php scan --update-baseline\n");
    exit(2);
}

$baselineRaw = json_decode((string)file_get_contents($baselinePath), true);
if (!is_array($baselineRaw) || !is_array($baselineRaw['signatures'] ?? null)) {
    fwrite(STDERR, "Invalid baseline format: {$baselinePath}\n");
    exit(2);
}

$baselineSignatures = [];
foreach ($baselineRaw['signatures'] as $signature) {
    if (is_string($signature) && $signature !== '') {
        $baselineSignatures[$signature] = true;
    }
}

$newSignatures = [];
foreach ($scan['signatures'] as $signature) {
    if (!isset($baselineSignatures[$signature])) {
        $newSignatures[$signature] = true;
    }
}

if (count($newSignatures) === 0) {
    echo "I18N_STATIC_GATE: PASS (no new hardcoded UI strings)\n";
    exit(0);
}

$newFindings = [];
foreach ($scan['result']['findings'] as $finding) {
    if (isset($newSignatures[$finding['signature']])) {
        $newFindings[] = $finding;
    }
}

echo "I18N_STATIC_GATE: FAIL\n";
echo "NEW_VIOLATIONS: " . count($newFindings) . "\n";
foreach (array_slice($newFindings, 0, 30) as $finding) {
    echo "- {$finding['rule']} | {$finding['file']}:{$finding['line']} | {$finding['snippet']}\n";
}
if (count($newFindings) > 30) {
    echo "... and " . (count($newFindings) - 30) . " more\n";
}
exit(1);

/**
 * @return list<string>
 */
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

function runStaticScan(string $projectRoot, array $files): array
{
    $findings = [];
    $signatures = [];
    $ruleCounts = [];
    $filesWithFindings = [];
    $perFileCounts = [];

    foreach ($files as $absolutePath) {
        $relativePath = ltrim(str_replace('\\', '/', str_replace($projectRoot, '', $absolutePath)), '/');
        $extension = strtolower((string)pathinfo($absolutePath, PATHINFO_EXTENSION));
        $lines = @file($absolutePath, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            continue;
        }

        foreach ($lines as $lineNumber => $line) {
            if (!is_string($line) || trim($line) === '') {
                continue;
            }

            $lineHasI18nMarkup = str_contains($line, 'data-i18n');
            $lineHasI18nCall = str_contains($line, 'WBGLI18n.t(') || str_contains($line, 'window.WBGLI18n.t(');
            $candidates = [];

            foreach (extractQuotedCandidates($line) as $text) {
                $candidates[] = ['rule' => 'quoted_literal', 'text' => $text];
            }

            if (str_contains($line, '`')) {
                foreach (extractTemplateCandidates($line) as $text) {
                    $candidates[] = ['rule' => 'template_literal', 'text' => $text];
                }
            }

            if (in_array($extension, ['php', 'html'], true)) {
                foreach (extractMarkupTextCandidates($line) as $text) {
                    $candidates[] = ['rule' => 'markup_text', 'text' => $text];
                }
            }

            foreach ($candidates as $candidate) {
                $rule = (string)$candidate['rule'];
                if (($lineHasI18nMarkup || $lineHasI18nCall) && in_array($rule, ['quoted_literal', 'template_literal', 'markup_text'], true)) {
                    continue;
                }

                $snippet = normalizeSnippet((string)$candidate['text']);
                if ($snippet === '' || !looksLikeUiText($snippet)) {
                    continue;
                }
                if (isKnownNonUiSnippet($snippet)) {
                    continue;
                }

                $signature = sha1($rule . '|' . $relativePath . '|' . $snippet);
                $signatures[$signature] = $signature;
                $ruleCounts[$rule] = ($ruleCounts[$rule] ?? 0) + 1;
                $filesWithFindings[$relativePath] = true;
                $perFileCounts[$relativePath] = ($perFileCounts[$relativePath] ?? 0) + 1;

                $findings[] = [
                    'rule' => $rule,
                    'file' => $relativePath,
                    'line' => $lineNumber + 1,
                    'snippet' => $snippet,
                    'signature' => $signature,
                    'has_arabic' => hasArabicLetters($snippet),
                    'has_latin' => hasLatinLetters($snippet),
                ];
            }
        }
    }

    usort($findings, static function (array $a, array $b): int {
        return [$a['file'], $a['line'], $a['rule'], $a['snippet']] <=> [$b['file'], $b['line'], $b['rule'], $b['snippet']];
    });
    arsort($ruleCounts);
    arsort($perFileCounts);

    return [
        'result' => [
            'generated_at' => date('c'),
            'summary' => [
                'total_files_scanned' => count($files),
                'files_with_findings' => count($filesWithFindings),
                'total_findings' => count($findings),
                'rule_counts' => $ruleCounts,
                'top_files' => array_slice($perFileCounts, 0, 25, true),
            ],
            'findings' => $findings,
        ],
        'signatures' => $signatures,
    ];
}

/**
 * @return list<string>
 */
function extractQuotedCandidates(string $line): array
{
    $out = [];
    if (!preg_match_all('/"([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"|\'([^\'\\\\]*(?:\\\\.[^\'\\\\]*)*)\'/u', $line, $matches, PREG_SET_ORDER)) {
        return $out;
    }

    foreach ($matches as $match) {
        $doubleQuoted = isset($match[1]) ? (string)$match[1] : '';
        $singleQuoted = isset($match[2]) ? (string)$match[2] : '';
        $candidate = $doubleQuoted !== '' ? $doubleQuoted : $singleQuoted;
        if ($candidate === '') {
            continue;
        }
        $out[] = stripcslashes($candidate);
    }

    return $out;
}

/**
 * @return list<string>
 */
function extractTemplateCandidates(string $line): array
{
    $out = [];
    if (!preg_match_all('/`([^`\\\\]*(?:\\\\.[^`\\\\]*)*)`/u', $line, $matches)) {
        return $out;
    }

    foreach (($matches[1] ?? []) as $candidate) {
        $decoded = stripcslashes((string)$candidate);
        if ($decoded === '') {
            continue;
        }
        $out[] = $decoded;
    }

    return $out;
}

/**
 * @return list<string>
 */
function extractMarkupTextCandidates(string $line): array
{
    $out = [];
    if (!preg_match_all('/>([^<]+)</u', $line, $matches)) {
        return $out;
    }

    foreach (($matches[1] ?? []) as $candidate) {
        $text = html_entity_decode((string)$candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim($text);
        if ($text === '' || str_starts_with($text, '<?') || str_contains($text, '?>')) {
            continue;
        }
        $out[] = $text;
    }

    return $out;
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

function looksLikeUiText(string $value): bool
{
    if (mb_strlen($value) < 2) {
        return false;
    }

    if (!preg_match('/[\p{L}]/u', $value)) {
        return false;
    }

    if (hasArabicLetters($value)) {
        return true;
    }

    if (!hasLatinLetters($value)) {
        return false;
    }

    if (preg_match('/^(true|false|null|undefined|function|return|const|let|var|class|if|else|for|while|switch|case|break|continue|default|new|this)$/i', $value)) {
        return false;
    }

    if (preg_match('/^[a-z0-9_:\\/.#-]+$/i', $value)) {
        return false;
    }

    if (preg_match('/^[A-Za-z]{1,2}$/', $value)) {
        return false;
    }

    return true;
}

function isKnownNonUiSnippet(string $value): bool
{
    if (strtolower($value) === 'use strict') {
        return true;
    }
    if (str_contains($value, '<?') || str_contains($value, '?>')) {
        return true;
    }
    if (preg_match('/\$[A-Za-z_][A-Za-z0-9_]*/', $value)) {
        return true;
    }
    if (str_contains($value, '->') || str_contains($value, '::')) {
        return true;
    }
    if (preg_match('/\b(SELECT|FROM|WHERE|JOIN|INSERT|UPDATE|DELETE|AND|OR)\b/i', $value) && preg_match('/[=()]/', $value)) {
        return true;
    }
    if (preg_match('/\.(php|js|css|json|xlsx|xls|csv)\b/i', $value)) {
        return true;
    }
    if (preg_match('/^[.#\[]/', $value)) {
        return true;
    }
    if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*\([^)]*\)$/', $value)) {
        return true;
    }
    if (preg_match('/[{}<>]/', $value)) {
        return true;
    }
    if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
        return true;
    }
    if (preg_match('/^\$[A-Za-z_][A-Za-z0-9_]*$/', $value)) {
        return true;
    }
    if (preg_match('/^#[A-Fa-f0-9]{3,8}$/', $value)) {
        return true;
    }
    if (preg_match('/^[A-Za-z0-9_]+(?:\.[A-Za-z0-9_]+)+$/', $value)) {
        return true;
    }
    if (preg_match('/^[a-z0-9_-]+(?:\s+[a-z0-9_-]+)+$/i', $value)) {
        return true;
    }
    if (preg_match('/^\[[^\]]+\]$/', $value)) {
        return true;
    }
    if (preg_match('/^[a-z-]+=[^,]+(?:,\s*[a-z-]+=[^,]+)*$/i', $value)) {
        return true;
    }
    return false;
}

function hasArabicLetters(string $value): bool
{
    return (bool)preg_match('/[\x{0600}-\x{06FF}]/u', $value);
}

function hasLatinLetters(string $value): bool
{
    return (bool)preg_match('/[A-Za-z]/', $value);
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
        fwrite(STDERR, "Failed to encode JSON for {$path}\n");
        exit(1);
    }
    if (file_put_contents($path, $json . PHP_EOL) === false) {
        fwrite(STDERR, "Failed to write file: {$path}\n");
        exit(1);
    }
}
