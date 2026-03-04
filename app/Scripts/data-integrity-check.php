<?php
declare(strict_types=1);

/**
 * WBGL Data Integrity Checker
 *
 * Usage:
 *   php app/Scripts/data-integrity-check.php
 *   php app/Scripts/data-integrity-check.php --output-json=storage/logs/data-integrity-report.json --output-md=storage/logs/data-integrity-report.md
 *   php app/Scripts/data-integrity-check.php --strict-warn
 */

require_once __DIR__ . '/../Support/autoload.php';

use App\Support\Database;

/**
 * @return array<int,array<string,mixed>>
 */
function wbgl_di_fetch_all(\PDO $db, string $sql): array
{
    $stmt = $db->query($sql);
    if ($stmt === false) {
        return [];
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

function wbgl_di_fetch_int(\PDO $db, string $sql): int
{
    $stmt = $db->query($sql);
    if ($stmt === false) {
        return 0;
    }
    return (int)$stmt->fetchColumn();
}

function wbgl_di_table_exists(\PDO $db, string $driver, string $table): bool
{
    if ($driver === 'pgsql') {
        $stmt = $db->prepare("
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = 'public'
              AND table_name = :table_name
            LIMIT 1
        ");
        if ($stmt === false) {
            return false;
        }
        $stmt->execute([':table_name' => $table]);
        return $stmt->fetchColumn() !== false;
    }

    if ($driver === 'sqlite') {
        $stmt = $db->prepare("
            SELECT 1
            FROM sqlite_master
            WHERE type = 'table'
              AND name = :table_name
            LIMIT 1
        ");
        if ($stmt === false) {
            return false;
        }
        $stmt->execute([':table_name' => $table]);
        return $stmt->fetchColumn() !== false;
    }

    return true;
}

/**
 * @param array<int,array<string,mixed>> $rows
 */
function wbgl_di_render_markdown(
    string $generatedAt,
    string $driver,
    string $status,
    int $failViolations,
    int $warnViolations,
    int $strictWarnMode,
    array $rows
): string {
    $md = [];
    $md[] = '# WBGL Data Integrity Report';
    $md[] = '';
    $md[] = '- Generated At: `' . $generatedAt . '`';
    $md[] = '- Driver: `' . $driver . '`';
    $md[] = '- Status: **' . strtoupper($status) . '**';
    $md[] = '- Fail violations: `' . $failViolations . '`';
    $md[] = '- Warn violations: `' . $warnViolations . '`';
    $md[] = '- Strict warn mode: `' . ($strictWarnMode === 1 ? 'ON' : 'OFF') . '`';
    $md[] = '';
    $md[] = '| Check ID | Severity | Status | Violations | Title |';
    $md[] = '|---|---|---|---:|---|';
    foreach ($rows as $row) {
        $md[] = '| `' . (string)($row['id'] ?? '') . '` | '
            . strtoupper((string)($row['severity'] ?? '')) . ' | '
            . strtoupper((string)($row['status'] ?? '')) . ' | '
            . (int)($row['violations'] ?? 0) . ' | '
            . (string)($row['title'] ?? '') . ' |';
    }
    $md[] = '';
    return implode(PHP_EOL, $md) . PHP_EOL;
}

try {
    /** @var array<string,string|false>|false $options */
    $options = getopt('', ['output-json::', 'output-md::', 'strict-warn']);
    $defaultOutputDir = dirname(__DIR__, 2) . '/storage/logs';
    $outputJson = is_array($options) && isset($options['output-json']) && is_string($options['output-json'])
        ? $options['output-json']
        : $defaultOutputDir . '/data-integrity-report.json';
    $outputMd = is_array($options) && isset($options['output-md']) && is_string($options['output-md'])
        ? $options['output-md']
        : $defaultOutputDir . '/data-integrity-report.md';
    $strictWarn = is_array($options) && array_key_exists('strict-warn', $options);

    $db = Database::connect();
    $driver = Database::currentDriver();

    /** @var array<int,array{id:string,title:string,severity:string,sql:string,requires?:string}> $checks */
    $checks = [
        [
            'id' => 'DECISION_ORPHAN_GUARANTEE',
            'title' => 'guarantee_decisions must reference existing guarantees',
            'severity' => 'fail',
            'sql' => "
                SELECT COUNT(*) AS c
                FROM guarantee_decisions d
                LEFT JOIN guarantees g ON g.id = d.guarantee_id
                WHERE g.id IS NULL
            ",
        ],
        [
            'id' => 'READY_REQUIRES_SUPPLIER_BANK',
            'title' => 'ready decisions must have both supplier_id and bank_id',
            'severity' => 'fail',
            'sql' => "
                SELECT COUNT(*) AS c
                FROM guarantee_decisions
                WHERE status = 'ready'
                  AND (supplier_id IS NULL OR bank_id IS NULL)
            ",
        ],
        [
            'id' => 'RELEASED_REQUIRES_LOCK',
            'title' => 'released decisions must stay locked',
            'severity' => 'fail',
            'sql' => "
                SELECT COUNT(*) AS c
                FROM guarantee_decisions
                WHERE status = 'released'
                  AND COALESCE(is_locked, FALSE) = FALSE
            ",
        ],
        [
            'id' => 'ACTIVE_ACTION_DOMAIN',
            'title' => 'active_action must stay within approved domain',
            'severity' => 'fail',
            'sql' => "
                SELECT COUNT(*) AS c
                FROM guarantee_decisions
                WHERE active_action IS NOT NULL
                  AND TRIM(active_action) <> ''
                  AND active_action NOT IN ('extension', 'reduction', 'release')
            ",
        ],
        [
            'id' => 'WORKFLOW_STEP_DOMAIN',
            'title' => 'workflow_step must stay within approved domain',
            'severity' => 'fail',
            'sql' => "
                SELECT COUNT(*) AS c
                FROM guarantee_decisions
                WHERE workflow_step IS NULL
                   OR TRIM(workflow_step) = ''
                   OR workflow_step NOT IN ('draft', 'audited', 'analyzed', 'supervised', 'approved', 'signed')
            ",
        ],
        [
            'id' => 'DECISION_STATUS_DOMAIN',
            'title' => 'decision status must stay within approved domain',
            'severity' => 'fail',
            'sql' => "
                SELECT COUNT(*) AS c
                FROM guarantee_decisions
                WHERE status IS NULL
                   OR TRIM(status) = ''
                   OR status NOT IN ('pending', 'ready', 'released')
            ",
        ],
        [
            'id' => 'SIGNATURES_NON_NEGATIVE',
            'title' => 'signatures_received must never be negative',
            'severity' => 'fail',
            'sql' => "
                SELECT COUNT(*) AS c
                FROM guarantee_decisions
                WHERE signatures_received IS NULL
                   OR signatures_received < 0
            ",
        ],
        [
            'id' => 'UNDO_STATUS_DOMAIN',
            'title' => 'undo_requests status values must stay valid',
            'severity' => 'fail',
            'sql' => "
                SELECT COUNT(*) AS c
                FROM undo_requests
                WHERE status IS NULL
                   OR TRIM(status) = ''
                   OR status NOT IN ('pending', 'approved', 'rejected', 'executed')
            ",
        ],
        [
            'id' => 'UNDO_ORPHAN_GUARANTEE',
            'title' => 'undo_requests must reference existing guarantees',
            'severity' => 'fail',
            'sql' => "
                SELECT COUNT(*) AS c
                FROM undo_requests ur
                LEFT JOIN guarantees g ON g.id = ur.guarantee_id
                WHERE g.id IS NULL
            ",
        ],
        [
            'id' => 'HISTORY_ORPHAN_GUARANTEE',
            'title' => 'guarantee_history must reference existing guarantees',
            'severity' => 'fail',
            'sql' => "
                SELECT COUNT(*) AS c
                FROM guarantee_history h
                LEFT JOIN guarantees g ON g.id = h.guarantee_id
                WHERE g.id IS NULL
            ",
        ],
        [
            'id' => 'OCCURRENCE_ORPHAN_GUARANTEE',
            'title' => 'guarantee_occurrences must reference existing guarantees',
            'severity' => 'fail',
            'sql' => "
                SELECT COUNT(*) AS c
                FROM guarantee_occurrences o
                LEFT JOIN guarantees g ON g.id = o.guarantee_id
                WHERE g.id IS NULL
            ",
        ],
        [
            'id' => 'ROLE_PERMISSION_ORPHANS',
            'title' => 'role_permissions rows must reference existing roles and permissions',
            'severity' => 'fail',
            'sql' => "
                SELECT COUNT(*) AS c
                FROM role_permissions rp
                LEFT JOIN roles r ON r.id = rp.role_id
                LEFT JOIN permissions p ON p.id = rp.permission_id
                WHERE r.id IS NULL OR p.id IS NULL
            ",
        ],
        [
            'id' => 'USER_PERMISSION_ORPHANS',
            'title' => 'user_permissions rows must reference existing users and permissions',
            'severity' => 'fail',
            'sql' => "
                SELECT COUNT(*) AS c
                FROM user_permissions up
                LEFT JOIN users u ON u.id = up.user_id
                LEFT JOIN permissions p ON p.id = up.permission_id
                WHERE u.id IS NULL OR p.id IS NULL
            ",
        ],
        [
            'id' => 'HISTORY_EMPTY_EVENT_DETAILS',
            'title' => 'history rows with blank event_details',
            'severity' => 'warn',
            'sql' => "
                SELECT COUNT(*) AS c
                FROM guarantee_history
                WHERE event_details IS NULL
                   OR TRIM(event_details) = ''
            ",
        ],
        [
            'id' => 'PRINT_EVENT_ORPHAN_GUARANTEE',
            'title' => 'print_events with non-null missing guarantee_id',
            'severity' => 'warn',
            'requires' => 'print_events',
            'sql' => "
                SELECT COUNT(*) AS c
                FROM print_events pe
                LEFT JOIN guarantees g ON g.id = pe.guarantee_id
                WHERE pe.guarantee_id IS NOT NULL
                  AND g.id IS NULL
            ",
        ],
        [
            'id' => 'NOTIFICATION_EMPTY_RECIPIENT',
            'title' => 'notifications must have non-empty recipient_username',
            'severity' => 'warn',
            'requires' => 'notifications',
            'sql' => "
                SELECT COUNT(*) AS c
                FROM notifications
                WHERE recipient_username IS NULL
                   OR TRIM(recipient_username) = ''
            ",
        ],
    ];

    echo 'WBGL Data Integrity Check' . PHP_EOL;
    echo 'Driver: ' . $driver . PHP_EOL;
    echo str_repeat('-', 96) . PHP_EOL;

    $failViolations = 0;
    $warnViolations = 0;
    $reportRows = [];

    foreach ($checks as $check) {
        $requiredTable = trim((string)($check['requires'] ?? ''));
        if ($requiredTable !== '' && !wbgl_di_table_exists($db, $driver, $requiredTable)) {
            $line = sprintf('[SKIP] %-30s | violations=%d | %s', $check['id'], 0, $check['title']);
            echo $line . PHP_EOL;
            $reportRows[] = [
                'id' => $check['id'],
                'title' => $check['title'],
                'severity' => $check['severity'],
                'status' => 'skip',
                'violations' => 0,
                'requires' => $requiredTable,
            ];
            continue;
        }

        $count = wbgl_di_fetch_int($db, $check['sql']);
        $isWarn = $check['severity'] === 'warn';
        $status = ($count === 0) ? 'ok' : ($isWarn ? 'warn' : 'fail');

        $line = sprintf(
            '[%s] %-30s | violations=%d | %s',
            strtoupper($status),
            $check['id'],
            $count,
            $check['title']
        );
        echo $line . PHP_EOL;

        if ($count > 0) {
            if ($isWarn) {
                $warnViolations += $count;
            } else {
                $failViolations += $count;
            }
        }

        $reportRows[] = [
            'id' => $check['id'],
            'title' => $check['title'],
            'severity' => $check['severity'],
            'status' => $status,
            'violations' => $count,
            'requires' => $requiredTable !== '' ? $requiredTable : null,
        ];
    }

    echo str_repeat('-', 96) . PHP_EOL;
    echo 'Fail violations: ' . $failViolations . PHP_EOL;
    echo 'Warn violations: ' . $warnViolations . PHP_EOL;

    $status = 'pass';
    if ($failViolations > 0) {
        $status = 'fail';
    } elseif ($warnViolations > 0) {
        $status = 'warn';
    }

    $generatedAt = gmdate('c');
    $report = [
        'generated_at' => $generatedAt,
        'driver' => $driver,
        'status' => $status,
        'strict_warn_mode' => $strictWarn,
        'summary' => [
            'checks_total' => count($checks),
            'fail_violations' => $failViolations,
            'warn_violations' => $warnViolations,
        ],
        'checks' => $reportRows,
    ];

    $outputJsonDir = dirname($outputJson);
    if (!is_dir($outputJsonDir) && !mkdir($outputJsonDir, 0777, true) && !is_dir($outputJsonDir)) {
        throw new RuntimeException('Failed to create output directory: ' . $outputJsonDir);
    }
    $outputMdDir = dirname($outputMd);
    if (!is_dir($outputMdDir) && !mkdir($outputMdDir, 0777, true) && !is_dir($outputMdDir)) {
        throw new RuntimeException('Failed to create output directory: ' . $outputMdDir);
    }

    file_put_contents(
        $outputJson,
        json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL
    );
    file_put_contents(
        $outputMd,
        wbgl_di_render_markdown(
            $generatedAt,
            $driver,
            $status,
            $failViolations,
            $warnViolations,
            $strictWarn ? 1 : 0,
            $reportRows
        )
    );

    echo 'JSON report: ' . $outputJson . PHP_EOL;
    echo 'Markdown report: ' . $outputMd . PHP_EOL;

    if ($failViolations > 0 || ($strictWarn && $warnViolations > 0)) {
        fwrite(STDERR, 'Data integrity check failed.' . PHP_EOL);
        exit(1);
    }

    if ($warnViolations > 0) {
        echo 'Data integrity check passed with warnings.' . PHP_EOL;
    } else {
        echo 'Data integrity check passed.' . PHP_EOL;
    }
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Data integrity checker crashed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
