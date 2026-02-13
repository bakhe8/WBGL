<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use App\Services\ImportService;
use App\Repositories\GuaranteeRepository;
use App\Models\Guarantee;
use App\Support\TypeNormalizer;
use App\Services\SmartPaste\ConfidenceCalculator;  // ✅ NEW (Phase 2)
use App\Repositories\BatchMetadataRepository; // ✅ NEW (Phase 3)

/**
 * ParseCoordinatorService
 * 
 * Orchestrates the parsing workflow:
 * 1. Detect if table or single row
 * 2. Extract fields using appropriate method
 * 3. Validate completeness
 * 4. Create guarantees in database
 * 5. Trigger auto-matching
 * 
 * @version 1.0
 */
class ParseCoordinatorService
{
    /**
     * Parse text and create guarantees
     * Main entry point for parse-paste functionality
     * 
     * @param string $text Input text from user
     * @param PDO $db Database connection
     * @return array Result with success status and created guarantees
     */
    /**
     * Parse text and create guarantees
     * Main entry point for parse-paste functionality
     * 
     * @param string $text Input text from user
     * @param PDO $db Database connection
     * @param array $options Optional parameters (e.g., ['is_test_data' => true])
     * @return array Result with success status and created guarantees
     */
    public static function parseText(string $text, PDO $db, array $options = []): array
    {
        // Try table detection first
        $tableRows = TableDetectionService::detectTable($text);
        
        if ($tableRows && is_array($tableRows) && count($tableRows) > 1) {
            // Multi-row table detected
            return self::processMultiRow($tableRows, $text, $db, $options);
        } elseif ($tableRows && is_array($tableRows) && count($tableRows) === 1) {
            // Single row from table
            return self::processSingleTableRow($tableRows[0], $text, $db, $options);
        } else {
            // Non-table text - use regex extraction
            return self::processSingleText($text, $db, $options);
        }
    }
    
    /**
     * Process multiple table rows
     */
    private static function processMultiRow(array $tableRows, string $text, PDO $db, array $options = []): array
    {
        error_log("🎯 [MULTI] Processing " . count($tableRows) . " guarantees from multi-row table");
        
        $repo = new GuaranteeRepository($db);
        $results = [];
        
        foreach ($tableRows as $rowData) {
            try {
                // Keep confidence score for logging
                // unset($rowData['_confidence']);
                
                // Process row
                $result = self::createGuaranteeFromRow($rowData, $text, $repo, 'smart_paste_multi', $options);
                $results[] = $result;
                
                error_log(sprintf(
                    "✅ [MULTI] Processed G#: %s - %s",
                    $result['guarantee_number'],
                    $result['exists_before'] ? 'Exists' : 'Created'
                ));
            } catch (\Exception $e) {
                error_log("❌ [MULTI] Failed to process G#: {$rowData['guarantee_number']} - " . $e->getMessage());
                $results[] = [
                    'guarantee_number' => $rowData['guarantee_number'],
                    'error' => $e->getMessage(),
                    'failed' => true
                ];
            }
        }
        
        // Trigger auto-matching for all new guarantees
        self::triggerAutoMatching($results);
        
        return [
            'success' => true,
            'multi' => true,
            'count' => count($results),
            'results' => $results,
            'message' => "تم استيراد " . count($results) . " ضمان بنجاح"
        ];
    }
    
    /**
     * Process single row from table
     */
    private static function processSingleTableRow(array $rowData, string $text, PDO $db, array $options = []): array
    {
        error_log("🎯 [TABLE] Using single row from table");
        
        // Remove confidence score
        unset($rowData['_confidence']);
        
        // Convert amount if present
        if ($rowData['amount']) {
            $amountStr = str_replace(',', '', $rowData['amount']);
            $rowData['amount'] = (float)$amountStr;
        }
        
        // Extract additional fields from text if missing
        $extracted = self::extractFieldsFromText($text);
        
        // Merge table data with extracted data (table data takes priority)
        $extracted = array_merge($extracted, array_filter($rowData));
        
        // Validate and create
        return self::validateAndCreate($extracted, $text, $db, $options);
    }
    
    /**
     * Process single non-table text
     */
    private static function processSingleText(string $text, PDO $db, array $options = []): array
    {
        // Extract all fields using regex patterns
        $extracted = self::extractFieldsFromText($text);
        
        // Validate and create
        return self::validateAndCreate($extracted, $text, $db, $options);
    }
    
