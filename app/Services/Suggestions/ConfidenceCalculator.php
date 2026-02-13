<?php
declare(strict_types=1);

namespace App\Services\Suggestions;

/**
 * ConfidenceCalculator
 * 
 * Implements scoring logic from ADR-009
 */
class ConfidenceCalculator
{
    /**
     * Calculate final confidence score
     * 
     * @param string $source 'entity_anchor', 'learned', 'historical'
     * @param int $confirmationCount Number of user confirmations
     * @param int $historicalCount Number of historical selections
     * @param int $rejectionCount Number of user rejections
     * @return int 0-100
     */
    public function calculate(string $source, int $confirmationCount, int $historicalCount, int $rejectionCount = 0): int
    {
        $base = $this->getBaseConfidence($source);
        $boost = $this->getConfirmationBoost($confirmationCount);
        $histBoost = $this->getHistoricalBoost($historicalCount);
        
        $penalty = $rejectionCount * 33.4;
        
        $score = $base + $boost + $histBoost - $penalty;
        
        return (int)max(0, min(100, $score));
    }
    
    /**
     * Determine Level (B, C, D) based on confidence
     */
    public function getLevel(int $score): ?string
    {
        if ($score >= 85) return 'B';
        if ($score >= 65) return 'C';
        if ($score >= 40) return 'D';
        return null; // Don't show
    }

    private function getBaseConfidence(string $source): int
    {
        return match($source) {
            'entity_anchor' => 85,
            'learned' => 65,
            'historical' => 40,
            default => 0
        };
    }
    
    private function getConfirmationBoost(int $count): int
    {
        if ($count >= 3) return 15;
        if ($count === 2) return 10;
        if ($count === 1) return 5;
        return 0;
    }
    
    private function getHistoricalBoost(int $count): int
    {
        // Only apply if not already boosted by confirmations?
        // ADR says "Level D: 40-60%", so max boost 20.
        if ($count >= 5) return 20;
        if ($count >= 3) return 10;
        return 0;
    }
}
