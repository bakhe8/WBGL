<?php

namespace App\Services\Learning\Feeders;

use App\Contracts\SignalFeederInterface;
use App\DTO\SignalDTO;
use App\Repositories\SupplierRepository;
use App\Support\Normalizer;

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
        private SupplierRepository $supplierRepo,
        private Normalizer $normalizer
    ) {}

    /**
     * Get fuzzy matching signals
     * 
     * @param string $normalizedInput
     * @return array<SignalDTO>
     */
    public function getSignals(string $normalizedInput): array
    {
        // Performance pre-filter: narrow fuzzy scan set using distinctive tokens first.
        $inputTokens = $this->extractDistinctiveTokens($normalizedInput);
        $allSuppliers = $this->supplierRepo->getFuzzyCandidatesByTokens($inputTokens, 600);

        $signals = [];

        foreach ($allSuppliers as $supplier) {
            $supplierId = $supplier['id'];
            if (empty($supplierId)) {
                continue;
            }

            // Names to check
            $namesToCheck = array_filter([
                'official' => $supplier['normalized_name'] ?? null,
                'english' => !empty($supplier['english_name']) ? $this->normalizer->normalizeSupplierName($supplier['english_name']) : null
            ]);

            foreach ($namesToCheck as $nameType => $supplierNormalized) {
                // Fast gate: if best possible similarity from length alone is below threshold,
                // skip expensive levenshtein call.
                $inputLen = mb_strlen($normalizedInput);
                $supplierLen = mb_strlen((string)$supplierNormalized);
                $maxLen = max($inputLen, $supplierLen);
                if ($maxLen <= 0) {
                    continue;
                }
                $lengthDiff = abs($inputLen - $supplierLen);
                $maxPossibleSimilarity = 1 - ($lengthDiff / $maxLen);
                if ($maxPossibleSimilarity < self::MIN_SIMILARITY) {
                    continue;
                }

                // Calculate similarity
                $similarity = $this->calculateSimilarity($normalizedInput, $supplierNormalized);

                // Only return if meets minimum threshold
                if ($similarity >= self::MIN_SIMILARITY) {
                    // ✅ CRITICAL VALIDATION: Check for distinctive keyword match
                    if (!$this->hasDistinctiveKeywordMatch($normalizedInput, $supplierNormalized)) {
                        continue;
                    }
                    
                    // Determine signal type based on similarity strength
                    $signalType = $this->determineSignalType($similarity);

                    $signals[] = new SignalDTO(
                        supplier_id: $supplierId,
                        signal_type: $signalType,
                        raw_strength: $similarity,
                        metadata: [
                            'source' => 'fuzzy_official',
                            'match_method' => 'levenshtein',
                            'similarity' => $similarity,
                            'matched_name' => $supplierNormalized,
                            'matched_field' => $nameType === 'english' ? 'english_name' : 'official_name'
                        ]
                    );
                    
                    // If we found a very strong match on one, skip the other name for this supplier
                    if ($similarity >= 0.95) break;
                }
            }
        }

        return $signals;
    }

    /**
     * Calculate similarity between two normalized strings
     * 
     * Uses Unicode-safe Levenshtein distance normalized to 0-1 range.
     * Native levenshtein() is byte-oriented and can mis-score Arabic UTF-8 text.
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

        $chars1 = $this->toUnicodeChars($str1);
        $chars2 = $this->toUnicodeChars($str2);
        $maxLength = max(count($chars1), count($chars2));
        
        if ($maxLength === 0) {
            return 0.0;
        }

        $distance = $this->unicodeLevenshteinDistance($chars1, $chars2);

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
     * Strategy:
     * 1. Extract all words from both names
     * 2. Filter out generic/common words (MEDICAL, INTERNATIONAL, CO, etc.)
     * 3. Check if at least ONE distinctive word matches
     * 
     * @param string $input Normalized input name
     * @param string $supplierName Normalized supplier name
     * @return bool True if at least one distinctive keyword matches
     */
    private function hasDistinctiveKeywordMatch(string $input, string $supplierName): bool
    {
        // Extract words (split by spaces, ignore empty)
        $inputWords = $this->tokenizeWords($input);
        $supplierWords = $this->tokenizeWords($supplierName);
        
        // Filter out generic words from both
        $inputDistinctive = array_diff($inputWords, self::GENERIC_WORDS);
        $supplierDistinctive = array_diff($supplierWords, self::GENERIC_WORDS);
        
        // If both names are generic-only, only allow exact generic equality.
        if (empty($inputDistinctive) && empty($supplierDistinctive)) {
            return trim($input) === trim($supplierName);
        }

        // If one side has no distinctive tokens, do not trust fuzzy similarity alone.
        if (empty($inputDistinctive) || empty($supplierDistinctive)) {
            return false;
        }
        
        // Check if at least ONE distinctive word is common
        $commonDistinctive = array_intersect($inputDistinctive, $supplierDistinctive);
        
        return count($commonDistinctive) > 0;
    }

    /**
     * @return array<int,string>
     */
    private function extractDistinctiveTokens(string $input): array
    {
        $tokens = $this->tokenizeWords($input);
        $distinctive = array_values(array_diff($tokens, self::GENERIC_WORDS));
        return array_values(array_unique(array_filter(
            $distinctive,
            static fn(string $token): bool => mb_strlen($token) >= 3
        )));
    }

    /**
     * @return array<int,string>
     */
    private function tokenizeWords(string $value): array
    {
        $parts = preg_split('/\s+/u', trim(mb_strtolower($value, 'UTF-8')));
        if (!is_array($parts)) {
            return [];
        }

        return array_values(array_filter($parts, static fn(string $token): bool => $token !== ''));
    }

    /**
     * @return array<int,string>
     */
    private function toUnicodeChars(string $value): array
    {
        $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        if (is_array($chars)) {
            return $chars;
        }

        // Fallback for invalid UTF-8 payloads.
        return str_split($value);
    }

    /**
     * @param array<int,string> $a
     * @param array<int,string> $b
     */
    private function unicodeLevenshteinDistance(array $a, array $b): int
    {
        $aLen = count($a);
        $bLen = count($b);

        if ($aLen === 0) {
            return $bLen;
        }
        if ($bLen === 0) {
            return $aLen;
        }

        $previous = range(0, $bLen);
        for ($i = 1; $i <= $aLen; $i++) {
            $current = [$i];
            for ($j = 1; $j <= $bLen; $j++) {
                $cost = ($a[$i - 1] === $b[$j - 1]) ? 0 : 1;
                $current[$j] = min(
                    $current[$j - 1] + 1,     // insertion
                    $previous[$j] + 1,        // deletion
                    $previous[$j - 1] + $cost // substitution
                );
            }
            $previous = $current;
        }

        return (int)$previous[$bLen];
    }
}