    /**
     * Extract fields from text using FieldExtractionService
     * 
     * ✨ ENHANCED: Uses Text Masking to prevent field overlap
     * Each extracted field is masked from subsequent searches for better accuracy
     */
    private static function extractFieldsFromText(string $text): array
    {
        // ⚠️ CRITICAL: Extract in order of specificity (most specific first)
        // This prevents generic patterns from consuming specific ones
        
        $workingText = $text;  // Working copy for masking
        $extracted = [];
        
        // 1. GUARANTEE NUMBER (Highest priority ID)
        $extracted['guarantee_number'] = FieldExtractionService::extractGuaranteeNumber($workingText);
        if ($extracted['guarantee_number']) {
            $workingText = self::maskExtractedValue($workingText, $extracted['guarantee_number']);
        }
        
        // 2. CONTRACT/PO NUMBER (Priority ID - Extract early to handle naked numbers)
        $extracted['contract_number'] = FieldExtractionService::extractContractNumber($workingText);
        if ($extracted['contract_number']) {
            $workingText = self::maskExtractedValue($workingText, $extracted['contract_number']);
        }

        // 3. DATES (Expiry and Issue)
        $extracted['expiry_date'] = FieldExtractionService::extractExpiryDate($workingText);
        if ($extracted['expiry_date']) {
            $workingText = self::maskExtractedValue($workingText, $extracted['expiry_date']);
        }
        $extracted['issue_date'] = FieldExtractionService::extractIssueDate($workingText);
        if ($extracted['issue_date']) {
            $workingText = self::maskExtractedValue($workingText, $extracted['issue_date']);
        }

        // 4. AMOUNT
        $extracted['amount'] = FieldExtractionService::extractAmount($workingText);
        if ($extracted['amount']) {
            $workingText = self::maskExtractedValue($workingText, (string)$extracted['amount']);
            $formatted = number_format($extracted['amount'], 2);
            $workingText = self::maskExtractedValue($workingText, str_replace(',', '', $formatted));
            $workingText = self::maskExtractedValue($workingText, $formatted);
        }

        // 5. BANK
        $extracted['bank'] = FieldExtractionService::extractBank($workingText);
        if ($extracted['bank']) {
            $workingText = self::maskExtractedValue($workingText, $extracted['bank']);
        }
        
        // 6. SUPPLIER (Catch-all)
        $extracted['supplier'] = FieldExtractionService::extractSupplier($workingText);
        
        // 7. TYPE and INTENT (pattern detection - use original text)
        $extracted['type'] = FieldExtractionService::detectType($text);
        $extracted['intent'] = FieldExtractionService::detectIntent($text);
        
        // 8. Metadata
        $extracted['currency'] = 'SAR';
        $extracted['source_text'] = $text;
        
        return $extracted;
    }
    
    /**
     * Mask an extracted value from text to prevent re-extraction
     * 
     * This is a simple replacement approach:
     * - Replaces the exact extracted value with spaces
     * - Prevents the same text segment from matching multiple patterns
     * 
     * @param string $text Text to mask from
     * @param string $value Value to mask
     * @return string Text with value masked
     */
    private static function maskExtractedValue(string $text, string $value): string
    {
        if (empty($value)) {
            return $text;
        }
        
        // Use preg_quote to escape special regex characters in the value
        $quotedValue = preg_quote($value, '/');
        
        // Replace with spaces (preserves text length and positions)
        $masked = preg_replace('/' . $quotedValue . '/u', str_repeat(' ', mb_strlen($value)), $text, 1);
        
        return $masked;
    }
    
