<?php

declare(strict_types=1);

/**
 * WBGL Theme/Style Audit + Gate
 *
 * Usage:
 *   php app/Scripts/theme-style-audit.php scan
 *   php app/Scripts/theme-style-audit.php scan --update-baseline
 *   php app/Scripts/theme-style-audit.php gate
 */

$projectRoot = dirname(__DIR__, 2);

$resultPath = $projectRoot . '/Docs/WBGL-THEME-STYLE-AUDIT-RESULT-AR.json';
$reportPath = $projectRoot . '/Docs/WBGL-THEME-STYLE-AUDIT-REPORT-AR.md';
$baselinePath = $projectRoot . '/Docs/WBGL-THEME-STYLE-BASELINE-AR.json';

$command = $argv[1] ?? 'scan';
$updateBaseline = in_array('--update-baseline', $argv, true);

$targets = [
    'index.php',
    'views',
    'partials',
    'templates',
    'public/js',
    'public/css',
    'assets/css',
];

$rules = [
    [
        'id' => 'php_inline_style_attr',
        'description' => 'Inline style attribute inside PHP/HTML templates',
        'extensions' => ['php', 'html'],
        'pattern' => '/\bstyle\s*=\s*([\'"]).*?\1/i',
    ],
    [
        'id' => 'js_style_assignment',
        'description' => 'Direct style mutation in JS (style.*= or cssText=)',
        'extensions' => ['js'],
        'pattern' => '/\.\s*style\s*\.\s*(?:cssText|[A-Za-z_]\w*)\s*=/',
    ],
    [
        'id' => 'js_inline_style_attr_in_markup',
        'description' => 'Inline style attribute generated inside JS markup strings',
        'extensions' => ['js'],
        'pattern' => '/style\s*=\s*([\'"]).*?\1/i',
    ],
    [
        'id' => 'css_hardcoded_color_literal',
        'description' => 'Hardcoded color literal in CSS (hex/rgb/hsl)',
        'extensions' => ['css'],
        'pattern' => '/(?:#[0-9A-Fa-f]{3,8}\b|rgba?\([^)]*\)|hsla?\([^)]*\))/',
    ],
];

$cssTokenAuthorityFiles = [
    'public/css/design-system.css',
    'public/css/themes.css',
];

if (!in_array($command, ['scan', 'gate'], true)) {
    fwrite(STDERR, "Invalid command. Use: scan | gate\n");
    exit(2);
}

$files = collectFiles($projectRoot, $targets);
$scan = scanFiles($projectRoot, $files, $rules, $cssTokenAuthorityFiles);

writeJson($resultPath, $scan['result']);
writeReport($reportPath, $scan['result']);

if ($command === 'scan') {
    if ($updateBaseline) {
        $baselinePayload = [
            'generated_at' => date('c'),
            'signature_count' => count($scan['signatures']),
            'signatures' => array_values($scan['signatures']),
        ];
        writeJson($baselinePath, $baselinePayload);
        echo "BASELINE_UPDATED: {$baselinePath}\n";
    }

    echo "AUDIT_DONE: {$reportPath}\n";
    echo "RESULT_JSON: {$resultPath}\n";
    echo "TOTAL_FINDINGS: " . (string)$scan['result']['summary']['total_findings'] . "\n";
    exit(0);
}

// gate mode
if (!is_file($baselinePath)) {
    fwrite(STDERR, "Missing baseline file: {$baselinePath}\n");
    fwrite(STDERR, "Run: php app/Scripts/theme-style-audit.php scan --update-baseline\n");
    exit(2);
}

$baselineRaw = json_decode((string)file_get_contents($baselinePath), true);
if (!is_array($baselineRaw) || !is_array($baselineRaw['signatures'] ?? null)) {
    fwrite(STDERR, "Invalid baseline file format: {$baselinePath}\n");
    exit(2);
}

$baselineSet = [];
foreach ($baselineRaw['signatures'] as $sig) {
    if (is_string($sig) && $sig !== '') {
        $baselineSet[$sig] = true;
    }
}

$newSignatures = [];
foreach ($scan['signatures'] as $sig) {
    if (!isset($baselineSet[$sig])) {
        $newSignatures[$sig] = true;
    }
}

if (count($newSignatures) === 0) {
    echo "THEME_STYLE_GATE: PASS (no new violations)\n";
    exit(0);
}

$newFindings = [];
foreach ($scan['result']['findings'] as $finding) {
    if (isset($newSignatures[$finding['signature']])) {
        $newFindings[] = $finding;
    }
}

echo "THEME_STYLE_GATE: FAIL\n";
echo "NEW_VIOLATIONS: " . count($newFindings) . "\n";
foreach (array_slice($newFindings, 0, 25) as $f) {
    echo "- {$f['rule']} | {$f['file']}:{$f['line']} | {$f['snippet']}\n";
}
if (count($newFindings) > 25) {
    echo "... and " . (count($newFindings) - 25) . " more\n";
}
exit(1);

/**
 * @return list<string>
 */
