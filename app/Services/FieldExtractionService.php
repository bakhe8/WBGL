<?php
declare(strict_types=1);

namespace App\Services;

/**
 * FieldExtractionService
 * 
 * Extracts guarantee fields from text using regex patterns
 * Handles all field types: guarantee number, amount, dates, supplier, bank, contract
 * 
 * ⚠️ CRITICAL: Patterns copied exactly from parse-paste.php - DO NOT MODIFY
 * 
 * @version 1.0
 */
class FieldExtractionService
{
    /**
     * Extract guarantee number from text
     * Uses 7 different patterns in priority order
     */
    public static function extractGuaranteeNumber(string $text): ?string
    {
        $patterns = [
            // Pattern 1: REF/LG/NO followed by alphanumeric
            '/\b(?:REFERENCE|REF|LG|NO|رقم|الرقم|ر\.ض)[:\h\-#]+([A-Z0-9\-\/]{4,25})/iu',
            // Pattern 2: Specific formats like 040XXXXXX
            '/\b(040[A-Z0-9]{5,})\b/i',
            // Pattern 3: G- or BG- prefix
            '/\b([GB]G?[\-\h]?[A-Z0-9]{5,20})\b/i',
            // Pattern 4: B followed by 6 digits (e.g., B323790)
            '/\b(B[0-9]{6,})\b/i',
            // Pattern 5: Alphanumeric with mix of letters and numbers (at least 8 chars)
            '/\b([0-9]{2,}[A-Z]{2,}[0-9A-Z]{4,})\b/i',
            '/\b([A-Z]{2,}[0-9]{4,}[A-Z0-9]*)\b/',
            // Pattern 6: Arabic "رقم الضمان" followed by value
            '/رقم\h*الضمان[:\h]*([A-Z0-9\-\/]+)/iu',
        ];

        return self::extractWithPatterns($text, $patterns, 'GUARANTEE_NUMBER');
    }

    /**
     * Extract amount from text
     * Returns float value or null
     */
    public static function extractAmount(string $text): ?float
    {
        $patterns = [
            // Pattern 1: With explicit keywords (Amount, مبلغ, Value, SAR)
            '/(?:Amount|مبلغ|القيمة|value|SAR|SR|ر\.س|ريال)[:\h]*([0-9,]+(?:\.[0-9]{2})?)/iu',
            // Pattern 2: Number followed by currency
            '/([0-9,]+(?:\.[0-9]{2})?)\s*(?:SAR|SR|ر\.س|ريال)/iu',
            // Pattern 3: Large numbers (likely amounts) with thousand separators
            '/\b([0-9]{1,3}(?:,[0-9]{3})+(?:\.[0-9]{2})?)\b/',
            // Pattern 4: Any number with decimal point and 2 digits (e.g., 5,989.83)
            '/\b([0-9,]+\.[0-9]{2})\b/',
            // Pattern 5: Simple large numbers (7+ digits) - fallback
            '/\b([0-9]{7,})\b/',
        ];

        $amountStr = self::extractWithPatterns($text, $patterns, 'AMOUNT');

        if ($amountStr) {
            return (float) str_replace(',', '', $amountStr);
        }

        return null;
    }

