<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\GuaranteeDecision;
use App\Support\Guard;

/**
 * WorkflowService
 * Manages the transition of guarantees through different stages
 */
class WorkflowService
{
    // Workflow Stages
    public const STAGE_DRAFT = 'draft';           // Initial state
    public const STAGE_AUDITED = 'audited';       // After Auditor check
    public const STAGE_ANALYZED = 'analyzed';     // After Analyst check
    public const STAGE_SUPERVISED = 'supervised'; // After Supervisor check
    public const STAGE_APPROVED = 'approved';     // After Manager approval
    public const STAGE_SIGNED = 'signed';         // Final state (after signatures)

    // Stage order for easy navigation
    private const STAGE_ORDER = [
        self::STAGE_DRAFT,
        self::STAGE_AUDITED,
        self::STAGE_ANALYZED,
        self::STAGE_SUPERVISED,
        self::STAGE_APPROVED,
        self::STAGE_SIGNED,
    ];

    // Permissions required for each transition (to move TO the stage)
    private const TRANSITION_PERMISSIONS = [
        self::STAGE_AUDITED => 'audit_data',
        self::STAGE_ANALYZED => 'analyze_guarantee',
        self::STAGE_SUPERVISED => 'supervise_analysis',
        self::STAGE_APPROVED => 'approve_decision',
        self::STAGE_SIGNED => 'sign_letters',
    ];

    private const REJECTABLE_STAGES = [
        self::STAGE_DRAFT,
        self::STAGE_AUDITED,
        self::STAGE_ANALYZED,
        self::STAGE_SUPERVISED,
        self::STAGE_APPROVED,
    ];

    /**
     * @return string[]
     */
    public static function advanceableStages(): array
    {
        return [
            self::STAGE_DRAFT,
            self::STAGE_AUDITED,
            self::STAGE_ANALYZED,
            self::STAGE_SUPERVISED,
            self::STAGE_APPROVED,
        ];
    }

    /**
     * Get the next stage for a decision
     */
    public static function getNextStage(string $currentStage): ?string
    {
        $index = array_search($currentStage, self::STAGE_ORDER);
        if ($index === false || $index >= count(self::STAGE_ORDER) - 1) {
            return null;
        }
        return self::STAGE_ORDER[$index + 1];
    }

    /**
     * Check if the current user can perform the next action
     */
    public static function canAdvance(GuaranteeDecision $decision): bool
    {
        $result = self::canAdvanceWithReasons($decision);
        return $result['allowed'];
    }

    /**
     * @return array{allowed:bool,reasons:array<int,string>,next_stage:?string}
     */
    public static function canAdvanceWithReasons(GuaranteeDecision $decision): array
    {
        $reasons = self::validateRecordGuards($decision);
        $nextStage = self::getNextStage($decision->workflowStep);
        if (!$nextStage) {
            $reasons[] = 'NO_NEXT_STAGE';
            return [
                'allowed' => false,
                'reasons' => array_values(array_unique($reasons)),
                'next_stage' => null,
            ];
        }

        $requiredPermission = self::TRANSITION_PERMISSIONS[$nextStage] ?? null;
        if (!$requiredPermission) {
            $reasons[] = 'NEXT_STAGE_PERMISSION_NOT_MAPPED';
            return [
                'allowed' => false,
                'reasons' => array_values(array_unique($reasons)),
                'next_stage' => $nextStage,
            ];
        }

        if (!Guard::has($requiredPermission)) {
            $reasons[] = 'MISSING_PERMISSION_' . strtoupper($requiredPermission);
        }

        return [
            'allowed' => empty($reasons),
            'reasons' => array_values(array_unique($reasons)),
            'next_stage' => $nextStage,
        ];
    }

    public static function requiredPermissionForStage(string $stage): ?string
    {
        return ActionabilityPolicyService::STAGE_PERMISSION_MAP[$stage] ?? null;
    }

    public static function canReject(GuaranteeDecision $decision): bool
    {
        $result = self::canRejectWithReasons($decision);
        return $result['allowed'];
    }

