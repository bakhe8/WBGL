<?php
/**
 * ADR-007 Implementation: Arabic Entity Anchor System
 * 
 * AUTHORITY: This file implements ADR-007 (Entity Anchor-Based Level B)
 * 
 * GOLDEN RULE:
 * No Arabic Level B suggestion is allowed without at least ONE Entity Anchor.
 * Silence is safer than misleading suggestions.
 */

namespace App\Services\Suggestions;

/**
 * Entity Anchor Extractor for Arabic Names
 * 
 * Extracts entity anchors (proper nouns, trade names, person names)
 * from normalized Arabic supplier names.
 */
class ArabicEntityAnchorExtractor
{
    /**
     * Structural words that are NOT entities
     * (Legal forms, organizational descriptors, etc.)
     */
    private const STRUCTURAL_WORDS = [
        // Legal forms
        'شركة', 'شركه', 'مؤسسة', 'مؤسسه', 'مجموعة',
        'المحدودة', 'المحدوده', 'ذمم', 'ذ.م.م', 'ذ.م.م.',
        
        // Organizational
        'فرع', 'مكتب', 'وكالة', 'وكاله',
        
        // Prepositions/connectors  
        'في', 'من', 'إلى', 'على', 'عن', 'مع',
        'لل', 'للـ', 'و', 'الـ', 'ال',
        
        // General descriptors
        'للتجارة', 'التجارية', 'للتقنية', 'للخدمات',
        'للمقاولات', 'للاستيراد', 'للتصدير', 'العامة'
    ];
    
    /**
     * Activity words - describe what the company DOES, not WHO it is
     * Can be used for scoring, but NOT as anchors
     */
    private const ACTIVITY_WORDS = [
        // Sectors (all forms with/without "ال")
        'طبية', 'الطبية', 'طبيه', 'الطبيه',
        'صحية', 'الصحية', 'صحيه', 'الصحيه', 'والصحية',
        'تقنية', 'التقنية', 'تقنيه', 'التقنيه',
        'الكترونية', 'إلكترونية', 'الالكترونية', 'الإلكترونية',
        'علمية', 'العلمية', 'علميه', 'العلميه', 'والعلميه',
        
        // Activities
        'تجارة', 'تجارية', 'التجارية',
        'صناعية', 'الصناعية', 'صناعات', 'الصناعات', 'للصناعات',
        'خدمات', 'الخدمات', 'خدمية', 'الخدمية', 'والخدمات', 'لخدمات',
        'مقاولات', 'المقاولات',
        
        // Products/Equipment
        'مستلزمات', 'المستلزمات', 'للمستلزمات',
        'معدات', 'المعدات', 'للمعدات',
        'أجهزة', 'الأجهزة',
        'لوازم', 'اللوازم', 'للوازم',
        'منتجات', 'المنتجات',
        'تجهيزات', 'التجهيزات', 'للتجهيزات'
    ];
    
    /**
     * Descriptor words - generic commercial/business descriptors
     * NOT entities - must be rejected as anchors
     */
    private const DESCRIPTOR_WORDS = [
        // Commercial descriptors
        'الحلول', 'حلول',
        'المتقدمة', 'متقدمة', 'المتقدمه', 'متقدمه',
        'المتطورة', 'متطورة', 'المتطوره', 'متطوره',
        'الذكية', 'ذكية',
        'الحديثة', 'حديثة', 'الحديثه', 'حديثه',
        'المتكاملة', 'متكاملة', 'المتكامله', 'متكامله',
        'المتسارعة', 'متسارعة', 'المتسارعه', 'متسارعه',
        'العامة', 'عامة', 'عامه', 'العامه',
        'الشركة',  // TOO GENERIC!
        'مؤسسات',  // Plural form
        
        // Business operations
        'للتنمية', 'التنمية',
        'للاستيراد', 'الاستيراد',
        
        // FINAL BLOCKLIST (Phase 3 approved)
        'التجارية', 'التجاريه',  // Commercial descriptor
        'المعلومات'  // Generic IT term
    ];
    
    /**
     * Geographic words - location descriptors, not entities
     * FINAL BLOCKLIST includes: العرب, العربية
     */
    private const GEOGRAPHIC_WORDS = [
        'السعودية', 'السعوديه', 'سعودية',
        'العالمية', 'عالمية',
        'الدولية', 'دولية',
        'العربية', 'عربية', 'عربي', 'العربي',
        'الخليج',  // Unless part of brand name
        'الوسط', 'وسط',
        'الشرق',
        'الغرب',
        
        // FINAL BLOCKLIST (Phase 3 approved)
        'العرب'  // Geographic/ethnic descriptor, NOT entity
    ];
    
    /**
     * Extract entity anchors from normalized Arabic name
     * 
     * @param string $normalized Normalized Arabic supplier name
     * @return array Array of entity anchors found
     */
    public function extract(string $normalized): array
    {
        $tokens = explode(' ', $normalized);
        $anchors = [];
        
        // Rule 1: Single-word entities
        foreach ($tokens as $word) {
            if ($this->isAnchorCandidate($word)) {
                $anchors[] = $word;
            }
        }
        
        // Rule 2: Compound entities (2-word combinations)
        for ($i = 0; $i < count($tokens) - 1; $i++) {
            $compound = $this->tryCompoundAnchor($tokens[$i], $tokens[$i + 1]);
            if ($compound) {
                $anchors[] = $compound;
            }
        }
        
        return array_unique($anchors);
    }
    
