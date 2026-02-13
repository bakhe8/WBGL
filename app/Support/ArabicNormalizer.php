<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Arabic Text Normalizer
 * 
 * Complete implementation - All phases active
 * 
 * Phase 1: Unicode Whitespace Handling (ACTIVE)
 * Phase 2A: Arabic Character Variants (ACTIVE)
 * Phase 2B: Diacritics & Punctuation (ACTIVE)
 */
class ArabicNormalizer
{
    /**
     * Normalize Arabic text for matching
     * 
     * Currently implements: Phase 1 only
     * 
     * @param string $text Input text to normalize
     * @return string Normalized text
     */
    public static function normalize(string $text): string
    {
        if (empty($text)) {
            return '';
        }
        
        // ============================================================
        // PHASE 1: Unicode Whitespace Handling
        // ============================================================
        
        // 1. Replace Unicode Non-Breaking Space (U+00A0)
        // Evidence: Guarantee #6 contains \xC2\xA0
        $text = str_replace("\xC2\xA0", ' ', $text);
        $text = str_replace("\u{00A0}", ' ', $text);
        
        // 2. Replace other Unicode space variants
        // U+202F - Narrow No-Break Space
        // U+2009 - Thin Space
        // U+2007 - Figure Space
        $text = str_replace(["\u{202F}", "\u{2009}", "\u{2007}"], ' ', $text);
        
        // 3. Collapse multiple consecutive spaces into single space
        $text = preg_replace('/\s+/', ' ', $text);
        
        // ============================================================
        // PHASE 2A: Arabic Character Variants (ACTIVE)
        // ============================================================
        
        // Normalize common Arabic character variants
        $arabicVariants = [
            'ى' => 'ي',  // Alef Maksura -> Yeh
            'ة' => 'ه',  // Teh Marbuta -> Heh
            'أ' => 'ا',  // Alef with Hamza above -> Alef
            'إ' => 'ا',  // Alef with Hamza below -> Alef
            'آ' => 'ا',  // Alef with Madda -> Alef
            'ؤ' => 'و',  // Waw with Hamza -> Waw
            'ئ' => 'ي',  // Yeh with Hamza -> Yeh
        ];
        $text = str_replace(array_keys($arabicVariants), array_values($arabicVariants), $text);
        
        // ============================================================
        // PHASE 2B: Diacritics & Punctuation (ACTIVE)
        // ============================================================
        
        // Remove Arabic diacritics (Tashkeel)
        // U+064B to U+065F (Fatha, Damma, Kasra, Sukun, Shadda, etc.)
        $text = preg_replace('/[\x{064B}-\x{065F}]/u', '', $text);
        
        // Remove common punctuation that may interfere with matching
        // Keep: spaces, letters, numbers
        // Remove: ()[]{}،,؛;.!?-_
        $text = preg_replace('/[()[\]{}،,؛;.!?\-_]/u', '', $text);
        
        // ============================================================
        // FINAL: Lowercase and Trim (Always Active)
        // ============================================================
        
        $text = mb_strtolower($text, 'UTF-8');
        $text = trim($text);
        
        return $text;
    }
    
    /**
     * Get current active phase
     * 
     * @return string Current phase identifier
     */
    public static function getCurrentPhase(): string
    {
        return 'Phase 2B: Complete Normalization (All Phases Active)';
    }
    
    /**
     * Check if a specific phase is active
     * 
     * @param int $phase Phase number (1, 2, 3)
     * @return bool Whether the phase is active
     */
    public static function isPhaseActive(int $phase): bool
    {
        return match ($phase) {
            1 => true,   // Phase 1: Unicode Whitespace - ACTIVE
            2 => true,   // Phase 2A: Arabic Variants - ACTIVE
            3 => true,   // Phase 2B: Diacritics - ACTIVE
            default => false
        };
    }
}
