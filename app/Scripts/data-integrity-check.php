<?php
declare(strict_types=1);

/**
 * WBGL Data Integrity Checker
 *
 * Usage:
 *   php app/Scripts/data-integrity-check.php
 */

require_once __DIR__ . '/../Support/autoload.php';

use App\Support\Database;

try {
    $db = Database::connect();

    /** @var array<int,array{id:string,title:string,severity:string,sql:string}> $checks */
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
            'id' => 'HISTORY_EMPTY_EVENT_DETAILS',
            'title' => 'history rows with blank event_details (warning only)',
            'severity' => 'warn',
            'sql' => "
                SELECT COUNT(*) AS c
                FROM guarantee_history
                WHERE event_details IS NULL
                   OR TRIM(event_details) = ''
            ",
        ],
    ];

    echo 'WBGL Data Integrity Check' . PHP_EOL;
    echo 'Driver: ' . Database::currentDriver() . PHP_EOL;
    echo str_repeat('-', 88) . PHP_EOL;

    $failViolations = 0;
    $warnViolations = 0;

    foreach ($checks as $check) {
        $stmt = $db->query($check['sql']);
        $count = (int)($stmt ? $stmt->fetchColumn() : 0);

        $isWarn = $check['severity'] === 'warn';
        $status = ($count === 0) ? 'OK' : ($isWarn ? 'WARN' : 'FAIL');
        $line = sprintf(
            '[%s] %-30s | violations=%d | %s',
            $status,
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
    }

    echo str_repeat('-', 88) . PHP_EOL;
    echo 'Fail violations: ' . $failViolations . PHP_EOL;
    echo 'Warn violations: ' . $warnViolations . PHP_EOL;

    if ($failViolations > 0) {
        fwrite(STDERR, 'Data integrity check failed.' . PHP_EOL);
        exit(1);
    }

    echo 'Data integrity check passed.' . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Data integrity checker crashed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