function collectFiles(string $root, array $targets): array
{
    $files = [];
    foreach ($targets as $target) {
        $absolute = $root . '/' . $target;
        if (is_file($absolute)) {
            $files[] = $absolute;
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
            if (str_contains($path, '/vendor/') || str_contains($path, '/.git/') || str_contains($path, '/node_modules/')) {
                continue;
            }
            $files[] = $item->getPathname();
        }
    }

    sort($files);
    return array_values(array_unique($files));
}

function scanFiles(string $root, array $files, array $rules, array $cssTokenAuthorityFiles): array
{
    $findings = [];
    $ruleCounts = [];
    $signatures = [];

    foreach ($files as $absolutePath) {
        $relativePath = ltrim(str_replace('\\', '/', str_replace($root, '', $absolutePath)), '/');
        $extension = strtolower((string)pathinfo($absolutePath, PATHINFO_EXTENSION));
        $lines = @file($absolutePath, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            continue;
        }

        foreach ($rules as $rule) {
            if (!in_array($extension, $rule['extensions'], true)) {
                continue;
            }

            if ($rule['id'] === 'css_hardcoded_color_literal' && in_array($relativePath, $cssTokenAuthorityFiles, true)) {
                continue;
            }

            foreach ($lines as $lineNumber => $line) {
                if ($line === '' || str_starts_with(trim($line), '//')) {
                    continue;
                }

                if (!preg_match_all($rule['pattern'], $line, $matches)) {
                    continue;
                }

                foreach (($matches[0] ?? []) as $match) {
                    $snippet = normalizeSnippet($match);
                    if ($snippet === '') {
                        continue;
                    }

                    $signatureSource = $rule['id'] . '|' . $relativePath . '|' . $snippet;
                    $signature = sha1($signatureSource);
                    $signatures[$signature] = $signature;

                    $ruleCounts[$rule['id']] = ($ruleCounts[$rule['id']] ?? 0) + 1;

                    $findings[] = [
                        'rule' => $rule['id'],
                        'description' => $rule['description'],
                        'file' => $relativePath,
                        'line' => $lineNumber + 1,
                        'snippet' => $snippet,
                        'signature' => $signature,
                    ];
                }
            }
        }
    }

    usort($findings, static function (array $a, array $b): int {
        return [$a['file'], $a['line'], $a['rule']] <=> [$b['file'], $b['line'], $b['rule']];
    });

    arsort($ruleCounts);

    $result = [
        'generated_at' => date('c'),
        'summary' => [
            'total_files_scanned' => count($files),
            'total_findings' => count($findings),
            'rule_counts' => $ruleCounts,
        ],
        'findings' => $findings,
    ];

    return [
        'result' => $result,
        'signatures' => $signatures,
    ];
}

function normalizeSnippet(string $raw): string
{
    $snippet = trim($raw);
    $snippet = preg_replace('/\s+/', ' ', $snippet) ?? $snippet;
    if (strlen($snippet) > 180) {
        $snippet = substr($snippet, 0, 177) . '...';
    }
    return $snippet;
}

function writeJson(string $path, array $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Failed to encode JSON for ' . $path);
    }
    file_put_contents($path, $json . PHP_EOL);
}

function writeReport(string $path, array $result): void
{
    $summary = $result['summary'] ?? [];
    $ruleCounts = $summary['rule_counts'] ?? [];
    $findings = $result['findings'] ?? [];

    $byFile = [];
    foreach ($findings as $f) {
        $file = $f['file'];
        $byFile[$file] = ($byFile[$file] ?? 0) + 1;
    }
    arsort($byFile);

    $lines = [];
    $lines[] = '# تقرير تدقيق الثيمات والستايلات — WBGL';
    $lines[] = '';
    $lines[] = 'تاريخ التوليد: ' . ($result['generated_at'] ?? date('c'));
    $lines[] = '';
    $lines[] = '## الملخص';
    $lines[] = '';
    $lines[] = '- الملفات المفحوصة: ' . (string)($summary['total_files_scanned'] ?? 0);
    $lines[] = '- إجمالي المخالفات: ' . (string)($summary['total_findings'] ?? 0);
    $lines[] = '';
    $lines[] = '## توزيع المخالفات حسب النوع';
    $lines[] = '';
    foreach ($ruleCounts as $rule => $count) {
        $lines[] = '- `' . $rule . '`: ' . (string)$count;
    }
    if ($ruleCounts === []) {
        $lines[] = '- لا توجد مخالفات.';
    }
    $lines[] = '';
    $lines[] = '## أعلى الملفات مخالفة';
    $lines[] = '';
    $rank = 0;
    foreach ($byFile as $file => $count) {
        $rank++;
        $lines[] = $rank . '. `' . $file . '` — ' . $count;
        if ($rank >= 15) {
            break;
        }
    }
    if ($byFile === []) {
        $lines[] = '1. لا توجد مخالفات.';
    }

    $lines[] = '';
    $lines[] = '## عينة من المخالفات (أول 30)';
    $lines[] = '';
    $sample = array_slice($findings, 0, 30);
    foreach ($sample as $item) {
        $lines[] = '- `' . $item['rule'] . '` في `' . $item['file'] . ':' . $item['line'] . '` => `' . $item['snippet'] . '`';
    }
    if ($sample === []) {
        $lines[] = '- لا توجد مخالفات.';
    }

    file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL);
}