    /**
     * Extract expiry date from text
     * Normalizes to YYYY-MM-DD format
     * 
     * ✨ ENHANCED: Added support for:
     * - Arabic month names (يناير، فبراير، إلخ)
     * - Dot separator (MM.DD.YYYY)
     * - More flexible patterns
     */
    public static function extractExpiryDate(string $text): ?string
    {
        $patterns = [
            // Pattern 1: YYYY-MM-DD or YYYY/MM/DD
            '/(?:Expiry|Until|تاريخ|انتهاء|الانتهاء|ينتهي)[:\s]*([0-9]{4}[\-\/\.][0-9]{1,2}[\-\/\.][0-9]{1,2})/iu',
            // Pattern 2: DD-MM-YYYY or DD/MM/YYYY or DD.MM.YYYY
            '/(?:Expiry|Until|تاريخ|انتهاء|الانتهاء|ينتهي)[:\s]*([0-9]{1,2}[\-\/\.][0-9]{1,2}[\-\/\.][0-9]{4})/iu',
            // Pattern 3a: Date with English month + 2-digit year (12-Jan-26, 15-Dec-25)
            '/\b([0-9]{1,2}[\-\/\.](Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[\-\/\.]([0-9]{2}))\b/i',
            // Pattern 3b: Date with English month + 4-digit year (6-Jan-2026, 15-Dec-2025)
            '/\b([0-9]{1,2}[\-\/\.](Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[\-\/\.][0-9]{4})\b/i',
            // Pattern 4: Date with Arabic month name (15-يناير-2026)
            '/\b([0-9]{1,2}[\-\/\.](يناير|فبراير|مارس|أبريل|مايو|يونيو|يوليو|أغسطس|سبتمبر|أكتوبر|نوفمبر|ديسمبر)[\-\/\.][0-9]{4})\b/iu',
            // Pattern 5: Just dates in YYYY-MM-DD format (with -, /, or .)
            '/\b([0-9]{4}[\-\/\.][0-9]{1,2}[\-\/\.][0-9]{1,2})\b/',
            // Pattern 6: Just dates in DD-MM-YYYY format (with -, /, or .)
            '/\b([0-9]{1,2}[\-\/\.][0-9]{1,2}[\-\/\.][0-9]{4})\b/',
            // Pattern 7: Compact format YYYYMMDD
            '/\b(20[0-9]{2}[01][0-9][0-3][0-9])\b/',
        ];

        $dateStr = self::extractWithPatterns($text, $patterns, 'EXPIRY_DATE');

        if ($dateStr) {
            // Convert to standard YYYY-MM-DD format
            return self::normalizeDateFormat($dateStr);
        }

        return null;
    }

    /**
     * Extract issue date from text
     */
    public static function extractIssueDate(string $text): ?string
    {
        $patterns = [
            '/(?:Issue|Issued|تاريخ\s*الإصدار|صدر|إصدار)[:\s]*([0-9]{4}[\-\/][0-9]{1,2}[\-\/][0-9]{1,2})/iu',
            '/(?:Issue|Issued|تاريخ\s*الإصدار|صدر|إصدار)[:\s]*([0-9]{1,2}[\-\/][0-9]{1,2}[\-\/][0-9]{4})/iu',
        ];

        $dateStr = self::extractWithPatterns($text, $patterns, 'ISSUE_DATE');

        if ($dateStr) {
            return str_replace('/', '-', $dateStr);
        }

        return null;
    }

