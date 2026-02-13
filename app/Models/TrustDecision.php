<?php
namespace App\Models;

/**
 * Trust Decision Model
 * 
 * Represents a comprehensive trust evaluation decision with reasoning.
 * This replaces the simple boolean "trusted/not trusted" logic with
 * an explainable decision that includes context and reasoning.
 */
class TrustDecision
{
    public bool $allowed;
    public string $reason;
    public ?array $blockingAlias;
    public int $confidence;

    /**
     * Reasons for trust decisions
     */
    public const REASON_HIGH_CONFIDENCE = 'HIGH_CONFIDENCE';
    public const REASON_TRUSTED_SOURCE = 'TRUSTED_SOURCE';
    public const REASON_OVERRIDDEN_BY_TRUST = 'OVERRIDDEN_BY_TRUST';
    public const REASON_ALIAS_CONFLICT = 'ALIAS_CONFLICT';
    public const REASON_LOW_CONFIDENCE = 'LOW_CONFIDENCE';

    public function __construct(
        bool $allowed,
        string $reason,
        int $confidence,
        ?array $blockingAlias = null
    ) {
        $this->allowed = $allowed;
        $this->reason = $reason;
        $this->confidence = $confidence;
        $this->blockingAlias = $blockingAlias;
    }

    /**
     * Factory: Create a decision to allow auto-match
     */
    public static function allow(string $reason, int $confidence): self
    {
        return new self(true, $reason, $confidence, null);
    }

    /**
     * Factory: Create a decision to block auto-match with a culprit
     */
    public static function block(string $reason, int $confidence, ?array $culprit = null): self
    {
        return new self(false, $reason, $confidence, $culprit);
    }

    /**
     * Factory: Trust override (allowed despite blocking alias)
     */
    public static function override(int $confidence, array $culprit): self
    {
        return new self(true, self::REASON_OVERRIDDEN_BY_TRUST, $confidence, $culprit);
    }

    /**
     * Check if this decision should trigger targeted negative learning
     */
    public function shouldApplyTargetedPenalty(): bool
    {
        return $this->allowed 
            && $this->reason === self::REASON_OVERRIDDEN_BY_TRUST
            && $this->blockingAlias !== null;
    }
}
