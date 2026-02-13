<?php

namespace App\Services\Learning;

use App\Contracts\SignalFeederInterface;
use App\DTO\SignalDTO;
use App\DTO\SuggestionDTO;
use App\Support\Normalizer;
use App\Support\Logger;

/**
 * Unified Learning Authority
 * 
 * The SINGLE, AUTHORITATIVE source for all supplier suggestions.
 * 
 * Responsibilities:
 * - Normalize input ONCE
 * - Aggregate signals from ALL feeders
 * - Compute confidence using unified formula
 * - Order by confidence
 * - Return SuggestionDTO (canonical format)
 * 
 * Does NOT:
 * - Query database directly (uses feeders)
 * - Make arbitrary decisions (follows Charter rules)
 * - Bypass governance (enforces Database Role Declaration)
 * 
 * Reference: Authority Intent Declaration (all sections)
 * Reference: Charter Part 2, Section 2 (Single Learning Authority)
 */
class UnifiedLearningAuthority
{
    /**
     * @var array<SignalFeederInterface> Registered signal feeders
     */
    private array $feeders = [];

    public function __construct(
        private Normalizer $normalizer,
        private ConfidenceCalculatorV2 $calculator,
        private SuggestionFormatter $formatter
    ) {
        // Feeders will be registered via registerFeeder() or dependency injection
    }

    /**
     * Register a signal feeder
     * 
     * @param SignalFeederInterface $feeder
     * @return self
     */
    public function registerFeeder(SignalFeederInterface $feeder): self
    {
        $this->feeders[] = $feeder;
        return $this;
    }

    /**
     * Get supplier suggestions for raw input
     * 
     * This is the PRIMARY method - the ONLY entry point for suggestions.
     * 
     * Process (Authority Intent Declaration, Section 2.2):
     * 1. Normalize input
     * 2. Gather signals from ALL feeders
     * 3. Aggregate signals by supplier
     * 4. Compute confidence per supplier
     * 5. Filter by minimum threshold
     * 6. Order by confidence descending
     * 7. Format as SuggestionDTO
     * 
     * @param string $rawInput Raw supplier name from user
     * @return array<SuggestionDTO> Ordered suggestions (highest confidence first)
     */
    public function getSuggestions(string $rawInput): array
    {
        // Step 1: Normalize input ONCE (Authority Intent 2.1.1)
        $normalized = $this->normalizer->normalizeSupplierName($rawInput);

        // Step 2: Gather signals from ALL feeders (Authority Intent 2.1.2)
        $allSignals = $this->gatherSignals($normalized);

        // If no signals, apply Silence Rule (Authority Intent 1.3)
        if (empty($allSignals)) {
            return []; // Silent return - no valid signals exist
        }

        // Step 3: Aggregate signals by supplier (Authority Intent 2.2, Step 3)
        $candidatesBySupplier = $this->aggregateBySupplier($allSignals);

        // Step 4: Compute confidence per supplier (Authority Intent 2.2, Step 4)
        $candidates = $this->computeConfidenceScores($candidatesBySupplier);

        // Step 5: Filter by minimum threshold (Authority Intent 2.2, Step 5)
        $candidates = array_filter($candidates, function($candidate) {
            return $this->calculator->meetsDisplayThreshold($candidate['confidence']);
        });

        // Step 6: Order by confidence descending (Authority Intent 2.2, Step 6)
        usort($candidates, fn($a, $b) => $b['confidence'] <=> $a['confidence']);

        // Step 7: Format as SuggestionDTO (Authority Intent 2.2, Step 7)
        $suggestions = array_map(
            fn($candidate) => $this->formatter->toDTO($candidate),
            $candidates
        );

        // Filter out null results (deleted suppliers)
        $suggestions = array_filter($suggestions, fn($s) => $s !== null);

        return array_values($suggestions); // Re-index array
    }

    /**
     * Gather signals from all registered feeders
     * 
     * @param string $normalized
     * @return array<SignalDTO> All signals from all feeders
     */
    private function gatherSignals(string $normalized): array
    {
        $allSignals = [];

        foreach ($this->feeders as $feeder) {
            try {
                $feederSignals = $feeder->getSignals($normalized);
                $allSignals = array_merge($allSignals, $feederSignals);
            } catch (\Exception $e) {
                // Log error but continue with other feeders
                Logger::error('Feeder error', [
                    'feeder' => get_class($feeder),
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $allSignals;
    }

    /**
     * Aggregate signals by supplier ID
     * 
     * @param array<SignalDTO> $signals
     * @return array<int, array> Map of supplier_id => ['signals' => SignalDTO[], 'metadata' => ...]
     */
    private function aggregateBySupplier(array $signals): array
    {
        $candidatesBySupplier = [];

        foreach ($signals as $signal) {
            $supplierId = $signal->supplier_id;

            if (!isset($candidatesBySupplier[$supplierId])) {
                $candidatesBySupplier[$supplierId] = [
                    'supplier_id' => $supplierId,
                    'signals' => [],
                    'confirmation_count' => 0,
                    'rejection_count' => 0,
                ];
            }

            $candidatesBySupplier[$supplierId]['signals'][] = $signal;

            // Accumulate learning counts from signal metadata
            if (isset($signal->metadata['confirmation_count'])) {
                $candidatesBySupplier[$supplierId]['confirmation_count'] += $signal->metadata['confirmation_count'];
            }
            if (isset($signal->metadata['rejection_count'])) {
                $candidatesBySupplier[$supplierId]['rejection_count'] += $signal->metadata['rejection_count'];
            }
        }

        return $candidatesBySupplier;
    }

    /**
     * Compute confidence scores for each supplier candidate
     * 
     * @param array $candidatesBySupplier
     * @return array Candidates with computed confidence
     */
    private function computeConfidenceScores(array $candidatesBySupplier): array
    {
        $candidates = [];

        foreach ($candidatesBySupplier as $supplierId => $data) {
            $signals = $data['signals'];
            $confirmations = $data['confirmation_count'];
            $rejections = $data['rejection_count'];

            // Calculate confidence using Charter formula
            $confidence = $this->calculator->calculate($signals, $confirmations, $rejections);

            // Assign level based on confidence
            $level = $this->calculator->assignLevel($confidence);

            // Identify primary signal (for provenance)
            $primarySignal = $this->identifyPrimarySignal($signals);

            $candidates[] = [
                'supplier_id' => $supplierId,
                'confidence' => $confidence,
                'level' => $level,
                'signals' => $signals,
                'confirmation_count' => $confirmations,
                'rejection_count' => $rejections,
                'primary_source' => $primarySignal->signal_type,
                'signal_count' => count($signals),
                'is_ambiguous' => $this->detectAmbiguity($signals),
            ];
        }

        return $candidates;
    }

    /**
     * Identify the primary signal (highest base score)
     * 
     * @param array<SignalDTO> $signals
     * @return SignalDTO
     */
    private function identifyPrimarySignal(array $signals): SignalDTO
    {
        // Use same logic as ConfidenceCalculatorV2
        // For now, return first (will be refined)
        return $signals[0];
    }

    /**
     * Detect if supplier has ambiguous/conflicting signals
     * 
     * @param array<SignalDTO> $signals
     * @return bool
     */
    private function detectAmbiguity(array $signals): bool
    {
        // Simple heuristic: if signals have very different strengths
        $strengths = array_map(fn($s) => $s->raw_strength, $signals);
        $min = min($strengths);
        $max = max($strengths);

        return ($max - $min) > 0.4; // Significant variance
    }
}