    /**
     * Extract supplier name from text
     */
    public static function extractSupplier(string $text): ?string
    {
        $patterns = [
            '/(?:Supplier|Beneficiary|المورد|المستفيد|لصالح)[:\h]*([^\v]+)/iu',
            '/(?:لصالح|ل\s*صالع)[:\h]*([^\v]+)/iu',
            // Include prefix in the capture group
            '/\b(شركة|مؤسسة|مصنع|مركز|مكتب|مقاولات)\h+([^\v،,\.]+)/iu', 
            // Pattern for TAB-separated table
            '/^([A-Z][A-Z\s&]+COMPANY)\s*\t/im',
            '/^([A-Z][A-Z\s&]+(?:COMPANY|CO\.|LTD|LLC|CORPORATION))\s*\t/im',
        ];

        // Custom extraction for supplier because of multiple capture groups in Pattern 3
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                // If we have multiple groups (e.g. prefix + name), join them
                $value = count($m) > 2 ? trim($m[1] . ' ' . $m[2]) : trim($m[1]);
                $safeValue = str_replace(["\n", "\r"], ' ', $value);
                error_log("✅ [SUPPLIER] Matched with pattern: $pattern => $safeValue");
                return preg_replace('/[،,\.]+$/', '', $value);
            }
        }

        return null;
    }

    /**
     * Extract bank name from text
     */
    public static function extractBank(string $text): ?string
    {
        $patterns = [
            // Pattern 1: Keyword followed by text (must NOT be a numeric ID)
            '/(?:Bank|البنك|بنك|مصرف)[:\h]+(?![0-9]{5,10}(?:\h|\v|$))([^\v\h][^\v]*?)(?:\h+[0-9]{5,10})?(?:\h|\v|$)/iu', 
            // Pattern 2: Text BEFORE keyword (must NOT be a numeric ID)
            '/(?:\b|(?<![0-9]))(?![0-9]{5,10}\h+)([^\v\h][^\v]*?)\h+(?:Bank|البنك|بنك|مصرف)/iu', 
            '/(?:من|عبر)\h*(?:بنك|البنك)\h+([^\v\h][^\v،,\.]+)/iu',
            // Pattern for TAB-separated
            '/\t([A-Z]{2,4})\t[0-9,]+/i',
            // Common Saudi bank codes
            '/\b(SNB|ANB|SABB|NCB|RIBL|SAMBA|BSF|ALRAJHI|RAJHI|ALINMA|SAIB)\b/i',
        ];

        $bankStr = self::extractWithPatterns($text, $patterns, 'BANK');

        if ($bankStr) {
            $bankStr = preg_replace('/[،,\.]+$/', '', trim($bankStr));
            // Secondary Guard: Strip any 5-10 digit numeric ID that leaked into the bank name
            $bankStr = preg_replace('/\h*[0-9]{5,10}\h*$/', '', $bankStr);
            return trim($bankStr);
        }

        return null;
    }

    /**
     * Extract contract number or purchase order from text
     * 
     * ✨ ENHANCED: Purchase orders now extract number only (without PO- prefix)
     * - Contract (عقد): C/0061/43, CNT-2024-001 → kept as-is
     * - Purchase Order (أمر شراء): PO-123456 → extracted as "123456"
     * 
     * ⚠️ IMPORTANT: PO cannot contain "/" (slash) - only "-" (dash) is valid
     */
    public static function extractContractNumber(string $text): ?string
    {
        $patterns = [
            // Pattern 1: Pure Digits (5-10 digits) - Aggressive check
            '/^\h*([0-9]{5,10})(?:\h|\v|$)/im',
            '/\b([0-9]{5,10})\b/',

            // CONTRACTS (kept as-is with C/ prefix)
            '/^[^\n]*\b(C\/[A-Z]?[0-9]{4}\/[0-9]{2})\b/im',
            '/\b(C\/[0-9]{4}\/[0-9]{2})\b/i',
            '/\b(CNT[\-][0-9]{4,})\b/i', // CNT-2024-001

            // PURCHASE ORDERS
            '/(?:PO|P\.O|أمر\s*شراء)[:\s#\-]*(\d{4,})/iu',

            // Generic contract labels (fallback)
            '/(?:Contract|Order|العقد|رقم\s*العقد|عقد)[:\h#]*([A-Z0-9\-\/]+)/iu',
            '/(?:ع\.ر)[:\h#]*([A-Z0-9\-\/]+)/iu',
        ];

        $extracted = self::extractWithPatterns($text, $patterns, 'CONTRACT_NUMBER');

        if ($extracted) {
            // Clean up: if it starts with PO- or P.O-, remove the prefix
            // This ensures we only store the number for purchase orders
            $cleaned = preg_replace('/^(PO|P\.O)[\-\s]*/i', '', $extracted);
            return $cleaned;
        }

        return null;
    }

    /**
     * Detect guarantee type (initial or final)
     */
    public static function detectType(string $text): ?string
    {
        if (preg_match('/نهائي|final|performance/iu', $text)) {
            return 'نهائي';
        } elseif (preg_match('/ابتدائي|initial|bid/iu', $text)) {
            return 'ابتدائي';
        }

        return null; // No default assumption
    }

    /**
     * Detect intent (extension, reduction, release)
     * For logging only - not actionable
     */
    public static function detectIntent(string $text): ?string
    {
        if (preg_match('/تمديد|extend|extension|للتمديد|لتمديد/iu', $text)) {
            return 'extension';
        } elseif (preg_match('/تخفيض|reduce|reduction|للتخفيض|لتخفيض/iu', $text)) {
            return 'reduction';
        } elseif (preg_match('/إفراج|افراج|release|cancel|للإفراج|لإفراج/iu', $text)) {
            return 'release';
        }

        return null;
    }

    /**
     * Multi-pattern extraction helper
     * Tries multiple patterns in order until one matches
     * 
     * ⚠️ Copy of extractWithPatterns() function from parse-paste.php
     */
    private static function extractWithPatterns(string $text, array $patterns, string $fieldName): ?string
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $value = trim($m[1]);
                $safeValue = str_replace(["\n", "\r"], ' ', $value);
                error_log("✅ [{$fieldName}] Matched with pattern: {$pattern} => {$safeValue}");
                return $value;
            }
        }
        return null;
    }

    /**
     * Normalize date format to YYYY-MM-DD
     * Handles English and Arabic month names, various separators
     * 
     * ✨ ENHANCED: Added Arabic month names support
     */
    private static function normalizeDateFormat(string $dateStr): string
    {
        // Handle compact format YYYYMMDD
        if (preg_match('/^(20[0-9]{2})([01][0-9])([0-3][0-9])$/', $dateStr, $m)) {
            return $m[1] . '-' . $m[2] . '-' . $m[3];
        }

        // Convert English month name format with 2-digit year (12-Jan-26 → 2026-01-12)
        if (preg_match('/([0-9]{1,2})[\-\/\.](Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[\-\/\.]([0-9]{2})\b/i', $dateStr, $m)) {
            $months = [
                'jan' => '01', 'feb' => '02', 'mar' => '03', 'apr' => '04',
                'may' => '05', 'jun' => '06', 'jul' => '07', 'aug' => '08',
                'sep' => '09', 'oct' => '10', 'nov' => '11', 'dec' => '12'
            ];
            $month = $months[strtolower($m[2])];
            
            // Convert 2-digit year to 4-digit: 00-49 → 20xx, 50-99 → 19xx
            $year = (int)$m[3];
            $year = $year < 50 ? 2000 + $year : 1900 + $year;
            
            return $year . '-' . $month . '-' . str_pad($m[1], 2, '0', STR_PAD_LEFT);
        }
        
        // Convert English month name format with 4-digit year (6-Jan-2026 → 2026-01-06)
        if (preg_match('/([0-9]{1,2})[\-\/\.](Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[\-\/\.]([0-9]{4})/i', $dateStr, $m)) {
            $months = [
                'jan' => '01', 'feb' => '02', 'mar' => '03', 'apr' => '04',
                'may' => '05', 'jun' => '06', 'jul' => '07', 'aug' => '08',
                'sep' => '09', 'oct' => '10', 'nov' => '11', 'dec' => '12'
            ];
            $month = $months[strtolower($m[2])];
            return $m[3] . '-' . $month . '-' . str_pad($m[1], 2, '0', STR_PAD_LEFT);
        }

        // Convert Arabic month name format to YYYY-MM-DD
        if (preg_match('/([0-9]{1,2})[\-\/\.](يناير|فبراير|مارس|أبريل|مايو|يونيو|يوليو|أغسطس|سبتمبر|أكتوبر|نوفمبر|ديسمبر)[\-\/\.]([0-9]{4})/iu', $dateStr, $m)) {
            $arabicMonths = [
                'يناير' => '01',
                'فبراير' => '02',
                'مارس' => '03',
                'أبريل' => '04',
                'مايو' => '05',
                'يونيو' => '06',
                'يوليو' => '07',
                'أغسطس' => '08',
                'سبتمبر' => '09',
                'أكتوبر' => '10',
                'نوفمبر' => '11',
                'ديسمبر' => '12'
            ];
            $month = $arabicMonths[$m[2]];
            return $m[3] . '-' . $month . '-' . str_pad($m[1], 2, '0', STR_PAD_LEFT);
        }

        // Normalize separators (replace / and . with -)
        return str_replace(['/', '.'], '-', $dateStr);
    }
}
