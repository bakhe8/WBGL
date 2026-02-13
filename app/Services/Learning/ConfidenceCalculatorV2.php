<?php

namespace App\Services\Learning;

use App\DTO\SignalDTO;

/**
 * Confidence Calculator V2
 * 
 * Implements UNIFIED confidence formula from Charter Part 2, Section 4.
 * This is the SINGLE source of truth for confidence calculation.
 * 
 * Formula:
 * - Base score determined by primary signal type (85-100 for strong signals)
 * - Boosts from confirmations (+5/+10/+15 based on count)
 * - Penalties from rejections (-10 per rejection)
 * - Clamped to 0-100 range
 * 
 * Reference: Charter Part 2, Section 4 (Unified Scoring Semantics)
 * Reference: Authority Intent Declaration, Section 1.2 (Meaning of Confidence)
 */
class ConfidenceCalculatorV2
{
    private \App\Support\Settings $settings;

    public function __construct(?\App\Support\Settings $settings = null)
    {
        $this->settings = $settings ?? new \App\Support\Settings();
        $this->loadBaseScores();
    }

    /**
     * Load base scores from settings
     */
    private function loadBaseScores(): void
    {
        $this->baseScores = [
            'alias_exact' => (int) $this->settings->get('BASE_SCORE_ALIAS_EXACT', 100),
            'entity_anchor_unique' => (int) $this->settings->get('BASE_SCORE_ENTITY_ANCHOR_UNIQUE', 90),
            'entity_anchor_generic' => (int) $this->settings->get('BASE_SCORE_ENTITY_ANCHOR_GENERIC', 75),
            'fuzzy_official_strong' => (int) $this->settings->get('BASE_SCORE_FUZZY_OFFICIAL_STRONG', 85),
            'fuzzy_official_medium' => (int) $this->settings->get('BASE_SCORE_FUZZY_OFFICIAL_MEDIUM', 70),
            'fuzzy_official_weak' => (int) $this->settings->get('BASE_SCORE_FUZZY_OFFICIAL_WEAK', 55),
            'historical_frequent' => (int) $this->settings->get('BASE_SCORE_HISTORICAL_FREQUENT', 60),
            'historical_occasional' => (int) $this->settings->get('BASE_SCORE_HISTORICAL_OCCASIONAL', 45),
        ];
    }

    /**
     * Base scores by signal type (loaded from Settings)
     */
    private array $baseScores = [];

    /**
     * Minimum confidence threshold for display
     * @deprecated Use Settings::MATCH_WEAK_THRESHOLD instead
     */
    private const MIN_DISPLAY_THRESHOLD = 40;

    /**
     * Calculate confidence from aggregated signals
     * 
     * @param array<SignalDTO> $signals All signals for this supplier
     * @param int $confirmationCount Number of confirmations (from learning_confirmations)
     * @param int $rejectionCount Number of rejections
     * @return int Confidence score (0-100)
     */
    public function calculate(array $signals, int $confirmationCount = 0, int $rejectionCount = 0): int
    {
        if (empty($signals)) {
            return 0;
        }

        // 1. Identify primary signal (highest base score)
        $primarySignal = $this->identifyPrimarySignal($signals);

        // 2. Get base score
        $baseScore = $this->getBaseScore($primarySignal->signal_type, $primarySignal->raw_strength);

        // 3. Calculate confirmation boost
        $confirmBoost = $this->calculateConfirmationBoost($confirmationCount);

        // 4. Apply signal strength modifier (for fuzzy signals)
        $strengthModifier = $this->calculateStrengthModifier($primarySignal);

        // 5. Compute base confidence (before rejection penalty)
        $baseConfidence = $baseScore + $confirmBoost + $strengthModifier;
        $baseConfidence = max(0, min(100, $baseConfidence));

        // 6. Apply rejection penalty (25% per rejection)
        $finalConfidence = $this->calculateRejectionPenalty($rejectionCount, $baseConfidence);

        // 7. Clamp to valid range
        return max(0, min(100, $finalConfidence));
    }

