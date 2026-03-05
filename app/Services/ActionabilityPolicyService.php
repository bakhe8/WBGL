<?php

declare(strict_types=1);

namespace App\Services;

use App\DTO\PolicyResultDTO;
use App\Support\Guard;

/**
 * ActionabilityPolicyService
 *
 * Single source of truth for actionable/executable workflow scope.
 */
class ActionabilityPolicyService
{
    /**
     * Workflow stage -> required permission.
     *
     * @var array<string,string>
     */
    public const STAGE_PERMISSION_MAP = [
        'draft' => 'audit_data',
        'audited' => 'analyze_guarantee',
        'analyzed' => 'supervise_analysis',
        'supervised' => 'approve_decision',
        'approved' => 'sign_letters',
        // Post-sign operational handoff:
        // signed records return to data-entry operations (printing/follow-up).
        'signed' => 'manage_data',
    ];

    /**
     * @param string[]|null $permissions
     * @return string[]
     */
    public static function allowedStages(?array $permissions = null): array
    {
        $effectivePermissions = self::normalizePermissions($permissions ?? Guard::permissions());
        if (in_array('*', $effectivePermissions, true)) {
            return array_keys(self::STAGE_PERMISSION_MAP);
        }

        $stages = [];
        foreach (self::STAGE_PERMISSION_MAP as $stage => $permission) {
            if (in_array($permission, $effectivePermissions, true)) {
                $stages[] = $stage;
            }
        }

        return $stages;
    }

    /**
     * Build canonical SQL predicate for actionable records.
     *
     * Returned SQL always starts with " AND ...".
     *
     * @param string[]|null $permissions
     * @return array{sql:string,params:array<string,mixed>,reasons:array<int,string>}
     */
    public static function buildActionableSqlPredicate(
        string $decisionAlias = 'd',
        ?string $stageFilter = null,
        ?array $permissions = null,
        string $paramPrefix = 'actionable_stage'
    ): array {
        $allowedStages = self::allowedStages($permissions);
        if (empty($allowedStages)) {
            return [
                'sql' => ' AND 1=0',
                'params' => [],
                'reasons' => ['NO_ACTIONABLE_STAGE_PERMISSION'],
            ];
        }

        $params = [];
        $stagePlaceholders = [];
        foreach ($allowedStages as $idx => $stage) {
            $key = $paramPrefix . '_' . $idx;
            $stagePlaceholders[] = ':' . $key;
            $params[$key] = $stage;
        }

        $sql = " AND {$decisionAlias}.status = 'ready'"
            . " AND ({$decisionAlias}.active_action IS NOT NULL AND {$decisionAlias}.active_action <> '')"
            . " AND {$decisionAlias}.workflow_step IN (" . implode(',', $stagePlaceholders) . ')';

        $reasons = [];
        if ($stageFilter !== null && trim($stageFilter) !== '') {
            if (in_array($stageFilter, $allowedStages, true)) {
                $filterKey = $paramPrefix . '_filter';
                $sql .= " AND {$decisionAlias}.workflow_step = :{$filterKey}";
                $params[$filterKey] = $stageFilter;
            } else {
                $sql .= ' AND 1=0';
                $reasons[] = 'INVALID_STAGE_FILTER';
            }
        }

        return [
            'sql' => $sql,
            'params' => $params,
            'reasons' => $reasons,
        ];
    }

    /**
     * Evaluate actionable/executable decision for a decision row.
     *
     * @param array<string,mixed> $decisionRow
     * @param string[]|null $permissions
     */
    public static function evaluate(
        array $decisionRow,
        bool $isVisible = true,
        ?array $permissions = null
    ): PolicyResultDTO {
        $reasons = [];
        if (!$isVisible) {
            return new PolicyResultDTO(false, false, false, ['NOT_VISIBLE']);
        }

        $allowedStages = self::allowedStages($permissions);
        if (empty($allowedStages)) {
            $reasons[] = 'NO_ACTIONABLE_STAGE_PERMISSION';
        }

        $status = trim((string)($decisionRow['status'] ?? ''));
        $stage = trim((string)($decisionRow['workflow_step'] ?? ''));
        $activeAction = trim((string)($decisionRow['active_action'] ?? ''));
        $isLocked = self::asBool($decisionRow['is_locked'] ?? false);

        $actionable = true;
        if ($isLocked) {
            $actionable = false;
            $reasons[] = 'LOCKED_RECORD';
        }
        if ($status !== 'ready') {
            $actionable = false;
            $reasons[] = 'STATUS_NOT_READY';
        }
        if ($activeAction === '') {
            $actionable = false;
            $reasons[] = 'ACTIVE_ACTION_NOT_SET';
        }
        if ($stage === '' || !in_array($stage, $allowedStages, true)) {
            $actionable = false;
            $reasons[] = 'STAGE_NOT_ALLOWED';
        }

        // Current baseline: executable follows actionable.
        $executable = $actionable;

        return new PolicyResultDTO(
            true,
            $actionable,
            $executable,
            $reasons
        );
    }

    /**
     * @param string[] $permissions
     * @return string[]
     */
    private static function normalizePermissions(array $permissions): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn($permission): string => trim((string)$permission),
            $permissions
        ), static fn(string $permission): bool => $permission !== '')));
    }

    private static function asBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            return in_array($normalized, ['1', 'true', 't', 'yes', 'y'], true);
        }
        return false;
    }
}
