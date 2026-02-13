<?php
declare(strict_types=1);

namespace App\Support;

/**
 * TypeNormalizer
 * 
 * Standardizes guarantee types from various inputs (Excel, Paste, OCR)
 * into unified Arabic terminology.
 */
class TypeNormalizer
{
    public static function normalize(?string $input): string
    {
        if (empty($input)) {
            return 'غير محدد'; // Don't guess if empty
        }

        $normalized = mb_strtoupper(trim($input));

        // 🎯 1. Final (نهائي) - Handles: FINAL, Final, نهائي, نهائى, نهائ, ضمان نهائي, etc.
        // Also handling 'Performance' (حسن تنفيذ) mapping here if desired, but separating is better.
        // User explicitly asked for robust matching of: نهائي, نهائى, نهائ, FINAL, Final
        
        // Check for specific "Performance" / "حسن تنفيذ" first to avoid capturing it as Final if they are distinct
        if (preg_match('/(PERFORMANCE|حسن\s*تنفيذ)/iu', $normalized)) {
             return 'حسن تنفيذ';
        }

        // Now catch all "Final" variations including typos
        if (preg_match('/(FINAL|نهائي|نهائى|نهائ|أخير|اخير)/iu', $normalized)) {
            return 'نهائي';
        }

        // 🎯 2. Initial (ابتدائي) - Handles: INITIAL, Initial, BID, TENDER, ابتدائي, إبتدائي, أولي, اولي
        if (preg_match('/(INITIAL|BID|TENDER|ابتدائي|إبتدائي|أولي|اولي|PROVISIONAL)/iu', $normalized)) {
            return 'ابتدائي';
        }

        // 🎯 3. Advance Payment (دفعة مقدمة) - Handles: ADVANCE, ADV, دفعة مقدمة, مقدمة, دفعة
        if (preg_match('/(ADVANCE|ADV|دفعة\s*مقدمة|مقدمة)/iu', $normalized)) {
            return 'دفعة مقدمة';
        }

        // 🎯 4. Retention (محجوز ضمان) - Handles: RETENTION, محجوز, ضمان محجوز
        if (preg_match('/(RETENTION|محجوز)/iu', $normalized)) {
            return 'محجوز ضمان';
        }
        
        // 🎯 5. Maintenance (صيانة)
         if (preg_match('/(MAINTENANCE|صيانة)/iu', $normalized)) {
            return 'صيانة';
        }

        // Fallback: Return original or default
        return $input;
    }
}
