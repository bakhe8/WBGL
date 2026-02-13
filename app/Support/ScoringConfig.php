<?php
declare(strict_types=1);

namespace App\Support;

/**
 * ScoringConfig - ثوابت نظام التقييم والنجوم
 * 
 * تجميع كل القيم السحرية المستخدمة في:
 * - SupplierCandidateService
 * - SupplierSuggestionRepository
 * - MatchingService
 */
class ScoringConfig
{
    // ═══════════════════════════════════════════════════════════════════
    // STAR RATING THRESHOLDS
    // ═══════════════════════════════════════════════════════════════════
    
    /** الحد الأدنى للحصول على 3 نجوم */
    public const STAR_3_THRESHOLD = 200;
    
    /** الحد الأدنى للحصول على نجمتين */
    public const STAR_2_THRESHOLD = 120;
    
    // ═══════════════════════════════════════════════════════════════════
    // USAGE SCORING
    // ═══════════════════════════════════════════════════════════════════
    
    /** النقاط المكتسبة لكل استخدام */
    public const USAGE_BONUS_PER_USE = 15;
    
    /** الحد الأقصى لنقاط الاستخدام */
    public const USAGE_BONUS_MAX = 75;
    
    // ═══════════════════════════════════════════════════════════════════
    // SOURCE WEIGHTS (أوزان المصادر)
    // ═══════════════════════════════════════════════════════════════════
    
    /** وزن التعلم من قرارات المستخدم */
    public const WEIGHT_LEARNING = 50;
    
    /** وزن التطابق الرسمي من القاموس */
    public const WEIGHT_OFFICIAL = 25;
    
    /** وزن الأسماء البديلة */
    public const WEIGHT_ALTERNATIVE = 15;
    
    /** وزن التطابق الضبابي */
    public const WEIGHT_FUZZY = 0;
    
    // ═══════════════════════════════════════════════════════════════════
    // BLOCK PENALTY
    // ═══════════════════════════════════════════════════════════════════
    
    /** عقوبة الحظر (تُطرح من النقاط) */
    public const BLOCK_PENALTY = 50;
    
    // ═══════════════════════════════════════════════════════════════════
    // MATCHING THRESHOLDS
    // ═══════════════════════════════════════════════════════════════════
    
    /** عتبة التطابق التلقائي (90%) */
    public const AUTO_MATCH_THRESHOLD = 0.9;
    
    /** عتبة التطابق القوي للبنوك (95%) */
    public const BANK_FUZZY_THRESHOLD = 0.95;
    
    // ═══════════════════════════════════════════════════════════════════
    // HELPER METHODS
    // ═══════════════════════════════════════════════════════════════════
    
    /**
     * حساب تقييم النجوم بناءً على مجموع النقاط
     */
    public static function getStarRating(float $totalScore): int
    {
        if ($totalScore >= self::STAR_3_THRESHOLD) return 3;
        if ($totalScore >= self::STAR_2_THRESHOLD) return 2;
        return 1;
    }
    
    /**
     * حساب نقاط الاستخدام (مع الحد الأقصى)
     */
    public static function calculateUsageBonus(int $usageCount): int
    {
        return min($usageCount * self::USAGE_BONUS_PER_USE, self::USAGE_BONUS_MAX);
    }
}
