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
        // Production Mode: Exclude test data
        $settings = \App\Support\Settings::getInstance();
        $testDataFilter = '';
        if ($settings->isProductionMode()) {
            $testDataFilter = ' WHERE (g.is_test_data = 0 OR g.is_test_data IS NULL)';
        }

        $query = $db->prepare('
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN (d.is_locked IS NULL OR d.is_locked = 0) AND d.status = "ready" THEN 1 ELSE 0 END) as ready,
                SUM(CASE WHEN (d.is_locked IS NULL OR d.is_locked = 0) AND d.status = "ready" AND (d.active_action IS NULL OR d.active_action = "") THEN 1 ELSE 0 END) as actionable,
                SUM(CASE WHEN (d.is_locked IS NULL OR d.is_locked = 0) AND (d.id IS NULL OR d.status != "ready") THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN d.is_locked = 1 THEN 1 ELSE 0 END) as released
            FROM guarantees g
            LEFT JOIN guarantee_decisions d ON d.guarantee_id = g.id
            ' . $testDataFilter . '
        ');

        $query->execute();
        $stats = $query->fetch(PDO::FETCH_ASSOC);

        // Ensure integers (NULL from SUM becomes 0)
        return [
            'total' => (int)$stats['total'],
            'ready' => (int)$stats['ready'],
            'actionable' => (int)($stats['actionable'] ?? 0),
            'pending' => (int)$stats['pending'],
            'released' => (int)$stats['released']
        ];
    }

    /**
     * Get detailed workflow statistics
     * Counts guarantees at each workflow stage
     */
    public static function getWorkflowStats(PDO $db): array
    {
        $settings = \App\Support\Settings::getInstance();
        $testDataFilter = '';
        if ($settings->isProductionMode()) {
            $testDataFilter = ' WHERE (g.is_test_data = 0 OR g.is_test_data IS NULL)';
        }

        $query = $db->prepare('
            SELECT
                d.workflow_step,
                COUNT(*) as count
            FROM guarantees g
            LEFT JOIN guarantee_decisions d ON d.guarantee_id = g.id
            ' . $testDataFilter . '
            GROUP BY d.workflow_step
        ');

        $query->execute();
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
        $stats = self::getWorkflowStats($db);
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
        $stats = self::getWorkflowStats($db);
        $breakdown = [];

        $stageConfigs = [
            'draft' => ['label' => 'بانتظار تدقيقك (Audit)', 'permission' => 'audit_data'],
            'audited' => ['label' => 'بانتظار تحليلك (Analyze)', 'permission' => 'analyze_guarantee'],
            'analyzed' => ['label' => 'بانتظار مراجعتك (Supervise)', 'permission' => 'supervise_analysis'],
            'supervised' => ['label' => 'بانتظار اعتمادك (Approve)', 'permission' => 'approve_decision'],
            'approved' => ['label' => 'بانتظار توقيعك (Sign)', 'permission' => 'sign_letters'],
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