    /**
     * Assign level based on confidence (Charter Part 2, Section 4.3)
     * 
     * Uses configurable thresholds from Settings:
     * - LEVEL_B_THRESHOLD (default 85): Minimum for Level B (High confidence)
     * - LEVEL_C_THRESHOLD (default 65): Minimum for Level C (Medium confidence)
     * - Below LEVEL_C_THRESHOLD: Level D (Low confidence)
     * 
     * @param int $confidence Confidence score (0-100)
     * @return string Level ('B', 'C', or 'D')
     */
    public function assignLevel(int $confidence): string
    {
        // Get thresholds from settings (user-configurable)
        $levelBThreshold = (int) $this->settings->get('LEVEL_B_THRESHOLD', 85);
        $levelCThreshold = (int) $this->settings->get('LEVEL_C_THRESHOLD', 65);
        
        if ($confidence >= $levelBThreshold) {
            return 'B'; // High confidence
        } elseif ($confidence >= $levelCThreshold) {
            return 'C'; // Medium confidence
        } else {
            return 'D'; // Low confidence
        }
    }

    /**
     * Check if confidence meets minimum display threshold
     */
    public function meetsDisplayThreshold(int $confidence): bool
    {
        // Use MATCH_REVIEW_THRESHOLD as the cutoff for display logic if strict
        // But traditionally we might show "Low confidence" items
        // Let's use MATCH_WEAK_THRESHOLD if available, or strict floor
        // For now, let's allow showing anything above 40 (hard floor) to avoid empty lists
        // regardless of settings, because user might want to see "Low Confidence" options
        
        return $confidence >= 40; 
    }

    /**
     * Identify the primary signal (highest base score)
     * 
     * @param array<SignalDTO> $signals
     * @return SignalDTO
     */
    private function identifyPrimarySignal(array $signals): SignalDTO
    {
        $primarySignal = null;
        $highestBaseScore = -1;

        foreach ($signals as $signal) {
            $baseScore = $this->baseScores[$signal->signal_type] ?? 0;
            if ($baseScore > $highestBaseScore) {
                $highestBaseScore = $baseScore;
                $primarySignal = $signal;
            }
        }

        return $primarySignal ?? $signals[0];
    }

    /**
     * Get base score for signal type
     */
    private function getBaseScore(string $signalType, float $rawStrength): int
    {
        return $this->baseScores[$signalType] ?? 40; // Default for unknown types
    }

    /**
     * Calculate confirmation boost (configurable via Settings)
     * 
     * Formula:
     * - 1-2 confirmations: +tier1 boost
     * - 3-5 confirmations: +tier2 boost
     * - 6+ confirmations: +tier3 boost
     */
    private function calculateConfirmationBoost(int $count): int
    {
        if ($count === 0) {
            return 0;
        } elseif ($count <= 2) {
            return (int) $this->settings->get('CONFIRMATION_BOOST_TIER1', 5);
        } elseif ($count <= 5) {
            return (int) $this->settings->get('CONFIRMATION_BOOST_TIER2', 10);
        } else {
            return (int) $this->settings->get('CONFIRMATION_BOOST_TIER3', 15);
        }
    }

    /**
     * Calculate rejection penalty (configurable via Settings)
     * 
     * Formula: X% penalty per rejection (multiplicative)
     * Default: 25% penalty means 75% retention (0.75 multiplier)
     * 
     * @param int $count Number of rejections
     * @param int $baseConfidence Base confidence before penalty
     * @return int Final confidence after penalty
     */
    private function calculateRejectionPenalty(int $count, int $baseConfidence): int
    {
        if ($count === 0) {
            return $baseConfidence;
        }
        
        // Get penalty percentage from settings (default 25%)
        $penaltyPercentage = (int) $this->settings->get('REJECTION_PENALTY_PERCENTAGE', 25);
        
        // Calculate retention factor: 100% - penalty% = retention%
        // Example: 25% penalty = 75% retention = 0.75 multiplier
        $retentionFactor = (100 - $penaltyPercentage) / 100;
        
        // Apply penalty multiplicatively: confidence × (retention)^rejectionCount
        $penaltyFactor = pow($retentionFactor, $count);
        
        return (int) ($baseConfidence * $penaltyFactor);
    }

    /**
     * Calculate strength modifier for fuzzy signals
     * 
     * For signals with raw_strength < 1.0, apply proportional adjustment
     */
    private function calculateStrengthModifier(SignalDTO $signal): int
    {
        // Only apply to fuzzy signals
        if (!str_starts_with($signal->signal_type, 'fuzzy_')) {
            return 0;
        }

        // Strength modifier: -10 to +10 based on raw_strength
        // 1.0 = +5, 0.9 = 0, 0.8 = -5, etc.
        return (int) (($signal->raw_strength - 0.9) * 50);
    }
}
