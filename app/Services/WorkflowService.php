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
        $nextStage = self::getNextStage($decision->workflowStep);
        if (!$nextStage) {
            return false;
        }

        $requiredPermission = self::TRANSITION_PERMISSIONS[$nextStage] ?? null;
        if (!$requiredPermission) {
            return false;
        }

        return Guard::has($requiredPermission);
    }

    /**
     * Get button label for the current workflow step
     */
    public static function getActionLabel(string $currentStep): string
    {
        switch ($currentStep) {
            case self::STAGE_DRAFT:
                return "تأكيد التدقيق (Audit)";
            case self::STAGE_AUDITED:
                return "تأكيد التحليل (Analyze)";
            case self::STAGE_ANALYZED:
                return "مراجعة المشرف (Supervise)";
            case self::STAGE_SUPERVISED:
                return "اعتماد نهائي (Approve)";
            case self::STAGE_APPROVED:
                return "توقيع الخطاب (Sign)";
            case self::STAGE_SIGNED:
                return "مكتمل (Signed)";
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
}
