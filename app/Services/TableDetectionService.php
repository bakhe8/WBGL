<?php
declare(strict_types=1);

namespace App\Services;

/**
 * TableDetectionService
 * 
 * Detects and parses TAB-separated tabular data from pasted text
 * Supports multi-row tables with order-independent column detection
 * 
 * ⚠️ USER REQUIREMENTS:
 * 1. Confidence scoring (70%+ required) - "احرص على ثقة النتائج"
 * 2. Order-independent detection - "ترتيب الأعمدة ممكن يتغير"
 * 3. Content-based, not position-based
 * 
 * @version 1.0
 */
class TableDetectionService
{
    /**
     * Detect and parse tabular data from text
     * Returns array of rows with detected fields, or null if no valid table
     * 
     * @param string $text Input text
     * @return array|null Array of rows, each with fields and confidence score
     */
    public static function detectTable(string $text): ?array
    {
        $tabRows = self::detectTabSeparatedRows($text);
        if ($tabRows !== null) {
            return $tabRows;
        }

        return self::detectColumnarRows($text);
    }

    /**
     * Detect classic tab-separated rows.
     */
    private static function detectTabSeparatedRows(string $text): ?array
    {
        $lines = explode("\n", $text);
        $allRows = [];

        foreach ($lines as $lineNum => $line) {
            $tabCount = substr_count($line, "\t");
            if ($tabCount < 4) {
                continue;
            }

            $columns = array_map('trim', explode("\t", $line));
            $rowData = self::parseRow($columns);
            $confidence = self::calculateConfidence($rowData);

            if (self::validateRow($rowData, $confidence)) {
                error_log(sprintf(
                    "✅ [TABLE/TAB] Valid row #%d: G#=%s, Confidence=%.0f%%",
                    $lineNum + 1,
                    $rowData['guarantee_number'] ?? 'N/A',
                    $confidence * 100
                ));
                $allRows[] = array_merge($rowData, ['_confidence' => $confidence]);
            } else {
                error_log(sprintf(
                    "❌ [TABLE/TAB] Invalid row #%d: Confidence=%.0f%% (threshold: 70%%)",
                    $lineNum + 1,
                    $confidence * 100
                ));
            }
        }

        if (count($allRows) > 0) {
            error_log("🎯 [TABLE/TAB] Total valid rows detected: " . count($allRows));
            return $allRows;
        }

        return null;
    }

    /**
     * Detect OCR-like column dumps where each column is pasted vertically.
     * Example shape:
     *   [PO lines]
     *   [contract lines]
     *   [bank lines]
     *   ...
     */
    private static function detectColumnarRows(string $text): ?array
    {
        if (strpos($text, "\t") !== false) {
            return null;
        }

        $blocks = self::splitLineBlocks($text);
        if (count($blocks) < 5) {
            return null;
        }

        $dominantRowCount = self::dominantBlockLength($blocks);
        if ($dominantRowCount === null || $dominantRowCount < 2) {
            return null;
        }

        $candidateBlocks = array_values(array_filter(
            $blocks,
            static fn(array $block): bool => count($block) === $dominantRowCount
        ));
        if (count($candidateBlocks) < 5) {
            return null;
        }

        $candidateBlocks = self::collapseDuplicatedHeadBlocks($candidateBlocks);
        $rowsAsColumns = array_fill(0, $dominantRowCount, []);
        foreach ($candidateBlocks as $block) {
            for ($i = 0; $i < $dominantRowCount; $i++) {
                $value = trim((string)($block[$i] ?? ''));
                if ($value !== '') {
                    $rowsAsColumns[$i][] = $value;
                }
            }
        }

        $allRows = [];
        foreach ($rowsAsColumns as $rowIndex => $columns) {
            if (count($columns) < 4) {
                continue;
            }

            $rowData = self::parseRow($columns);
            $confidence = self::calculateConfidence($rowData);

            if (self::validateRow($rowData, $confidence)) {
                error_log(sprintf(
                    "✅ [TABLE/COLUMNAR] Valid row #%d: G#=%s, Confidence=%.0f%%",
                    $rowIndex + 1,
                    $rowData['guarantee_number'] ?? 'N/A',
                    $confidence * 100
                ));
                $allRows[] = array_merge($rowData, ['_confidence' => $confidence]);
            } else {
                error_log(sprintf(
                    "❌ [TABLE/COLUMNAR] Invalid row #%d: Confidence=%.0f%% (threshold: 70%%)",
                    $rowIndex + 1,
                    $confidence * 100
                ));
            }
        }

        if (count($allRows) > 0) {
            error_log(sprintf(
                "🎯 [TABLE/COLUMNAR] Total valid rows detected: %d (row_count=%d, blocks=%d)",
                count($allRows),
                $dominantRowCount,
                count($candidateBlocks)
            ));
            return $allRows;
        }

        return null;
    }

