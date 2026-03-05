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
     * Data-entry task count:
     * ready + draft + no active_action selected yet.
     */
    private static function getDataEntryTaskCount(PDO $db, bool $includeTestData = false): int
    {
        return NavigationService::countByFilter($db, 'data_entry', null, null, $includeTestData);
    }

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
    private static function getActionableWorkflowStats(PDO $db, bool $includeTestData = false): array
    {
        $filter = NavigationService::buildFilterConditions('actionable', null, null, $includeTestData);

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
            'signed' => (int)($results['signed'] ?? 0),
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
    public static function getImportStats(PDO $db, bool $includeTestData = false): array
    {
        // Canonical counts: rely on the same predicate source used by list/navigation.
        $total = NavigationService::countByFilter($db, 'all', null, null, $includeTestData);
        $ready = NavigationService::countByFilter($db, 'ready', null, null, $includeTestData);
        $actionable = NavigationService::countByFilter($db, 'actionable', null, null, $includeTestData);
        $pending = NavigationService::countByFilter($db, 'pending', null, null, $includeTestData);
        $released = NavigationService::countByFilter($db, 'released', null, null, $includeTestData);

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
    public static function getWorkflowStats(PDO $db, bool $includeTestData = false): array
    {
        $where = ' WHERE 1=1 ';
        $params = [];

        $settings = \App\Support\Settings::getInstance();
        $allowTestData = $includeTestData && !$settings->isProductionMode();
        if (!$allowTestData) {
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
    public static function getPersonalTaskCount(PDO $db, bool $includeTestData = false): int
    {
        $stats = self::getActionableWorkflowStats($db, $includeTestData);
        $count = 0;

        // Data-entry preparation tasks (before audit flow starts).
        if (\App\Support\Guard::has('manage_data')) {
            $count += self::getDataEntryTaskCount($db, $includeTestData);
        }

        $stagePermissions = [
            'draft' => 'audit_data',
            'audited' => 'analyze_guarantee',
            'analyzed' => 'supervise_analysis',
            'supervised' => 'approve_decision',
            'approved' => 'sign_letters',
            'signed' => 'manage_data',
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
     *
     * @param bool $includeEmptyStages When true, include allowed stages even if count is 0
     */
    public static function getPersonalTaskBreakdown(PDO $db, bool $includeTestData = false, bool $includeEmptyStages = false): array
    {
        $stats = self::getActionableWorkflowStats($db, $includeTestData);
        $breakdown = [];
        $dataEntryTaskCount = self::getDataEntryTaskCount($db, $includeTestData);

        if (\App\Support\Guard::has('manage_data')) {
            if ($includeEmptyStages || $dataEntryTaskCount > 0) {
                $breakdown[] = [
                    'label' => 'مهام مدخل البيانات',
                    'count' => $dataEntryTaskCount,
                    'stage' => null,
                    'filter' => 'data_entry',
                ];
            }
        }

        $stageConfigs = [
            'draft' => ['label' => 'مهام التدقيق', 'permission' => 'audit_data', 'filter' => 'actionable'],
            'audited' => ['label' => 'مهام التحليل', 'permission' => 'analyze_guarantee', 'filter' => 'actionable'],
            'analyzed' => ['label' => 'مهام الإشراف', 'permission' => 'supervise_analysis', 'filter' => 'actionable'],
            'supervised' => ['label' => 'مهام الاعتماد', 'permission' => 'approve_decision', 'filter' => 'actionable'],
            'approved' => ['label' => 'مهام التوقيع', 'permission' => 'sign_letters', 'filter' => 'actionable'],
            'signed' => ['label' => 'مهام الطباعة بعد التوقيع', 'permission' => 'manage_data', 'filter' => 'actionable'],
        ];

        foreach ($stageConfigs as $stage => $config) {
            if (\App\Support\Guard::has($config['permission'])) {
                $count = $stats[$stage] ?? 0;
                if ($includeEmptyStages || $count > 0) {
                    $breakdown[] = [
                        'label' => $config['label'],
                        'count' => $count,
                        'stage' => $stage,
                        'filter' => $config['filter'],
                    ];
                }
            }
        }

        return $breakdown;
    }
}