    /**
     * Validate fields and create guarantee
     */
    private static function validateAndCreate(array $extracted, string $text, PDO $db, array $options = []): array
    {
        // Check for mandatory fields
        $missing = [];
        if (!$extracted['guarantee_number']) $missing[] = "رقم الضمان";
        if (!$extracted['supplier']) $missing[] = "اسم المورد";
        if (!$extracted['bank']) $missing[] = "اسم البنك";
        if (!$extracted['amount']) $missing[] = "القيمة";
        if (!$extracted['expiry_date']) $missing[] = "تاريخ الانتهاء";
        if (!$extracted['contract_number']) $missing[] = "رقم العقد";
        
        // Field status for user feedback
        $fieldStatus = [
            'guarantee_number' => $extracted['guarantee_number'] ? '✅' : '❌',
            'amount' => $extracted['amount'] ? '✅' : '❌',
            'supplier' => $extracted['supplier'] ? '✅' : '❌',
            'bank' => $extracted['bank'] ? '✅' : '❌',
            'expiry_date' => $extracted['expiry_date'] ? '✅' : '❌',
            'contract_number' => $extracted['contract_number'] ? '✅' : '❌',
            'issue_date' => $extracted['issue_date'] ? '✅' : '⚠️',
        ];
        
        // Log the attempt
        self::logPasteAttempt($text, $extracted, $fieldStatus, empty($missing));
        
        if (!empty($missing)) {
            return [
                'success' => false,
                'error' => "بيانات غير مكتملة. الحقول الناقصة: " . implode(', ', $missing),
                'extracted' => $extracted,
                'field_status' => $fieldStatus,
                'missing_fields' => $missing
            ];
        }
        
        // ✅ NEW (Phase 2): Calculate confidence scores
        $confidence = self::calculateConfidenceScores($extracted, $text);
        $overallConfidence = self::calculateOverallConfidence($confidence);
        
        // Inject confidence into extracted data for storage
        $extracted['_confidence'] = $confidence;
        $extracted['_overall_confidence'] = $overallConfidence;

        // Create guarantee
        $repo = new GuaranteeRepository($db);
        $result = self::createGuaranteeFromExtracted($extracted, $text, $repo, $options);
        
        // Trigger auto-matching for single guarantee
        if (!$result['exists_before']) {
            self::triggerAutoMatching([$result]);
        }
        
        return [
            'success' => true,
            'id' => $result['id'],
            'extracted' => $extracted,
            'field_status' => $fieldStatus,
            'confidence' => $confidence,  // ✅ NEW
            'overall_confidence' => $overallConfidence,  // ✅ NEW
            'exists_before' => $result['exists_before'],
            'intent' => $extracted['intent'],
            'message' => $result['exists_before'] ? 'تم العثور على الضمان' : 'تم إنشاء ضمان جديد بنجاح'
        ];
    }
    
    /**
     * ✅ NEW (Phase 2): Calculate confidence scores for extracted fields
     * 
     * @param array $extracted Extracted field data
     * @param string $text Original text
     * @return array Confidence data for each field
     */
    private static function calculateConfidenceScores(array $extracted, string $text): array
    {
        $calculator = new ConfidenceCalculator();
        $confidence = [];
        
        // Calculate confidence for supplier (if extracted)
        if (!empty($extracted['supplier'])) {
            $confidence['supplier'] = $calculator->calculateSupplierConfidence(
                $text,
                $extracted['supplier'],
                'fuzzy',  // Default to fuzzy since we don't track match type here
                85,  // Assume decent similarity
                0   // No historical count
            );
        }
        
        // Calculate confidence for bank (if extracted)
        if (!empty($extracted['bank'])) {
            $confidence['bank'] = $calculator->calculateBankConfidence(
                $text,
                $extracted['bank'],
                'fuzzy',
                85
            );
        }
        
        // Calculate confidence for amount (if extracted)
        if (!empty($extracted['amount'])) {
            $confidence['amount'] = $calculator->calculateAmountConfidence(
                $text,
                floatval($extracted['amount'])
            );
        }
        
        // Calculate confidence for dates
        if (!empty($extracted['expiry_date'])) {
            $confidence['expiry_date'] = $calculator->calculateDateConfidence(
                $text,
                $extracted['expiry_date']
            );
        }
        
        if (!empty($extracted['issue_date'])) {
            $confidence['issue_date'] = $calculator->calculateDateConfidence(
                $text,
                $extracted['issue_date']
            );
        }
        
        return $confidence;
    }
    
    /**
     * ✅ NEW (Phase 2): Calculate overall confidence from individual field scores
     * 
     * @param array $confidence Field confidence data
     * @return int Overall confidence percentage
     */
    private static function calculateOverallConfidence(array $confidence): int
    {
        if (empty($confidence)) {
            return 0;
        }
        
        // Extract confidence scores
        $scores = array_column($confidence, 'confidence');
        
        // Calculate average
        $average = array_sum($scores) / count($scores);
        
        return (int)round($average);
    }
    