    /**
     * Split text into non-empty blocks separated by blank lines.
     *
     * @return array<int, array<int, string>>
     */
    private static function splitLineBlocks(string $text): array
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        $blocks = [];
        $current = [];

        foreach ($lines as $line) {
            $trimmed = trim((string)$line);
            if ($trimmed === '') {
                if ($current !== []) {
                    $blocks[] = $current;
                    $current = [];
                }
                continue;
            }

            $current[] = $trimmed;
        }

        if ($current !== []) {
            $blocks[] = $current;
        }

        return $blocks;
    }

    /**
     * Find the most frequent block length (used as inferred row count).
     */
    private static function dominantBlockLength(array $blocks): ?int
    {
        $freq = [];
        foreach ($blocks as $block) {
            $len = count($block);
            if ($len < 2 || $len > 100) {
                continue;
            }
            $freq[$len] = ($freq[$len] ?? 0) + 1;
        }

        if ($freq === []) {
            return null;
        }

        arsort($freq);
        $topLength = (int)array_key_first($freq);
        $topCount = (int)$freq[$topLength];

        // Guard against weak signals in free text.
        if ($topCount < 4) {
            return null;
        }

        return $topLength;
    }

    /**
     * Collapse duplicated leading column groups that sometimes appear in OCR exports.
     *
     * Example:
     *   [A,B,C,D,E,F,G, A,B,C,D,E,F,G, H]
     * becomes:
     *   [A,B,C,D,E,F,G, H]
     */
    private static function collapseDuplicatedHeadBlocks(array $blocks): array
    {
        $count = count($blocks);
        if ($count < 6) {
            return $blocks;
        }

        $maxPrefix = intdiv($count, 2);
        for ($prefix = $maxPrefix; $prefix >= 3; $prefix--) {
            $left = array_slice($blocks, 0, $prefix);
            $right = array_slice($blocks, $prefix, $prefix);
            if ($left !== [] && $left === $right) {
                error_log(sprintf(
                    "ℹ️ [TABLE/COLUMNAR] Collapsed duplicated head blocks (prefix=%d, total=%d)",
                    $prefix,
                    $count
                ));
                return array_merge($left, array_slice($blocks, $prefix * 2));
            }
        }

        return $blocks;
    }
    
    /**
     * Parse a table row - ORDER-INDEPENDENT detection
     * Detects field type by CONTENT, not POSITION
     * 
     * ⚠️ Critical: Column order doesn't matter!
     * 
     * @param array $columns Array of column values
     * @return array Detected fields
     */
    private static function parseRow(array $columns): array
    {
        $rowData = [
            'supplier' => null,
            'guarantee_number' => null,
            'bank' => null,
            'amount' => null,
            'expiry_date' => null,
            'contract_number' => null,
        ];
        
        error_log("🔍 [TABLE] Analyzing row with " . count($columns) . " columns");
        
        // ⚠️ CRITICAL: Detect by CONTENT, not POSITION
        // Priority order: most specific patterns first
        foreach ($columns as $col) {
            if (empty($col)) continue;
            
            // Skip row numbers at start
            if (strlen($col) <= 3 && is_numeric($col)) continue;
            
            // 1. AMOUNT (highest specificity - numbers with commas/decimals)
            if (!$rowData['amount'] && self::isAmount($col)) {
                $rowData['amount'] = $col;
                error_log("  💰 Amount: {$col}");
                continue;
            }
            
            // 2. DATE (specific formats)
            if (!$rowData['expiry_date'] && self::isDate($col)) {
                $rowData['expiry_date'] = $col;
                error_log("  📅 Date: {$col}");
                continue;
            }
            
            // 3. BANK CODE (known patterns)
            if (!$rowData['bank'] && self::isBankCode($col)) {
                $rowData['bank'] = $col;
                error_log("  🏦 Bank: {$col}");
                continue;
            }
            
            // 4. GUARANTEE NUMBER (alphanumeric patterns)
            if (!$rowData['guarantee_number'] && self::isGuaranteeNumber($col)) {
                $rowData['guarantee_number'] = $col;
                error_log("  🔖 Guarantee#: {$col}");
                continue;
            }
            
            // 5. CONTRACT NUMBER (specific formats)
            if (!$rowData['contract_number'] && self::isContractNumber($col)) {
                $rowData['contract_number'] = $col;
                error_log("  📄 Contract#: {$col}");
                continue;
            }
            
            // 6. SUPPLIER (fallback - text content, least specific)
            if (!$rowData['supplier'] && self::isSupplier($col)) {
                $rowData['supplier'] = $col;
                error_log("  🏢 Supplier: {$col}");
                continue;
            }
        }
        
        // ✨ NEW: Infer document type from Contract Number format
        // If it's a plain number (e.g. 7773), it's a Purchase Order
        if (!empty($rowData['contract_number'])) {
            if (preg_match('/^[0-9]{4,}$/', $rowData['contract_number'])) {
                $rowData['related_to'] = 'purchase_order';
            } else {
                $rowData['related_to'] = 'contract';
            }
        }
        
        return $rowData;
    }
    
    /**
     * Calculate confidence score for a detected row
     * Returns 0.0 to 1.0 (70%+ required for acceptance)
     * 
     * ⚠️ USER REQUIREMENT: "احرص على ثقة النتائج"
     */
    private static function calculateConfidence(array $rowData): float
    {
        $score = 0;
        
        // Critical fields (higher weight)
        if ($rowData['guarantee_number']) $score += 2;  // Most critical
        if ($rowData['amount']) $score += 2;            // Most critical
        
        // Important fields
        if ($rowData['supplier']) $score += 1;
        if ($rowData['bank']) $score += 1;
        if ($rowData['expiry_date']) $score += 1;
        
        // Optional field
        if ($rowData['contract_number']) $score += 0.5;
        
        $maxScore = 7.5; // 2+2+1+1+1+0.5
        return $score / $maxScore;
    }
    
    /**
     * Validate row meets minimum requirements
     * 
     * Requirements:
     * - Confidence >= 70%
     * - Must have guarantee_number AND amount (critical fields)
     */
    private static function validateRow(array $rowData, float $confidence): bool
    {
        // Confidence threshold
        if ($confidence < 0.70) {
            return false;
        }
        
        // Must have critical fields
        if (!$rowData['guarantee_number'] || !$rowData['amount']) {
            return false;
        }
        
        return true;
    }
    
    // ========================================================================
    // Field Type Detectors (Order-Independent, Content-Based)
    // ========================================================================
    
    /**
     * Check if column is an amount
     * 
     * ⚠️ STRICT: Must have comma OR decimal to avoid matching plain numbers (PO numbers)
     * Examples:
     * ✅ 95,200.00 → Amount
     * ✅ 1,234.56 → Amount  
     * ✅ 95,200 → Amount
     * ❌ 7773 → NOT an amount (plain number, likely PO or reference)
     */
    private static function isAmount(string $col): bool
    {
        // Must have comma OR decimal point (prevents "7773" from being detected as amount)
        if (strpos($col, ',') !== false || strpos($col, '.') !== false) {
            return (bool)preg_match('/^[0-9,]+(\.[0-9]{2})?$/', $col);
        }
        
        return false; // Plain numbers are not amounts
    }
    
    /**
     * Check if column is a date
     * 
     * Supports:
     * - 12-Jan-26 (2-digit year)
     * - 12-Jan-2026 (4-digit year)
     * - DD-MM-YYYY / DD/MM/YYYY
     * - YYYY-MM-DD / YYYY/MM/DD
     */
    private static function isDate(string $col): bool
    {
        // Month name format with 2-digit year (12-Jan-26)
        if (preg_match('/^[0-9]{1,2}[-\/](Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[-\/]([0-9]{2})$/i', $col)) {
            return true;
        }
        
        // Month name format with 4-digit year (6-Jan-2026)
        if (preg_match('/^[0-9]{1,2}[-\/](Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[-\/][0-9]{4}$/i', $col)) {
            return true;
        }
        
        // DD-MM-YYYY or DD/MM/YYYY
        if (preg_match('/^[0-9]{1,2}[-\/][0-9]{1,2}[-\/][0-9]{4}$/', $col)) {
            return true;
        }
        
        // YYYY-MM-DD or YYYY/MM/DD
        if (preg_match('/^[0-9]{4}[-\/][0-9]{1,2}[-\/][0-9]{1,2}$/', $col)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Check if column is a bank code
     */
    private static function isBankCode(string $col): bool
    {
        $cleanCol = trim(preg_replace('/\s+/', ' ', $col));
        
        if (strlen($cleanCol) >= 60) {
            return false; // Too long
        }
        
        // Exact match with known bank codes (including SAB)
        if (preg_match('/^(SNB|ANB|SAB|SABB|NCB|RIBL|SAMBA|BSF|ALRAJHI|ALINMA|BNP\s*PARIBAS|BANQUE\s*SAUDI\s*FRANSI|BSF)$/i', $cleanCol)) {
            return true;
        }
        
        // Pattern for bank names containing "BANK" or "BANQUE"
        if (preg_match('/\b(BANK|BANQUE|ALRAJHI|ALINMA)\b/i', $cleanCol)) {
            // Extra check: prevent capturing sentences containing "bank"
            if (str_word_count($cleanCol) < 10) {
                return true;
            }
        }
        
        // Fallback: Short uppercase codes (2-5 letters) likely to be bank codes
        if (preg_match('/^[A-Z]{2,5}$/', $cleanCol)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Check if column is a guarantee number
     */
    private static function isGuaranteeNumber(string $col): bool
    {
        // Long alphanumeric codes (10+ chars)
        if (preg_match('/^[A-Z0-9]{10,}$/i', $col)) {
            return true;
        }
        
        // Prefix + numbers + optional letter (ABC123456A)
        if (preg_match('/^[A-Z]{3,4}[0-9]{6,}[A-Z]?$/i', $col)) {
            return true;
        }
        
        // Numbers + letter (123456A)
        if (preg_match('/^[0-9]{6,}[A-Z]$/i', $col)) {
            return true;
        }
        
        // Short prefix + numbers (AB123456)
        if (preg_match('/^[A-Z]{1,2}[0-9]{6,}$/i', $col)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Check if column is a contract number
     */
    /**
     * Check if column is a contract number or PO
     * 
     * ⚠️ UPDATED: 
     * - Removed "PO-" support because user clarified POs are plain numbers (e.g. 7773)
     * - "PO-211" is actually an S/N, so must be rejected
     * - Added support for plain numbers (4+ digits)
     */
    private static function isContractNumber(string $col): bool
    {
        // Format: ABC/1234/56 (Contract)
        if (preg_match('/^[A-Z]+\/[A-Z0-9]{4,}\/[0-9]{2}$/i', $col)) {
            return true;
        }
        
        // Format: CNT-1234 or C-1234 (standard Contract prefixes)
        // ❌ REMOVED "PO" prefix support - user confirmed "PO-211" is incorrect/S-N
        if (preg_match('/^(CNT|C)-[0-9]+/i', $col)) {
            return true;
        }
        
        // ✨ NEW: Plain Purchase Order numbers (4+ digits)
        // Example: 7773
        // Note: isAmount() handles numbers with commas/decimals. Clean integers flow here.
        if (preg_match('/^[0-9]{4,}$/', $col)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Check if column is a supplier name
     * Lowest specificity - catch-all for text
     */
    private static function isSupplier(string $col): bool
    {
        $cleanSupp = trim(preg_replace('/\s+/', ' ', $col));
        
        // Must contain letters and be reasonable length
        if (!preg_match('/[A-Za-zء-ي]/', $col)) {
            return false;
        }
        
        if (strlen($cleanSupp) < 8 || strlen($cleanSupp) >= 100) {
            return false; // Too short or too long
        }
        
        // Skip if it looks like other field types
        if (preg_match('/^[0-9,\.]+$/', $cleanSupp)) {
            return false; // Amount
        }
        
        if (preg_match('/^[A-Z0-9]{1,4}[0-9]+[A-Z]?$/i', $cleanSupp)) {
            return false; // Code
        }
        
        if (preg_match('/[0-9]{1,2}[-\/][A-Za-z0-9]{1,3}[-\/][0-9]{2,4}/', $cleanSupp)) {
            return false; // Date
        }
        
        if (preg_match('/(BANK|BANQUE)/i', $cleanSupp)) {
            return false; // Bank (already captured)
        }
        
        if (strpos($cleanSupp, '<') !== false) {
            return false; // HTML tags
        }
        
        return true;
    }
}
