<?php

namespace App\Contracts;

/**
 * Signal Feeder Interface
 * 
 * All suggestion signal sources MUST implement this interface.
 * Feeders provide RAW signals to UnifiedLearningAuthority.
 * 
 * Feeders MUST NOT:
 * - Compute final confidence scores
 * - Apply decision filtering
 * - Order results by score
 * 
 * Reference: Authority Intent Declaration, Section 2.1
 */
interface SignalFeederInterface
{
    /**
     * Retrieve signals for normalized input
     * 
     * @param string $normalizedInput The normalized supplier name
     * @return array<SignalDTO> Array of signal DTOs
     */
    public function getSignals(string $normalizedInput): array;
}