    /**
     * Create guarantee from table row data
     */
    private static function createGuaranteeFromRow(
        array $rowData, 
        string $text, 
        GuaranteeRepository $repo,
        string $source = 'smart_paste',
        array $options = []
    ): array {
        // ... (date and amount parsing logic typically here, keeping distinct)
        // Convert date format if needed
        $expiryDate = $rowData['expiry_date'];
        if ($expiryDate && preg_match('/([0-9]{1,2})[-\/](Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[-\/]([0-9]{4})/i', $expiryDate, $m)) {
            $months = [
                'jan'=>'01', 'feb'=>'02', 'mar'=>'03', 'apr'=>'04',
                'may'=>'05', 'jun'=>'06', 'jul'=>'07', 'aug'=>'08',
                'sep'=>'09', 'oct'=>'10', 'nov'=>'11', 'dec'=>'12'
            ];
            $month = $months[strtolower($m[2])];
            $expiryDate = $m[3] . '-' . $month . '-' . str_pad($m[1], 2, '0', STR_PAD_LEFT);
        }
        
        // Parse amount
        $amount = null;
        if ($rowData['amount']) {
            $amountStr = str_replace(',', '', $rowData['amount']);
            $amount = (float)$amountStr;
        }
        
        // Check if exists
        $existing = $repo->findByNumber($rowData['guarantee_number']);
        if ($existing) {
            return [
                'id' => $existing->id,
                'guarantee_number' => $rowData['guarantee_number'],
                'exists_before' => true
            ];
        }
        
        // ✅ BATCH LOGIC: Daily Separated Batches (Real vs Test)
        $isTestData = !empty($options['is_test_data']);
        $batchPrefix = $isTestData ? 'test_paste_' : 'manual_paste_';
        $batchId = $batchPrefix . date('Ymd');
        
        // Create new
        $rawData = [
            'bg_number' => $rowData['guarantee_number'],
            'supplier' => $rowData['supplier'],
            'bank' => $rowData['bank'],
            'amount' => $amount,
            'expiry_date' => $expiryDate,
            'contract_number' => $rowData['contract_number'],
            'type' => TypeNormalizer::normalize($rowData['type'] ?? $rowData['guarantee_type'] ?? ''), 
            'source' => $source,
            'original_text' => $text,
            'confidence' => $rowData['_confidence'] ?? null,  // ✅ Log Table Confidence
            'test_data' => $isTestData // Pass Internal flag
        ];
        
        $guaranteeModel = new Guarantee(
            id: null,
            guaranteeNumber: $rowData['guarantee_number'],
            rawData: $rawData,
            importSource: $batchId,
            importedAt: date('Y-m-d H:i:s'),
            importedBy: 'Web User'
        );
        
        $saved = $repo->create($guaranteeModel);

        // ✅ ARABIC NAME LOGIC
        $arabicName = $isTestData 
            ? 'دفعة اختبار: إدخال/لصق (' . date('Y/m/d') . ')' 
            : 'دفعة إدخال يدوي/ذكي (' . date('Y/m/d') . ')';

        // Ensure metadata exists
        $metaRepo = new BatchMetadataRepository($repo->getDb());
        $metaRepo->ensureBatchName($batchId, $arabicName);

        // ✅ Record Occurrence
        ImportService::recordOccurrence($saved->id, $batchId, 'smart_paste');
        
        // Record history event
        try {
            \App\Services\TimelineRecorder::recordImportEvent($saved->id, $source, $saved->rawData);
        } catch (\Throwable $t) {
            error_log("Failed to record history: " . $t->getMessage());
        }
        
        return [
            'id' => $saved->id,
            'guarantee_number' => $rowData['guarantee_number'],
            'supplier' => $rowData['supplier'],
            'amount' => $amount,
            'exists_before' => false
        ];
    }
    
