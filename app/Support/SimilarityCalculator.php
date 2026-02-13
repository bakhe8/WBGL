<?php
declare(strict_types=1);

namespace App\Support;

/**
 * =============================================================================
 * SimilarityCalculator - حساب التشابه بين النصوص
 * =============================================================================
 * 
 * الغرض:
 * ------
 * تجميع جميع دوال حساب التشابه في مكان موحد لتجنب التكرار وتسهيل الصيانة.
 * 
 * دوال متوفرة:
 * -------------
 * 1. fastLevenshteinRatio() - نسخة سريعة للاستيراد (بدون فحص حدود)
 * 2. safeLevenshteinRatio() - نسخة آمنة للواجهات (مع فحص حدود 255 بايت)
 * 3. tokenJaccardSimilarity() - تشابه Jaccard للكلمات (للنصوص الطويلة)
 * 
 * متى تستخدم كل دالة:
 * --------------------
 * 
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │ fastLevenshteinRatio()                                              │
 * │ • الاستخدام: ImportService, MatchingService (أثناء الاستيراد)     │
 * │ • السبب: أداء عالي + النصوص مضمونة القصر من Excel                │
 * │ • تحذير: لا تستخدم مع نصوص > 255 بايت!                            │
 * └─────────────────────────────────────────────────────────────────────┘
 * 
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │ safeLevenshteinRatio()                                              │
 * │ • الاستخدام: CandidateService, DictionaryController (واجهة)       │
 * │ • السبب: قد يدخل المستخدم نصوص طويلة يدوياً                       │
 * │ • الحماية: تحوّل للـ Jaccard تلقائياً إذا تجاوز 255 بايت          │
 * └─────────────────────────────────────────────────────────────────────┘
 * 
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │ tokenJaccardSimilarity()                                            │
 * │ • الاستخدام: Fallback للنصوص الطويلة                              │
 * │ • السبب: Levenshtein محدود بـ 255 بايت في PHP                     │
 * │ • الدقة: أقل من Levenshtein لكن يعمل مع أي طول                   │
 * └─────────────────────────────────────────────────────────────────────┘
 * 
 * مثال الاستخدام:
 * --------------
 * ```php
 * use App\Support\SimilarityCalculator;
 * 
 * // في ImportService (استيراد - نصوص قصيرة):
 * $score = SimilarityCalculator::fastLevenshteinRatio($input, $candidate);
 * 
 * // في CandidateService (واجهة - نصوص قد تكون طويلة):
 * $score = SimilarityCalculator::safeLevenshteinRatio($input, $candidate);
 * ```
 * 
 * =============================================================================
 */
class SimilarityCalculator
{
    /**
     * نسخة سريعة - للاستيراد فقط
     * 
     * ⚠️ تحذير: لا تستخدم مع نصوص > 255 بايت
     * ⚠️ تحذير: النصوص القادمة من Excel مضمونة القصر، لا تستخدم في الواجهات
     * 
     * @param string $a النص الأول (مطبّع)
     * @param string $b النص الثاني (مطبّع)
     * @return float نسبة التشابه من 0.0 إلى 1.0
     */
    public static function fastLevenshteinRatio(string $a, string $b): float
    {
        // حالات خاصة سريعة
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b) {
            return 1.0;
        }

        // حساب Levenshtein مباشرة (بدون فحص حدود)
        $dist = levenshtein($a, $b);
        if ($dist === -1) {
            // خطأ: النص أطول من 255 بايت (لا يجب أن يحدث في الاستيراد)
            return 0.0;
        }

        $maxLen = max(strlen($a), strlen($b));
        return 1.0 - ($dist / $maxLen);
    }

    /**
     * نسخة آمنة - للواجهات والبحث اليدوي
     * 
     * ✅ آمن للاستخدام مع أي طول
     * ✅ يتحول تلقائياً إلى Jaccard للنصوص الطويلة
     * 
     * @param string $a النص الأول (مطبّع)
     * @param string $b النص الثاني (مطبّع)
     * @return float نسبة التشابه من 0.0 إلى 1.0
     */
    public static function safeLevenshteinRatio(string $a, string $b): float
    {
        // حالات خاصة سريعة
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b) {
            return 1.0;
        }

        // فحص حدود PHP levenshtein (255 بايت)
        if (strlen($a) > 255 || strlen($b) > 255) {
            // استخدام Jaccard كبديل للنصوص الطويلة
            return self::tokenJaccardSimilarity($a, $b);
        }

        // حساب Levenshtein
        $dist = levenshtein($a, $b);
        if ($dist === -1) {
            // في حال حدث خطأ غير متوقع
            return self::tokenJaccardSimilarity($a, $b);
        }

        $maxLen = max(strlen($a), strlen($b));
        return 1.0 - ($dist / $maxLen);
    }

    /**
     * تشابه Jaccard على مستوى الكلمات
     * 
     * يستخدم كبديل للنصوص الطويلة أو عند فشل Levenshtein
     * 
     * الخوارزمية:
     * intersection(tokens_A, tokens_B) / union(tokens_A, tokens_B)
     * 
     * @param string $a النص الأول
     * @param string $b النص الثاني
     * @return float نسبة التشابه من 0.0 إلى 1.0
     */
    public static function tokenJaccardSimilarity(string $a, string $b): float
    {
        // تقسيم إلى كلمات
        $tokensA = array_filter(preg_split('/\s+/u', mb_strtolower($a)));
        $tokensB = array_filter(preg_split('/\s+/u', mb_strtolower($b)));

        if (count($tokensA) === 0 || count($tokensB) === 0) {
            return 0.0;
        }

        // حساب التقاطع والاتحاد
        $intersection = count(array_intersect($tokensA, $tokensB));
        $union = count(array_unique(array_merge($tokensA, $tokensB)));

        return $union > 0 ? $intersection / $union : 0.0;
    }
}
