<?php

namespace App\Services\SmartPaste;

/**
 * Confidence Calculator for Smart Paste
 * 
 * Calculates confidence scores for extracted data to prevent
 * aggressive/incorrect fuzzy matching.
 * 
 * Related: docs/smart-paste-pattern-matching-analysis.md
 *          Issue [ISSUE-002]
 */
class ConfidenceCalculator
{
    // Confidence thresholds
    const THRESHOLD_HIGH = 90;      // Auto-accept
    const THRESHOLD_MEDIUM = 70;    // Show with warning
    const THRESHOLD_LOW = 70;       // Reject (too risky)
    
    // Match type scores
    const SCORE_EXACT_MATCH = 100;
    const SCORE_ALTERNATIVE_MATCH = 95;
    const SCORE_FUZZY_STRONG = 85;   // 95%+ similarity
    const SCORE_FUZZY_MEDIUM = 70;   // 85-94% similarity
    const SCORE_FUZZY_WEAK = 50;     // 70-84% similarity
    
    /**
     * Calculate confidence for a supplier match
     * 
     * @param string $rawInput The input text from user
     * @param string $matchedValue The value that was matched
     * @param string $matchType Type: 'exact', 'alternative', 'fuzzy'
     * @param float $similarityScore For fuzzy matches (0-100)
     * @param int $occurrenceCount Historical usage count
     * @return array ['confidence' => int, 'reason' => string, 'accept' => bool]
     */
    public function calculateSupplierConfidence(
        string $rawInput,
        string $matchedValue,
        string $matchType,
        float $similarityScore = 0,
        int $occurrenceCount = 0
    ): array {
        $confidence = 0;
        $reason = '';
        
        switch ($matchType) {
            case 'exact':
                $confidence = self::SCORE_EXACT_MATCH;
                $reason = 'تطابق تام';
                break;
                
            case 'alternative':
                $confidence = self::SCORE_ALTERNATIVE_MATCH;
                $reason = 'تطابق مع اسم بديل معروف';
                break;
                
            case 'fuzzy':
                // Stricter fuzzy matching
                if ($similarityScore >= 95) {
                    $confidence = self::SCORE_FUZZY_STRONG;
                    $reason = "تشابه قوي ({$similarityScore}%)";
                } elseif ($similarityScore >= 85) {
                    $confidence = self::SCORE_FUZZY_MEDIUM;
                    $reason = "تشابه متوسط ({$similarityScore}%)";
                } else {
                    // Below 85% - too risky
                    $confidence = self::SCORE_FUZZY_WEAK;
                    $reason = "تشابه ضعيف ({$similarityScore}%)";
                }
                break;
                
            default:
                $confidence = 0;
                $reason = 'نوع مطابقة غير معروف';
        }
        
        // Boost confidence for frequently used suppliers
        if ($occurrenceCount > 0) {
            $boost = min(10, $occurrenceCount * 2); // Max +10%
            $confidence = min(100, $confidence + $boost);
            $reason .= " + استخدام متكرر ({$occurrenceCount}×)";
        }
        
        // Validate input isn't gibberish
        if ($this->isGibberish($rawInput)) {
            $confidence = max(0, $confidence - 30);
            $reason .= " - نص مشبوه";
        }
        
        return [
            'confidence' => round($confidence),
            'reason' => $reason,
            'accept' => $confidence >= self::THRESHOLD_LOW
        ];
    }
    
    /**
     * Calculate confidence for a bank match
     * 
     * @param string $rawInput
     * @param string $matchedBank
     * @param string $matchType
     * @param float $similarityScore
     * @return array
     */
    public function calculateBankConfidence(
        string $rawInput,
        string $matchedBank,
        string $matchType,
        float $similarityScore = 0
    ): array {
        // Banks use exact same logic as suppliers
        // (could be different if needed in future)
        return $this->calculateSupplierConfidence(
            $rawInput,
            $matchedBank,
            $matchType,
            $similarityScore,
            0 // No historical count for banks
        );
    }
    
    /**
     * Calculate confidence for amount extraction
     * 
     * @param string $rawInput
     * @param float $extractedAmount
     * @return array
     */
    public function calculateAmountConfidence(
        string $rawInput,
        float $extractedAmount
    ): array {
        $confidence = 0;
        $reason = '';
        
        // Check if amount looks reasonable
        if ($extractedAmount <= 0) {
            return [
                'confidence' => 0,
                'reason' => 'مبلغ غير صحيح',
                'accept' => false
            ];
        }
        
        // ✅ NEW: Try normalizing the raw input to see if it's well-formatted
        $normalized = self::normalizeNumber($rawInput);
        
        if ($normalized !== null && abs($normalized - $extractedAmount) < 0.01) {
            // Perfectly normalized - high confidence
            $confidence = 95;
            $reason = 'تنسيق رقمي واضح ومعيار';
        } else {
            // Check if we found clear numeric pattern
            $hasCommas = preg_match('/[\d,]+/', $rawInput);
            $hasArabicNumerals = preg_match('/[٠-٩]+/', $rawInput);
            $hasDecimal = preg_match('/\.\d{2}/', $rawInput);
            
            if ($hasCommas || $hasArabicNumerals) {
                $confidence = 90;
                $reason = 'تم العثور على نمط رقمي واضح';
            } else {
                $confidence = 60;
                $reason = 'استخراج رقمي بسيط';
            }
            
            if ($hasDecimal) {
                $confidence = min(100, $confidence + 5);
            }
        }
        
        return [
            'confidence' => $confidence,
            'reason' => $reason,
            'accept' => $confidence >= 60
        ];
    }
    