    /**
     * @return array{allowed:bool,reasons:array<int,string>}
     */
    public static function canRejectWithReasons(GuaranteeDecision $decision): array
    {
        $reasons = self::validateRecordGuards($decision);

        if (!in_array($decision->workflowStep, self::REJECTABLE_STAGES, true)) {
            $reasons[] = 'STAGE_NOT_REJECTABLE';
        }

        $requiredPermission = self::requiredPermissionForStage($decision->workflowStep);
        if ($requiredPermission === null) {
            $reasons[] = 'STAGE_PERMISSION_NOT_MAPPED';
        } elseif (!Guard::has($requiredPermission)) {
            $reasons[] = 'MISSING_PERMISSION_' . strtoupper($requiredPermission);
        }

        return [
            'allowed' => empty($reasons),
            'reasons' => array_values(array_unique($reasons)),
        ];
    }

    /**
     * @return array<int,string>
     */
    private static function validateRecordGuards(GuaranteeDecision $decision): array
    {
        $reasons = [];
        if ($decision->isLocked) {
            $reasons[] = 'LOCKED_RECORD';
        }
        if (trim($decision->status) !== 'ready') {
            $reasons[] = 'STATUS_NOT_READY';
        }
        if (trim((string)$decision->activeAction) === '') {
            $reasons[] = 'ACTIVE_ACTION_NOT_SET';
        }
        if (trim($decision->workflowStep) === '') {
            $reasons[] = 'WORKFLOW_STEP_EMPTY';
        }
        return $reasons;
    }

    /**
     * Get button label for the current workflow step
     */
    public static function getActionLabel(string $currentStep): string
    {
        switch ($currentStep) {
            case self::STAGE_DRAFT:
                return "تأكيد التدقيق";
            case self::STAGE_AUDITED:
                return "تأكيد التحليل";
            case self::STAGE_ANALYZED:
                return "مراجعة المشرف";
            case self::STAGE_SUPERVISED:
                return "اعتماد نهائي";
            case self::STAGE_APPROVED:
                return "توقيع الخطاب";
            case self::STAGE_SIGNED:
                return "مكتمل";
            default:
                return "نفذ الإجراء التالي";
        }
    }

    /**
     * Check if multiple signatures are required
     * For now, we assume 1 signature is enough, but can be updated here
     */
    public static function signaturesRequired(): int
    {
        return 1; // Default requirement
    }

    /**
     * @param array<int,string> $reasons
     */
    public static function describeAdvanceDenialReasons(array $reasons): string
    {
        $normalized = array_values(array_unique(array_filter(array_map(
            static fn($reason): string => strtoupper(trim((string)$reason)),
            $reasons
        ), static fn(string $reason): bool => $reason !== '')));

        if ($normalized === []) {
            return 'غير مؤهل لتنفيذ المرحلة التالية';
        }

        $messages = [];
        foreach ($normalized as $reason) {
            if (str_starts_with($reason, 'MISSING_PERMISSION_')) {
                $messages[] = 'لا تملك صلاحية تنفيذ المرحلة التالية';
                continue;
            }

            $messages[] = match ($reason) {
                'LOCKED_RECORD' => 'السجل مقفل',
                'STATUS_NOT_READY' => 'السجل ليس في حالة جاهزة',
                'ACTIVE_ACTION_NOT_SET' => 'لم يتم اختيار إجراء لهذا الضمان',
                'WORKFLOW_STEP_EMPTY' => 'مرحلة السير غير محددة',
                'NO_NEXT_STAGE' => 'لا توجد مرحلة تالية لهذا السجل',
                'NEXT_STAGE_PERMISSION_NOT_MAPPED',
                'STAGE_PERMISSION_NOT_MAPPED' => 'صلاحية المرحلة غير مضبوطة في النظام',
                'STAGE_NOT_REJECTABLE' => 'المرحلة الحالية لا تقبل هذا الإجراء',
                'STAGE_NOT_ALLOWED' => 'المرحلة الحالية ليست ضمن نطاق صلاحياتك',
                default => 'غير مؤهل لتنفيذ المرحلة التالية',
            };
        }

        return implode('، ', array_values(array_unique($messages)));
    }
}
