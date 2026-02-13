<?php

namespace App\Services\Learning\Feeders;

use App\Contracts\SignalFeederInterface;
use App\DTO\SignalDTO;
use App\Services\Suggestions\ArabicEntityExtractor;
use App\Repositories\SupplierRepository;

/**
 * Entity Anchor Signal Feeder
 * 
 * Provides signals based on Arabic entity anchor matching.
 * Extracts entity anchors from input and matches against suppliers.
 * 
 * Signal Types:
 * - 'entity_anchor_unique' (anchor appears in 1-2 suppliers)
 * - 'entity_anchor_generic' (anchor appears in 3+ suppliers)
 * 
 * Note: This extracts logic from ArabicLevelBSuggestions.
 * Does NOT implement "Golden Rule" (Authority decides silence).
 * 
 * Reference: Service Classification Matrix (ArabicLevelBSuggestions refactor)
 */
class AnchorSignalFeeder implements SignalFeederInterface
{
    public function __construct(
        private ArabicEntityExtractor $entityExtractor,
        private SupplierRepository $supplierRepo
    ) {}

    /**
     * Get entity anchor signals
     * 
     * @param string $normalizedInput
     * @return array<SignalDTO>
     */
    public function getSignals(string $normalizedInput): array
    {
        // Extract entity anchors from input
        $anchors = $this->entityExtractor->extractAnchors($normalizedInput);

        // If no anchors, return empty (Authority decides if this means silence)
        if (empty($anchors)) {
            return [];
        }

        $signals = [];
        $anchorFrequencies = $this->calculateAnchorFrequencies($anchors);

        foreach ($anchors as $anchor) {
            // Find suppliers matching this anchor
            $matchingSuppliers = $this->supplierRepo->findByAnchor($anchor);

            $frequency = $anchorFrequencies[$anchor] ?? 0;
            $signalType = $this->determineSignalType($frequency);

            foreach ($matchingSuppliers as $supplier) {
                $signals[] = new SignalDTO(
                    supplier_id: $supplier['id'],
                    signal_type: $signalType,
                    raw_strength: $this->calculateAnchorStrength($frequency),
                    metadata: [
                        'source' => 'entity_anchor',
                        'matched_anchor' => $anchor,
                        'anchor_frequency' => $frequency,
                        'anchor_type' => $this->classifyAnchorType($anchor),
                    ]
                );
            }
        }

        return $signals;
    }

    /**
     * Calculate how many suppliers contain each anchor
     * 
     * @param array $anchors
     * @return array Map of anchor => frequency
     */
    private function calculateAnchorFrequencies(array $anchors): array
    {
        $frequencies = [];

        foreach ($anchors as $anchor) {
            $matchCount = $this->supplierRepo->countSuppliersWithAnchor($anchor);
            $frequencies[$anchor] = $matchCount;
        }

        return $frequencies;
    }

    /**
     * Determine signal type based on anchor frequency
     * 
     * @param int $frequency How many suppliers contain this anchor
     * @return string Signal type
     */
    private function determineSignalType(int $frequency): string
    {
        if ($frequency <= 2) {
            return 'entity_anchor_unique'; // Distinctive anchor
        } else {
            return 'entity_anchor_generic'; // Common anchor (e.g., "شركة", "مؤسسة")
        }
    }

    /**
     * Calculate anchor strength based on frequency
     * 
     * Unique anchors = higher strength
     * Generic anchors = lower strength
     * 
     * @param int $frequency
     * @return float Strength (0.0 - 1.0)
     */
    private function calculateAnchorStrength(int $frequency): float
    {
        if ($frequency === 1) {
            return 1.0; // Perfectly unique
        } elseif ($frequency === 2) {
            return 0.9; // Very distinctive
        } elseif ($frequency <= 5) {
            return 0.7; // Somewhat distinctive
        } else {
            return 0.5; // Generic/common
        }
    }

    /**
     * Classify anchor type (for metadata)
     * 
     * @param string $anchor
     * @return string Type classification
     */
    private function classifyAnchorType(string $anchor): string
    {
        // Simple heuristics
        $commonPrefixes = ['شركة', 'مؤسسة', 'مكتب', 'دار'];
        
        foreach ($commonPrefixes as $prefix) {
            if (str_starts_with($anchor, $prefix)) {
                return 'legal_prefix';
            }
        }

        return 'distinctive_name';
    }
}