    /**
     * Check if a single word is a valid anchor candidate
     * 
     * @param string $word Word to check
     * @return bool True if valid anchor candidate
     */
    private function isAnchorCandidate(string $word): bool
    {
        // Rule 3: Minimum length
        if (mb_strlen($word) < 4) {
            return false;
        }
        
        // Reject structural words
        if (in_array($word, self::STRUCTURAL_WORDS)) {
            return false;
        }
        
        // Reject activity words (they can't be anchors)
        if (in_array($word, self::ACTIVITY_WORDS)) {
            return false;
        }
        
        // Reject descriptor words (MUST-FIX #1)
        if (in_array($word, self::DESCRIPTOR_WORDS)) {
            return false;
        }
        
        // Reject geographic words (MUST-FIX #1)
        if (in_array($word, self::GEOGRAPHIC_WORDS)) {
            return false;
        }
        
        // ANCHOR CLASSIFIER (MUST-FIX #2)
        // Only allow if it passes entity type check
        $anchorType = $this->classifyAnchorType($word);
        if ($anchorType === 'GENERIC' || $anchorType === 'DESCRIPTOR') {
            return false;
        }
        
        return true;
    }
    
    /**
     * Classify anchor type (MUST-FIX #2: Anchor Classifier)
     * 
     * @param string $word Word to classify
     * @return string 'PERSON', 'BRAND', 'GENERIC', or 'DESCRIPTOR'
     */
    private function classifyAnchorType(string $word): string
    {
        // Check against all rejection lists first
        if (in_array($word, self::ACTIVITY_WORDS) ||
            in_array($word, self::DESCRIPTOR_WORDS) ||
            in_array($word, self::GEOGRAPHIC_WORDS) ||
            in_array($word, self::STRUCTURAL_WORDS)) {
            return 'DESCRIPTOR';
        }
        
        // Person name patterns
        if (mb_strpos($word, 'عبد') === 0 && mb_strlen($word) >= 5) {
            return 'PERSON';
        }
        
        // Family prefixes
        $familyWords = ['ابراهيم', 'محمد', 'أحمد', 'خالد', 'سعود', 'فهد', 
                        'عبدالله', 'عبدالرحمن', 'عبداللطيف', 'عبدالعزيز'];
        if (in_array($word, $familyWords)) {
            return 'PERSON';
        }
        
        // If it's a unique, specific name not in rejection lists
        // Assume it's a BRAND (will be validated by uniqueness scoring)
        return 'BRAND';
    }
    
    /**
     * Try to create a compound anchor from two consecutive words
     * 
     * MUST-FIX #3: Compound must be pure entity or rejected entirely
     * 
     * @param string $word1 First word
     * @param string $word2 Second word
     * @return string|null Compound anchor or null if invalid
     */
    private function tryCompoundAnchor(string $word1, string $word2): ?string
    {
        // MUST-FIX #3: Reject if ANY word is generic/descriptor
        $rejectLists = array_merge(
            self::ACTIVITY_WORDS,
            self::DESCRIPTOR_WORDS,
            self::GEOGRAPHIC_WORDS,
            self::STRUCTURAL_WORDS
        );
        
        if (in_array($word1, $rejectLists) || in_array($word2, $rejectLists)) {
            return null;  // Reject compound with any generic word
        }
        
        // Rule 4: Person name patterns
        if ($this->isPersonNamePattern($word1, $word2)) {
            // Even for person names, check classifications
            $type1 = $this->classifyAnchorType($word1);
            $type2 = $this->classifyAnchorType($word2);
            
            if ($type1 === 'PERSON' || $type2 === 'PERSON') {
                return $word1 . ' ' . $word2;
            }
        }
        
        // Generic compound (both must be valid AND classified as entity)
        if ($this->isAnchorCandidate($word1) && $this->isAnchorCandidate($word2)) {
            $type1 = $this->classifyAnchorType($word1);
            $type2 = $this->classifyAnchorType($word2);
            
            // Both must be PERSON or BRAND, not GENERIC or DESCRIPTOR
            if (($type1 === 'PERSON' || $type1 === 'BRAND') &&
                ($type2 === 'PERSON' || $type2 === 'BRAND')) {
                return $word1 . ' ' . $word2;
            }
        }
        
        return null;
    }
    
    /**
     * Check if two words form a person name pattern
     * 
     * @param string $word1 First word
     * @param string $word2 Second word
     * @return bool True if person name pattern
     */
    private function isPersonNamePattern(string $word1, string $word2): bool
    {
        // Pattern: "عبد" + something
        if (mb_strpos($word1, 'عبد') === 0 && mb_strlen($word1) >= 4) {
            return true;
        }
        
        // Family/tribal patterns
        $familyPrefixes = ['بني', 'آل', 'أبو', 'ذوي'];
        if (in_array($word1, $familyPrefixes)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Get activity words from tokens (for scoring support)
     * 
     * @param array $tokens Tokenized supplier name
     * @return array Activity words found
     */
    public function extractActivityWords(array $tokens): array
    {
        $activities = [];
        
        foreach ($tokens as $token) {
            if (in_array($token, self::ACTIVITY_WORDS)) {
                $activities[] = $token;
            }
        }
        
        return $activities;
    }
}