    /**
     * Create guarantee from extracted fields
     */
    private static function createGuaranteeFromExtracted(
        array $extracted, 
        string $text, 
        GuaranteeRepository $repo,
        array $options = []
    ): array {
        // Check if exists
        $existing = $repo->findByNumber($extracted['guarantee_number']);
        
        if ($existing) {
            // Record duplicate
            try {
                \App\Services\TimelineRecorder::recordDuplicateImportEvent($existing->id, 'smart_paste');
            } catch (\Throwable $t) {
                error_log("Failed to record duplicate: " . $t->getMessage());
            }
            
            return [
                'id' => $existing->id,
                'exists_before' => true
            ];
        }
        
        // ✅ BATCH LOGIC: Daily Separated Batches (Real vs Test)
        $isTestData = !empty($options['is_test_data']);
        $batchPrefix = $isTestData ? 'test_paste_' : 'manual_paste_';
        $batchId = $batchPrefix . date('Ymd');

        // Create new
        $rawData = [
            'bg_number' => $extracted['guarantee_number'],
            'supplier' => $extracted['supplier'],
            'bank' => $extracted['bank'],
            'amount' => $extracted['amount'],
            'expiry_date' => $extracted['expiry_date'],
            'issue_date' => $extracted['issue_date'],
            'contract_number' => $extracted['contract_number'],
            'type' => TypeNormalizer::normalize($extracted['type']),
            'source' => 'smart_paste',
            'original_text' => $text,
            'detected_intent' => $extracted['intent'],
            'test_data' => $isTestData, // Pass Internal flag
            // ✅ LOG CONFIDENCE SCORES
            'confidence' => $extracted['_confidence'] ?? null,
            'overall_confidence' => $extracted['_overall_confidence'] ?? null
        ];
        
        $guaranteeModel = new Guarantee(
            id: null,
            guaranteeNumber: $extracted['guarantee_number'],
            rawData: $rawData,
            importSource: $batchId,
            importedAt: date('Y-m-d H:i:s'),
            importedBy: 'Web User'
        );
        
        $saved = $repo->create($guaranteeModel);

        // ✅ ARABIC NAME LOGIC
        $arabicName = $isTestData 
            ? 'دفعة اختبار: إدخال/لصق (' . date('Y/m/d') . ')' 
            : 'دفعة إدخال يدوي/ذكي (' . date('Y/m/d') . ')';

        // Ensure metadata exists
        $metaRepo = new BatchMetadataRepository($repo->getDb());
        $metaRepo->ensureBatchName($batchId, $arabicName);

        // ✅ Record Occurrence
        ImportService::recordOccurrence($saved->id, $batchId, 'smart_paste');
        
        // Record history
        try {
            \App\Services\TimelineRecorder::recordImportEvent($saved->id, 'smart_paste');
        } catch (\Throwable $t) {
            error_log("Failed to record history: " . $t->getMessage());
        }
        
        return [
            'id' => $saved->id,
            'exists_before' => false
        ];
    }
    
    /**
     * Trigger auto-matching for created guarantees
     */
    private static function triggerAutoMatching(array $results): void
    {
        try {
            $newCount = count(array_filter($results, function($r) {
                return !($r['exists_before'] ?? false) && !($r['failed'] ?? false);
            }));
            
            if ($newCount > 0) {
                $processor = new \App\Services\SmartProcessingService('manual', 'web_user');
                $autoMatchStats = $processor->processNewGuarantees($newCount);
                error_log("✅ Smart Paste auto-matched: {$autoMatchStats['auto_matched']} out of {$newCount}");
            }
        } catch (\Throwable $e) {
            error_log("Auto-matching failed (non-critical): " . $e->getMessage());
        }
    }
    
    /**
     * Log paste attempt for debugging
     */
    private static function logPasteAttempt(string $text, array $extracted, array $fieldStatus, bool $success): void
    {
        $logFile = __DIR__ . '/../../storage/paste_debug.log';
        $timestamp = date('Y-m-d H:i:s');
        
        $logEntry = "\n" . str_repeat("=", 80) . "\n";
        $logEntry .= "PASTE ATTEMPT @ {$timestamp}\n";
        $logEntry .= str_repeat("=", 80) . "\n";
        $logEntry .= "STATUS: " . ($success ? "✅ SUCCESS" : "❌ FAILED") . "\n";
        $logEntry .= "\n--- ORIGINAL TEXT ---\n{$text}\n";
        $logEntry .= "\n--- EXTRACTED DATA ---\n";
        $logEntry .= json_encode(array_merge($extracted, ['field_status' => $fieldStatus]), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        $logEntry .= str_repeat("=", 80) . "\n";
        
        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }
}
