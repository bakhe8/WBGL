<?php
/**
 * ADR-007 Implementation: Arabic Level B Suggestions Service
 * 
 * AUTHORITY: Implements ADR-007 (Entity Anchor-Based Only)
 * 
 * GOLDEN RULE (enforced in code):
 * if (count($anchors) === 0) {
 *     return [];  // Silent rejection - no suggestion without anchor
 * }
 */

namespace App\Services\Suggestions;

use PDO;
use App\Support\Logger;

/**
 * Arabic Level B Suggestions Service
 * 
 * Generates Level B suggestions for Arabic supplier names
 * using Entity Anchor-Based approach ONLY.
 */
class ArabicLevelBSuggestions
{
    private PDO $db;
    private ArabicEntityAnchorExtractor $anchorExtractor;
    
    /**
     * Minimum confidence score to return a suggestion
     */
    private const MIN_CONFIDENCE = 70;
    
    /**
     * Maximum number of suppliers an anchor can match to be "unique"
     */
    private const UNIQUENESS_THRESHOLD = 3;
    
    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->anchorExtractor = new ArabicEntityAnchorExtractor();
    }
    
    /**
     * Find Level B suggestions for Arabic supplier name
     * 
     * GOLDEN RULE ENFORCEMENT: Returns empty array if no entity anchors found
     * 
     * @param string $normalized Normalized Arabic supplier name
     * @param int $limit Maximum suggestions to return
     * @return array Suggestions with metadata
     */
    public function find(string $normalized, int $limit = 5): array
    {
        // GOLDEN RULE: Extract entity anchors first
        $tokens = explode(' ', $normalized);
        $anchors = $this->anchorExtractor->extract($normalized);
        
        // GOLDEN RULE: No anchors = silent rejection
        if (empty($anchors)) {
            // Log for audit trail
            $this->logSilentRejection($normalized, 'no_entity_anchors');
            return [];
        }
        
        // Extract activity words for scoring support
        $activityWords = $this->anchorExtractor->extractActivityWords($tokens);
        
        // Search for suppliers matching anchors
        $suggestions = [];
        
        foreach ($anchors as $anchor) {
            $matches = $this->searchByAnchor($anchor);
            
            foreach ($matches as $supplier) {
                $score = $this->scoreMatch(
                    $supplier['normalized_name'],
                    $anchors,
                    $activityWords,
                    $anchor
                );
                
                if ($score >= self::MIN_CONFIDENCE) {
                    $suggestions[] = [
                        'supplier_id' => $supplier['id'],
                        'official_name' => $supplier['official_name'],
                        'english_name' => $supplier['english_name'],
                        'level' => 'B',
                        'confidence' => $score,
                        'source' => 'arabic_entity_anchor',
                        'matched_anchor' => $anchor,
                        'reason_ar' => "تطابق اسم تجاري مميز: '{$anchor}'",
                        'requires_confirmation' => true,
                        'is_unique_anchor' => $this->isUniqueAnchor($anchor)
                    ];
                }
            }
        }
        
        // Deduplicate by supplier_id
        $suggestions = $this->deduplicateBySupplier($suggestions);
        
        // Sort by confidence (highest first)
        usort($suggestions, fn($a, $b) => $b['confidence'] - $a['confidence']);
        
        // Return top N
        return array_slice($suggestions, 0, $limit);
    }
    
    /**
     * Search suppliers by anchor
     * 
     * @param string $anchor Entity anchor to search for
     * @return array Matching suppliers
     */
    /**
     * Search suppliers by anchor (Exact + Fuzzy)
     * 
     * @param string $anchor Entity anchor to search for
     * @return array Matching suppliers
     */
    private function searchByAnchor(string $anchor): array
    {
        // 1. Exact Match (Fastest & Most Accurate)
        $stmt = $this->db->prepare("
            SELECT 
                id,
                official_name,
                english_name,
                normalized_name
            FROM suppliers
            WHERE normalized_name LIKE ?
            LIMIT 20
        ");
        
        $stmt->execute(['%' . $anchor . '%']);
        $exactMatches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // If we have enough exact matches, strict to them for performance
        if (count($exactMatches) >= 5) {
            return $exactMatches;
        }
        
        // 2. Fuzzy Match (Fallback/Expansion)
        // Optimization: Relaxed search (removed first-char check because anchor might be in middle)
        // Only rely on length heuristics to limit the scan set.
        
        $minLen = mb_strlen($anchor) - 3; // Allow slightly shorter
        $maxLen = mb_strlen($anchor) + 15; // Allow longer (company prefix + suffixes)
        
        $stmtFuzzy = $this->db->prepare("
            SELECT 
                id,
                official_name,
                english_name,
                normalized_name
            FROM suppliers
            WHERE LENGTH(normalized_name) >= :minLen
            LIMIT 500
        ");
        
        $stmtFuzzy->bindValue(':minLen', max(3, $minLen), PDO::PARAM_INT);
        $stmtFuzzy->execute();
        $candidates = $stmtFuzzy->fetchAll(PDO::FETCH_ASSOC);
        
        // echo "DEBUG: Found " . count($candidates) . " candidates for fuzzy scan (MinLen: " . max(3, $minLen) . ")\n";
        
        $fuzzyMatches = [];
        foreach ($candidates as $cand) {
            // Avoid duplicates with exact matches
            if ($this->inArrayById($cand['id'], $exactMatches)) continue;
            
            // Check fuzzy similarity against each word in the supplier name
            // Lazy load helper if needed
            if (!function_exists('mb_levenshtein')) {
                require_once __DIR__ . '/../../Support/mb_levenshtein.php';
            }

            $words = explode(' ', $cand['normalized_name']);
            foreach ($words as $word) {
                // Must be at least 4 chars to attempt fuzzy
                if (mb_strlen($word) < 4) continue;

                $dist = mb_levenshtein($anchor, $word);
                $len = max(mb_strlen($anchor), mb_strlen($word));
                if ($len == 0) continue;
                
                $similarity = 1 - ($dist / $len);
                
                // DEBUG:
                // echo "DEBUG: Anchor=$anchor vs Word=$word | Dist=$dist | Sim=$similarity\n";

                // Threshold matches:
                // "النورس" (6) vs "النوارس" (7). Dist=1. Sim = 1 - 1/7 = 85%. (Passes > 80%)
                // "موسسة" (5) vs "مؤسسة" (5). Dist=1? No, normalization handles this.
                // "عبدالرحمن" (9) vs "عبد الرحمن" (10). Dist=wait space removed.
                
                if ($similarity >= 0.70) { // Lowered to 70% based on testing
                    $cand['fuzzy_score'] = round($similarity * 100);
                    $cand['matched_anchor'] = $anchor; // Track which anchor matched
                    $fuzzyMatches[] = $cand;
                    break; // Matched one word, good enough
                }
            }
        }
        
        return array_merge($exactMatches, $fuzzyMatches);
    }

    private function inArrayById($id, $array) {
        foreach ($array as $item) {
            if ($item['id'] == $id) return true;
        }
        return false;
    }
    
    /**
     * Check if anchor is unique (appears in <= 3 suppliers)
     * 
     * Rule 5: Uniqueness scoring
     * 
     * @param string $anchor Anchor to check
     * @return bool True if unique
     */
    private function isUniqueAnchor(string $anchor): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) 
            FROM suppliers 
            WHERE normalized_name LIKE ?
        ");
        
        $stmt->execute(['%' . $anchor . '%']);
        $count = $stmt->fetchColumn();
        
        return $count <= self::UNIQUENESS_THRESHOLD;
    }
    
    /**
     * Score a match based on anchors and activity words
     * 
     * Scoring formula from ADR-007:
     * - 2+ unique anchors: 95%
     * - 1 unique anchor + activity: 90%
     * - 1 unique anchor: 85%
     * - 2+ generic anchors: 80%
     * - 1 generic anchor + activity: 75%
     * - 1 generic anchor: 70%
     * - No anchor match: 0 (reject)
     * 
     * @param string $supplierName Supplier normalized name
     * @param array $anchors All entity anchors from input
     * @param array $activityWords Activity words from input
     * @param string $matchedAnchor The anchor that matched this supplier
     * @return int Confidence score (0-100)
     */
    private function scoreMatch(
        string $supplierName,
        array $anchors,
        array $activityWords,
        string $matchedAnchor
    ): int {
        $uniqueAnchorsMatched = 0;
        $genericAnchorsMatched = 0;
        $activityWordsMatched = 0;
        
        // Count matched anchors
        foreach ($anchors as $anchor) {
            if (mb_stripos($supplierName, $anchor) !== false) {
                if ($this->isUniqueAnchor($anchor)) {
                    $uniqueAnchorsMatched++;
                } else {
                    $genericAnchorsMatched++;
                }
            }
        }
        
        // Count matched activity words
        foreach ($activityWords as $word) {
            if (mb_stripos($supplierName, $word) !== false) {
                $activityWordsMatched++;
            }
        }
        
        // Scoring logic (from ADR-007)
        if ($uniqueAnchorsMatched >= 2) {
            return 95;
        }
        
        if ($uniqueAnchorsMatched == 1 && $activityWordsMatched >= 1) {
            return 90;
        }
        
        if ($uniqueAnchorsMatched == 1) {
            return 85;
        }
        
        if ($genericAnchorsMatched >= 2) {
            return 80;
        }
        
        if ($genericAnchorsMatched == 1 && $activityWordsMatched >= 1) {
            return 75;
        }
        
        if ($genericAnchorsMatched == 1) {
            return 70;
        }
        
        return 0;  // No anchor match = reject
    }
    
    /**
     * Deduplicate suggestions by supplier_id
     * Keep highest confidence for each supplier
     * 
     * @param array $suggestions Array of suggestions
     * @return array Deduplicated suggestions
     */
    private function deduplicateBySupplier(array $suggestions): array
    {
        $bySupplier = [];
        
        foreach ($suggestions as $sugg) {
            $id = $sugg['supplier_id'];
            
            if (!isset($bySupplier[$id]) || $sugg['confidence'] > $bySupplier[$id]['confidence']) {
                $bySupplier[$id] = $sugg;
            }
        }
        
        return array_values($bySupplier);
    }
    
    /**
     * Log silent rejection for audit trail
     * 
     * @param string $input Input that was rejected
     * @param string $reason Rejection reason
     */
    private function logSilentRejection(string $input, string $reason): void
    {
        // TODO: Implement audit logging
        // For now, just a comment marker
        // In production, this should log to a file or table
        
        Logger::info('ADR-007 Silent Rejection', [
            'input' => $input,
            'reason' => $reason
        ]);
    }
}
