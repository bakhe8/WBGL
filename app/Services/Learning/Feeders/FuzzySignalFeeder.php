<?php

namespace App\Services\Learning\Feeders;

use App\Contracts\SignalFeederInterface;
use App\DTO\SignalDTO;
use App\Repositories\SupplierRepository;

/**
 * Fuzzy Signal Feeder
 * 
 * Provides fuzzy matching signals from official supplier names.
 * Computes similarity but does NOT apply weights or make decisions.
 * 
 * Signal Types:
 * - 'fuzzy_official_strong' (similarity >= 0.85)
 * - 'fuzzy_official_medium' (similarity >= 0.70)
 * - 'fuzzy_official_weak' (similarity >= 0.55)
 * 
 * Note: This extracts logic from SupplierCandidateService.
 * 
 * Reference: Query Pattern Audit, Query #7 (service-layer violation)
 * Reference: Service Classification Matrix (SupplierCandidateService refactor)
 */
class FuzzySignalFeeder implements SignalFeederInterface
{
    /**
     * Minimum similarity threshold to return as signal
     */
    private const MIN_SIMILARITY = 0.55;
    
    /**
     * Generic/common words that should be ignored when checking distinctive keywords
     * These words are too common to be distinctive identifiers
     */
    private const GENERIC_WORDS = [
        'company', 'co', 'corp', 'corporation', 'ltd', 'limited', 'inc',
        'trading', 'contracting', 'establishment', 'group', 'international',
        'medical', 'services', 'general', 'national', 'saudi', 'arabia',
        'for', 'and', 'the', 'of', 'est',
        // Arabic
        'شركة', 'مؤسسة', 'ومؤسسة', 'للتجارة', 'للمقاولات', 'محدودة', 'المحدودة',
        'العامة', 'الدولية', 'الطبية', 'الخدمات', 'السعودية', 'العربية'
    ];

    public function __construct(
        private SupplierRepository $supplierRepo
    ) {}

    /**
     * Get fuzzy matching signals
     * 
     * @param string $normalizedInput
     * @return array<SignalDTO>
     */
    public function getSignals(string $normalizedInput): array
    {
        // Get ALL suppliers (no pre-filtering)
        $allSuppliers = $this->supplierRepo->getAllSuppliers();

        $signals = [];

        foreach ($allSuppliers as $supplier) {
            $supplierNormalized = $supplier['normalized_name'];
            
            // Skip invalid suppliers
            if (empty($supplier['id'])) {
                continue;
            }

            // Calculate similarity
            $similarity = $this->calculateSimilarity($normalizedInput, $supplierNormalized);

            // Only return if meets minimum threshold
            if ($similarity >= self::MIN_SIMILARITY) {
                // ✅ CRITICAL VALIDATION: Check for distinctive keyword match
                // This prevents false positives like "GULF HORIZON" matching "TREATMENT OCEAN"
                // even if they share generic words like "MEDICAL" or "INTERNATIONAL"
                if (!$this->hasDistinctiveKeywordMatch($normalizedInput, $supplierNormalized)) {
                    // Skip this match - no distinctive keywords in common
                    // This filters out matches based purely on generic words
                    continue;
                }
                
                // Determine signal type based on similarity strength
                $signalType = $this->determineSignalType($similarity);

                $signals[] = new SignalDTO(
                    supplier_id: $supplier['id'],
                    signal_type: $signalType,
                    raw_strength: $similarity, // Raw similarity score (NO weighting)
                    metadata: [
                        'source' => 'fuzzy_official',
                        'match_method' => 'levenshtein',
                        'similarity' => $similarity,
                        'matched_name' => $supplierNormalized,
                    ]
                );
            }
        }

        return $signals;
    }

    /**
     * Calculate similarity between two normalized strings
     * 
     * Uses Levenshtein distance normalized to 0-1 range
     * 
     * @param string $str1
     * @param string $str2
     * @return float Similarity (0.0 - 1.0)
     */
    private function calculateSimilarity(string $str1, string $str2): float
    {
        if ($str1 === $str2) {
            return 1.0; // Exact match
        }

        $maxLength = max(mb_strlen($str1), mb_strlen($str2));
        
        if ($maxLength === 0) {
            return 0.0;
        }

        $distance = levenshtein($str1, $str2);
        if ($distance < 0) {
            return 0.0; // Guardrail: invalid distance should not produce a perfect match
        }

        // Convert distance to similarity (0-1)
        $similarity = 1 - ($distance / $maxLength);

        return max(0.0, min(1.0, $similarity));
    }

    /**
     * Determine signal type based on similarity strength
     * 
     * @param float $similarity
     * @return string Signal type
     */
    private function determineSignalType(float $similarity): string
    {
        if ($similarity >= 0.85) {
            return 'fuzzy_official_strong';
        } elseif ($similarity >= 0.70) {
            return 'fuzzy_official_medium';
        } else {
            return 'fuzzy_official_weak';
        }
    }
    
    /**
     * Check if two names share at least one distinctive keyword
     * 
     * This is CRITICAL to prevent false matches like:
     * "GULF HORIZON INTERNATIONAL MEDICAL" matching "TREATMENT OCEAN MEDICAL CO."
     * 
     * Strategy:
     * 1. Extract all words from both names
     * 2. Filter out generic/common words (MEDICAL, INTERNATIONAL, CO, etc.)
     * 3. Check if at least ONE distinctive word matches
     * 
     * Example:
     * - Input: "gulf horizon international medical"
     * - Supplier: "treatment ocean medical co"
     * - Input distinctive: [gulf, horizon]
     * - Supplier distinctive: [treatment, ocean]
     * - Common: [] → NO MATCH ✅ Prevents false positive!
     * 
     * @param string $input Normalized input name
     * @param string $supplierName Normalized supplier name
     * @return bool True if at least one distinctive keyword matches
     */
    private function hasDistinctiveKeywordMatch(string $input, string $supplierName): bool
    {
        // Extract words (split by spaces, ignore empty)
        $inputWords = array_filter(explode(' ', strtolower($input)));
        $supplierWords = array_filter(explode(' ', strtolower($supplierName)));
        
        // Filter out generic words from both
        $inputDistinctive = array_diff($inputWords, self::GENERIC_WORDS);
        $supplierDistinctive = array_diff($supplierWords, self::GENERIC_WORDS);
        
        // If either has NO distinctive words (all generic), allow match
        // This handles edge cases like "Company for Medical Services"
        if (empty($inputDistinctive) || empty($supplierDistinctive)) {
            return true;
        }
        
        // Check if at least ONE distinctive word is common
        $commonDistinctive = array_intersect($inputDistinctive, $supplierDistinctive);
        
        return count($commonDistinctive) > 0;
    }
}