    /**
     * Calculate confidence for date extraction
     * 
     * @param string $rawInput
     * @param string $extractedDate
     * @return array
     */
    public function calculateDateConfidence(
        string $rawInput,
        string $extractedDate
    ): array {
        $confidence = 0;
        $reason = '';
        
        // Check if date format is clear
        if (preg_match('/\d{4}-\d{2}-\d{2}/', $extractedDate)) {
            $confidence = 95;
            $reason = 'تنسيق تاريخ قياسي';
        } elseif (preg_match('/\d{2}\/\d{2}\/\d{4}/', $rawInput)) {
            $confidence = 90;
            $reason = 'تنسيق تاريخ واضح';
        } else {
            $confidence = 70;
            $reason = 'تنسيق تاريخ بسيط';
        }
        
        // Validate date is reasonable (not too far in past/future)
        try {
            $date = new \DateTime($extractedDate);
            $now = new \DateTime();
            $diff = $now->diff($date);
            
            $yearsDiff = abs($diff->y);
            
            if ($yearsDiff > 20) {
                $confidence -= 20;
                $reason .= ' - تاريخ بعيد';
            }
        } catch (\Exception $e) {
            $confidence = 0;
            $reason = 'تاريخ غير صحيح';
        }
        
        return [
            'confidence' => $confidence,
            'reason' => $reason,
            'accept' => $confidence >= 60
        ];
    }
    
    /**
     * Normalize number from various formats
     * Handles Arabic/English numerals, thousands separators, decimals
     * 
     * Examples:
     * - "50,000" → 50000
     * - "50.000" (European) → 50000
     * - "50,000.00" → 50000
     * - "٥٠٬٠٠٠" → 50000
     * - "50 000" → 50000
     * 
     * @param string $input Raw number string
     * @return float|null Normalized number or null if invalid
     */
    public static function normalizeNumber(string $input): ?float
    {
        // Remove whitespace
        $input = trim($input);
        
        if (empty($input)) {
            return null;
        }
        
        // Convert Arabic numerals to English
        $arabicNumerals = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $englishNumerals = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        $input = str_replace($arabicNumerals, $englishNumerals, $input);
        
        // Convert Arabic comma (٬) and Arabic decimal separator (٫) to standard
        $input = str_replace(['٬', '٫'], [',', '.'], $input);
        
        // Remove spaces (some countries use space as thousands separator)
        $input = str_replace(' ', '', $input);
        
        // Detect decimal separator
        // If we have both comma and period, the last one is decimal
        $hasComma = strpos($input, ',') !== false;
        $hasPeriod = strpos($input, '.') !== false;
        
        if ($hasComma && $hasPeriod) {
            // Both present - last one is decimal
            $lastCommaPos = strrpos($input, ',');
            $lastPeriodPos = strrpos($input, '.');
            
            if ($lastPeriodPos > $lastCommaPos) {
                // Period is decimal: 1,234.56
                $input = str_replace(',', '', $input); // Remove thousands comma
            } else {
                // Comma is decimal: 1.234,56 (European)
                $input = str_replace('.', '', $input); // Remove thousands period
                $input = str_replace(',', '.', $input); // Comma to period for decimal
            }
        } elseif ($hasComma) {
            // Only comma - check if it's thousands or decimal
            // If 2 digits after last comma, it's decimal (e.g., 1,50)
            // Otherwise it's thousands (e.g., 1,234 or 50,000)
            $parts = explode(',', $input);
            $lastPart = end($parts);
            
            if (strlen($lastPart) === 2 && count($parts) === 2) {
                // Likely decimal: 50,75
                $input = str_replace(',', '.', $input);
            } else {
                // Thousands separator: 50,000 or 1,234,567
                $input = str_replace(',', '', $input);
            }
        }
        // If only period, assume it's decimal (already correct format)
        
        // Convert to float
        $number = filter_var($input, FILTER_VALIDATE_FLOAT);
        
        return $number !== false ? $number : null;
    }
    
    /**
     * Detect if input text is gibberish/nonsense
     * 
     * @param string $text
     * @return bool
     */
    private function isGibberish(string $text): bool {
        // Check for Lorem Ipsum
        if (stripos($text, 'lorem') !== false || stripos($text, 'ipsum') !== false) {
            return true;
        }
        
        // Check for too many random characters
        $alphaCount = preg_match_all('/[a-zA-Z]/', $text);
        $totalCount = mb_strlen($text);
        
        if ($totalCount > 0 && $alphaCount / $totalCount > 0.8 && $totalCount < 20) {
            // Mostly English letters in short text - suspicious for Arabic system
            return true;
        }
        
        return false;
    }
    
    /**
     * Get confidence level label
     * 
     * @param int $confidence
     * @return string 'high', 'medium', 'low'
     */
    public static function getConfidenceLevel(int $confidence): string {
        if ($confidence >= self::THRESHOLD_HIGH) {
            return 'high';
        } elseif ($confidence >= self::THRESHOLD_MEDIUM) {
            return 'medium';
        } else {
            return 'low';
        }
    }
    
    /**
     * Get CSS class for confidence level
     * 
     * @param int $confidence
     * @return string
     */
    public static function getConfidenceClass(int $confidence): string {
        $level = self::getConfidenceLevel($confidence);
        
        return match($level) {
            'high' => 'confidence-high',
            'medium' => 'confidence-medium',
            'low' => 'confidence-low',
            default => 'confidence-unknown'
        };
    }
}
