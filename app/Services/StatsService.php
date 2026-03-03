<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * StatsService
 *
 * Provides statistics about guarantees (ready/pending/released counts)
 *
 * @version 1.0
 */
class StatsService
{
    /**
     * Actionable workflow counts per stage (only truly actionable records).
     *
     * Mirrors actionable filter semantics used in index.php:
     * - not locked (not released)
     * - decision status is ready
     * - no active action already chosen
     * - only workflow stages that can be advanced
     *
     * @return array<string,int>
     */
    private static function getActionableWorkflowStats(PDO $db): array
    {
        $filter = NavigationService::buildFilterConditions('actionable', null, null);

        $query = $db->prepare('
            SELECT
                d.workflow_step,
                COUNT(*) as count
            FROM guarantees g
            LEFT JOIN guarantee_decisions d ON d.guarantee_id = g.id
            WHERE 1=1
            ' . $filter['sql'] . '
            GROUP BY d.workflow_step
        ');

        $query->execute($filter['params']);
        $results = $query->fetchAll(PDO::FETCH_KEY_PAIR);

        return [
            'draft' => (int)($results['draft'] ?? 0),
            'audited' => (int)($results['audited'] ?? 0),
            'analyzed' => (int)($results['analyzed'] ?? 0),
            'supervised' => (int)($results['supervised'] ?? 0),
            'approved' => (int)($results['approved'] ?? 0),
        ];
    }

    /**
     * Get import statistics
     *
     * Returns counts for all guarantee statuses:
     * - total: All guarantees
     * - ready: Has supplier AND bank (not released)
     * - pending: Missing supplier OR bank (not released)
     * - released: Locked guarantees
     *
     * @param PDO $db Database connection
     * @return array Stats: ['total' => int, 'ready' => int, 'pending' => int, 'released' => int]
     */
    public static function getImportStats(PDO $db): array
    {
        // Canonical counts: rely on the same predicate source used by list/navigation.
        $total = NavigationService::countByFilter($db, 'all');
        $ready = NavigationService::countByFilter($db, 'ready');
        $actionable = NavigationService::countByFilter($db, 'actionable');
        $pending = NavigationService::countByFilter($db, 'pending');
        $released = NavigationService::countByFilter($db, 'released');

        return [
            'total' => $total,
            'ready' => $ready,
            'actionable' => $actionable,
            'pending' => $pending,
            'released' => $released
        ];
    }

    /**
     * Get detailed workflow statistics
     * Counts guarantees at each workflow stage
     */
    public static function getWorkflowStats(PDO $db): array
    {
        $where = ' WHERE 1=1 ';
        $params = [];

        $settings = \App\Support\Settings::getInstance();
        if ($settings->isProductionMode()) {
            $where .= ' AND g.is_test_data = 0';
        }
        $visibility = GuaranteeVisibilityService::buildSqlFilter('g', 'd');
        $where .= $visibility['sql'];
        $params = array_merge($params, $visibility['params']);

        $query = $db->prepare('
            SELECT
                d.workflow_step,
                COUNT(*) as count
            FROM guarantees g
            LEFT JOIN guarantee_decisions d ON d.guarantee_id = g.id
            ' . $where . '
            GROUP BY d.workflow_step
        ');

        $query->execute($params);
        $results = $query->fetchAll(PDO::FETCH_KEY_PAIR);

        // Ensure default stages exist in the array
        return [
            'draft' => (int)($results['draft'] ?? 0),
            'audited' => (int)($results['audited'] ?? 0),
            'analyzed' => (int)($results['analyzed'] ?? 0),
            'supervised' => (int)($results['supervised'] ?? 0),
            'approved' => (int)($results['approved'] ?? 0),
            'signed' => (int)($results['signed'] ?? 0),
            'none' => (int)($results[''] ?? 0) // items not yet in decisions
        ];
    }

    /**
     * Get a single number representing tasks for a specific user
     */
    public static function getPersonalTaskCount(PDO $db): int
    {
        $stats = self::getActionableWorkflowStats($db);
        $count = 0;

        $stagePermissions = [
            'draft' => 'audit_data',
            'audited' => 'analyze_guarantee',
            'analyzed' => 'supervise_analysis',
            'supervised' => 'approve_decision',
            'approved' => 'sign_letters',
        ];

        foreach ($stagePermissions as $stage => $permission) {
            if (\App\Support\Guard::has($permission)) {
                $count += $stats[$stage] ?? 0;
            }
        }

        return $count;
    }

    /**
     * Get a breakdown of tasks by responsibility
     * Returns array of ['label' => string, 'count' => int, 'stage' => string]
     */
    public static function getPersonalTaskBreakdown(PDO $db): array
    {
        $stats = self::getActionableWorkflowStats($db);
        $breakdown = [];

        $stageConfigs = [
            'draft' => ['label' => 'مهام التدقيق', 'permission' => 'audit_data'],
            'audited' => ['label' => 'مهام التحليل', 'permission' => 'analyze_guarantee'],
            'analyzed' => ['label' => 'مهام الإشراف', 'permission' => 'supervise_analysis'],
            'supervised' => ['label' => 'مهام الاعتماد', 'permission' => 'approve_decision'],
            'approved' => ['label' => 'مهام التوقيع', 'permission' => 'sign_letters'],
        ];

        foreach ($stageConfigs as $stage => $config) {
            if (\App\Support\Guard::has($config['permission'])) {
                $count = $stats[$stage] ?? 0;
                if ($count > 0) {
                    $breakdown[] = [
                        'label' => $config['label'],
                        'count' => $count,
                        'stage' => $stage
                    ];
                }
            }
        }

        return $breakdown;
    }
}
