<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Repositories\GuaranteeRepository;
use App\Models\Guarantee;
use App\Support\SimpleXlsxReader;
use App\Support\TransactionBoundary;
use App\Support\TypeNormalizer;
use App\Repositories\BatchMetadataRepository; // ✅ NEW
use PDO;
use RuntimeException;

/**
 * V3 Import Service
 * 
 * Handles importing guarantees from Excel or manual entry
 * Simplified version for V3 (no sessions, uses import_source)
 */
class ImportService
{
    private GuaranteeRepository $guaranteeRepo;
    private static ?string $occurrenceTypeColumn = null;
    
    public function __construct(?GuaranteeRepository $guaranteeRepo = null)
    {
        $db = Database::connect();
        $this->guaranteeRepo = $guaranteeRepo ?? new GuaranteeRepository($db);
    }

    /**
     * Sanitize filename for import_source
     * Decision #1: Add filename to prevent collision
     */
    private function sanitizeFilename(string $filename): string
    {
        // Remove extension
        $name = pathinfo($filename, PATHINFO_FILENAME);
        
        // Keep only alphanumeric and underscore
        $clean = preg_replace('/[^a-zA-Z0-9_]/', '', $name);
        
        // Truncate to 30 chars
        return substr($clean, 0, 30);
    }

    /**
     * Import from Excel file
     * 
     * @param string $filePath Path to uploaded Excel file
     * @param string $importedBy User who imported (default: 'system')
     * @param string $originalFilename Original filename for import_source
     * @return array Result with count, errors, skipped
     */
    public function importFromExcel(string $filePath, string $importedBy = 'system', string $originalFilename = '', bool $isTestData = false): array
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException('الملف غير موجود');
        }

        // Load Excel
        // Load Excel using SimpleXlsxReader
        try {
            $rows = SimpleXlsxReader::read($filePath);
        } catch (\Exception $e) {
            throw new RuntimeException('فشل قراءة ملف Excel: ' . $e->getMessage());
        }

        if (count($rows) < 2) {
            throw new RuntimeException('الملف فارغ أو لا يحتوي على بيانات');
        }

        // Smart Header Detection: Try first 5 rows to find the actual headers
        $headerMap = null;
        $headerRowIndex = 0;
        
        for ($i = 0; $i < min(5, count($rows)); $i++) {
            $testMap = $this->detectColumns($rows[$i]);
            
            // If we found supplier AND bank columns, this is the header row!
            if (isset($testMap['supplier']) && isset($testMap['bank'])) {
                $headerMap = $testMap;
                $headerRowIndex = $i;
                break;
            }
        }
        
        if (!$headerMap || !isset($headerMap['supplier']) || !isset($headerMap['bank'])) {
            throw new RuntimeException('لم يتم العثور على عمود المورد أو البنك في صف العناوين. الرجاء التأكد من أن الملف يحتوي على عناوين مثل: SUPPLIER, CONTRACTOR NAME, BANK NAME');
        }

        // Data rows (skip header)
        $dataRows = array_slice($rows, $headerRowIndex + 1);
        
        $imported = 0;
        $duplicates = 0;  // Decision #6: Track duplicate imports
        $skipped = [];
        $errors = [];
        $importedIdsWithData = []; // Track full records for timeline events

        // Decision #25: One batch per import (fixed identifier for this file)
        $filenamePart = $originalFilename ? '_' . $this->sanitizeFilename($originalFilename) : '';
        
        // ✅ BATCH LOGIC: Separated Excel Batches (Real vs Test)
        $batchPrefix = $isTestData ? 'test_excel_' : 'excel_';
        $batchIdentifier = $batchPrefix . date('Ymd_His') . $filenamePart;
        $db = Database::connect();
        $batchIdentifier = self::resolveCompatibleBatchIdentifier($db, $batchIdentifier, $isTestData);

        // ✅ ARABIC NAME LOGIC
        // "دفعة إكسل: {filename} (DD/MM/YYYY)"
        // "دفعة اختبار: إكسل - {filename} (DD/MM/YYYY)"
        $displayFilename = $originalFilename ?: 'Unknown';
        $dateStr = date('Y/m/d');
        
        $arabicName = $isTestData 
            ? "دفعة اختبار: إكسل - {$displayFilename} ({$dateStr})"
            : "دفعة إكسل: {$displayFilename} ({$dateStr})";

        // Create metadata immediately
        $metaRepo = new BatchMetadataRepository($db);
        $metaRepo->ensureBatchName($batchIdentifier, $arabicName);

        foreach ($dataRows as $index => $row) {
            $rowNumber = $index + 2; // Excel row number (1-indexed + header)
            
            // Skip empty rows
            if (empty(array_filter($row))) {
                $skipped[] = "الصف #{$rowNumber}: فارغ";
                continue;
            }

            try {
                // Extract data
                $supplier = $this->getColumn($row, $headerMap['supplier']);
                $bank = $this->getColumn($row, $headerMap['bank']);
                $guaranteeNumber = $this->getColumn($row, $headerMap['guarantee'] ?? null);
                $amount = $this->normalizeAmount($this->getColumn($row, $headerMap['amount'] ?? null));
                $issueDate = $this->normalizeDate($this->getColumn($row, $headerMap['issue'] ?? null));
                $expiryDate = $this->normalizeDate($this->getColumn($row, $headerMap['expiry'] ?? null));
                $type = $this->getColumn($row, $headerMap['type'] ?? null);
                $contractNumber = $this->getColumn($row, $headerMap['contract'] ?? null);

                // Validation (All Required Fields)
                $missingFields = [];
                if (empty($supplier)) $missingFields[] = 'المورد';
                if (empty($bank)) $missingFields[] = 'البنك';
                if (empty($guaranteeNumber)) $missingFields[] = 'رقم الضمان';
                if (empty($amount) || $amount <= 0) $missingFields[] = 'القيمة';
                if (empty($expiryDate)) $missingFields[] = 'تاريخ الانتهاء';
                if (empty($contractNumber)) $missingFields[] = 'رقم العقد/أمر الشراء';

                if (!empty($missingFields)) {
                    $skipped[] = "الصف #{$rowNumber}: بيانات ناقصة (" . implode('، ', $missingFields) . ")";
                    continue;
                }

                // Build raw_data
                $rawData = [
                    'supplier' => $supplier,
                    'bank' => $bank,
                    'guarantee_number' => $guaranteeNumber,
                    'amount' => $amount,
                    'issue_date' => $issueDate,
                    'expiry_date' => $expiryDate,
                    'type' => TypeNormalizer::normalize($type),
                    'contract_number' => $contractNumber,
                    'related_to' => $headerMap['contract_type'] ?? 'contract', // 🔥 NEW
                ];

                // Create Guarantee
                // Decision #1: Add sanitized filename to import_source
                
                $guarantee = new Guarantee(
                    id: null,
                    guaranteeNumber: $guaranteeNumber,
                    rawData: $rawData,
                    importSource: $batchIdentifier,
                    importedAt: date('Y-m-d H:i:s'),
                    importedBy: $importedBy
                );

                $rowResult = TransactionBoundary::run($db, function () use (
                    $guarantee,
                    $guaranteeNumber,
                    $batchIdentifier,
                    $db
                ): array {
                    try {
                        $created = $this->guaranteeRepo->create($guarantee);
                        $createdId = (int)$created->id;
                        if ($createdId <= 0) {
                            throw new RuntimeException('Failed to persist imported guarantee');
                        }

                        // ✅ [NEW] Record First Occurrence
                        $this->recordOccurrence($createdId, $batchIdentifier, 'excel', null, $db);

                        $importEventId = TimelineRecorder::recordImportEvent($createdId, 'excel', $created->rawData);
                        if (!$importEventId) {
                            throw new RuntimeException('Failed to record import timeline event');
                        }

                        return [
                            'kind' => 'imported',
                            'id' => $createdId,
                            'raw_data' => $created->rawData,
                        ];
                    } catch (\PDOException $e) {
                        // Decision #6: Handle duplicate guarantees (PostgreSQL + legacy compatibility)
                        if (!self::isDuplicateGuaranteeConstraint($e)) {
                            throw $e;
                        }

                        // Find existing guarantee
                        $existing = $this->guaranteeRepo->findByNumber($guaranteeNumber);
                        if (!$existing || (int)$existing->id <= 0) {
                            throw $e;
                        }

                        $existingId = (int)$existing->id;
                        // ✅ [NEW] Record Re-Occurrence (The core of Batch-as-Context)
                        $this->recordOccurrence($existingId, $batchIdentifier, 'excel', null, $db);

                        $duplicateEventId = TimelineRecorder::recordDuplicateImportEvent($existingId, 'excel');
                        if (!$duplicateEventId) {
                            throw new RuntimeException('Failed to record duplicate import timeline event');
                        }

                        return [
                            'kind' => 'duplicate',
                            'id' => $existingId,
                        ];
                    }
                });

                if (($rowResult['kind'] ?? '') === 'imported') {
                    $imported++;
                    $importedIdsWithData[] = [
                        'id' => (int)($rowResult['id'] ?? 0),
                        'raw_data' => $rowResult['raw_data'] ?? [],
                    ];
                    continue;
                }

                if (($rowResult['kind'] ?? '') === 'duplicate') {
                    $duplicates++;
                    $skipped[] = "الصف #{$rowNumber}: ضمان مكرر (تم تسجيل ظهور جديد في الدفعة الحالية)";
                    continue;
                }

                throw new RuntimeException('Unexpected import row result type');

            } catch (\Throwable $e) {
                $errors[] = "الصف #{$rowNumber}: " . $e->getMessage();
            }
        }

        return [
            'imported' => $imported,
            'duplicates' => $duplicates,  // Decision #6: Report duplicate count
            'imported_records' => $importedIdsWithData, // Canonical full tracking contract

            'total_rows' => count($dataRows),
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Create guarantee manually (from form)
     */
    public function createManually(array $data, string $createdBy = 'system'): int
    {
        // Validation
        if (empty($data['guarantee_number'])) {
            throw new RuntimeException('رقم الضمان مطلوب');
        }

        if (empty($data['supplier'])) {
            throw new RuntimeException('اسم المورد مطلوب');
        }

        if (empty($data['bank'])) {
            throw new RuntimeException('اسم البنك مطلوب');
        }

        if (empty($data['amount']) || floatval($data['amount']) <= 0) {
            throw new RuntimeException('القيمة مطلوبة');
        }

        if (empty($data['expiry_date'])) {
            throw new RuntimeException('تاريخ الانتهاء مطلوب');
        }

        if (empty($data['contract_number'])) {
            throw new RuntimeException('رقم العقد/أمر الشراء مطلوب');
        }

        // Build raw_data
        $cleanAmount = isset($data['amount']) ? str_replace(',', '', (string)$data['amount']) : '0';
        $rawData = [
            'supplier' => $data['supplier'],
            'bank' => $data['bank'],
            'guarantee_number' => $data['guarantee_number'],
            'amount' => floatval($cleanAmount),
            'issue_date' => $data['issue_date'] ?? null,
            'expiry_date' => $data['expiry_date'] ?? null,
            'type' => TypeNormalizer::normalize($data['type'] ?? ''),
            'contract_number' => $data['contract_number'] ?? null,
            'related_to' => $data['related_to'] ?? 'contract', // 🔥 NEW
        ];

        $db = $this->guaranteeRepo->getDb();
        $isTestData = !empty($data['is_test_data']);

        // Decision #11: Daily batch for manual & paste entry (unified)
        $batchIdentifier = 'manual_paste_' . date('Ymd');  // Daily batch
        $batchIdentifier = self::resolveCompatibleBatchIdentifier($db, $batchIdentifier, $isTestData);

        $guarantee = new Guarantee(
            id: null,
            guaranteeNumber: $data['guarantee_number'],
            rawData: $rawData,
            importSource: $batchIdentifier,
            importedAt: date('Y-m-d H:i:s'),
            importedBy: $createdBy
        );

        $metaRepo = new BatchMetadataRepository($db);
        $metaRepo->ensureBatchName(
            $batchIdentifier,
            $isTestData ? 'دفعة اختبار: إدخال/لصق (' . date('Y/m/d') . ')' : 'دفعة إدخال يدوي/ذكي (' . date('Y/m/d') . ')'
        );

        $ownsTransaction = !$db->inTransaction();
        if ($ownsTransaction) {
            $db->beginTransaction();
        }

        try {
            $created = $this->guaranteeRepo->create($guarantee);

            // ✅ NEW: Handle test data marking (Phase 1)
            if ($isTestData) {
                $this->guaranteeRepo->markAsTestData(
                    $created->id,
                    $data['test_batch_id'] ?? null,
                    $data['test_note'] ?? null
                );
            }

            // ✅ [NEW] Record Occurrence for manual entry
            $this->recordOccurrence($created->id, $batchIdentifier, 'manual', null, $db);

            $importEventId = TimelineRecorder::recordImportEvent((int)$created->id, 'manual', $created->rawData);
            if (!$importEventId) {
                throw new RuntimeException('Failed to record manual import timeline event');
            }

            if ($ownsTransaction) {
                $db->commit();
            }

            return $created->id;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Detect Excel column mapping using smart keyword matching
     * Supports both Arabic and English column names with variations
     */
    private function detectColumns(array $headerRow): array
    {
        $keywords = [
            'supplier' => [
                'supplier', 'vendor', 'supplier name', 'vendor name', 'party name', 
                'contractor name', 'contractor', 'company name', 'company',
                'المورد', 'اسم المورد', 'اسم الموردين', 'الشركة', 'اسم الشركة', 'مقدم الخدمة',
            ],
            'guarantee' => [
                // English (including common typos from real files)
                'guarantee no', 'guarantee number', 'reference', 'ref no',
                'bank guarantee number', 'bank gurantee number', 'bank guaranty number',
                'gurantee no', 'gurantee number', 'bank gurantee', 'guranttee number',
                'bg number', 'bg no', 'bg#', 'bg ##',
                // Arabic
                'رقم الضمان', 'رقم المرجع', 'مرجع الضمان', 'الضمان البنكي',
            ],
            'type' => [
                'type', 'guarantee type', 'category', 'bg type',
                'نوع الضمان', 'نوع', 'فئة الضمان', 'النوع',
            ],
            'amount' => [
                // English
                'amount', 'value', 'total amount', 'guarantee amount',
                'bg amount', 'gurantee amount', 'guarantee value',
                // Arabic
                'المبلغ', 'قيمة الضمان', 'قيمة', 'مبلغ الضمان', 'القيمة',
            ],
            'expiry' => [
                // English (from real files)
                'expiry date', 'exp date', 'validity', 'valid until', 'end date', 
                'validity date', 'bg expiry date', 'expiry', 'valid till',
                'expire date', 'expiration date', 'valid days', 'days valid',
                // Arabic
                'تاريخ الانتهاء', 'صلاحية', 'تاريخ الصلاحية', 'ينتهي في', 'تاريخ انتهاء الصلاحية',
            ],
            'issue' => [
                'issue date', 'issuance date', 'issued on', 'release date', 'start date',
                'تاريخ الاصدار', 'تاريخ الإصدار', 'تاريخ التحرير', 'تاريخ الاصدار/التحرير',
            ],
            'contract' => [
                // Contract
                'contract number', 'contract no', 'contract #', 'contract reference', 'contract id',
                'agreement number', 'agreement no',
                // PO (very common in files)
                'po number', 'po no', 'po#', 'po ##', 'purchase order', 'purchase order number',
                // Arabic
                'رقم العقد', 'رقم الاتفاقية', 'مرجع العقد',
                'رقم أمر الشراء', 'أمر الشراء', 'رقم الشراء', 'امر شراء',
            ],
            'bank' => [
                // English
                'bank', 'bank name', 'issuing bank', 'beneficiary bank', 'financial institution',
                // Arabic
                'البنك', 'اسم البنك', 'البنك المصدر', 'بنك الاصدار', 'بنك الإصدار',
            ],
        ];

        $map = [];
        $usedIndices = []; // Prevent column duplication as user suggested
        
        foreach ($headerRow as $idx => $header) {
            $h = $this->normalizeHeader($header);
            
            // Skip empty or already matched columns
            if (empty($h) || in_array($idx, $usedIndices)) {
                continue;
            }
            
            // Protect against capturing guarantee columns as Bank
            $isGuaranteeish = str_contains($h, 'guarantee') || str_contains($h, 'gurantee');
            
        foreach ($keywords as $field => $synonyms) {
                // Skip if this field already found
                if (isset($map[$field])) {
                    continue;
                }
                
                if ($field === 'bank' && $isGuaranteeish) {
                    continue;
                }
                
                // Special handling for contract field to detect type
                if ($field === 'contract') {
                    foreach ($synonyms as $synonym) {
                        if (str_contains($h, $this->normalizeHeader($synonym))) {
                            $map[$field] = $idx;
                            
                            // 🔥 NEW: Detect if it's Purchase Order or Contract based on ACTUAL column name
                            $isPurchaseOrder = (
                                str_contains($h, 'po') ||
                                str_contains($h, 'purchase') ||
                                str_contains($h, 'شراء') ||
                                str_contains($h, 'امر')
                            );
                            $map['contract_type'] = $isPurchaseOrder ? 'purchase_order' : 'contract';
                            
                            $usedIndices[] = $idx; // Mark column as used
                            break 2; // Exit both loops
                        }
                    }
                } else {
                    // Normal handling for other fields
                    foreach ($synonyms as $synonym) {
                        if (str_contains($h, $this->normalizeHeader($synonym))) {
                            $map[$field] = $idx;
                            $usedIndices[] = $idx; // Mark column as used
                            break 2; // Exit both loops
                        }
                    }
                }
            }
        }

        return $map;
    }

    /**
     * Normalize header for comparison
     */
    private function normalizeHeader(string|null $str): string
    {
        if ($str === null || $str === '') {
            return '';
        }
        
        $str = mb_strtolower($str);
        // Remove symbols, commas, dots
        $str = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $str);
        $str = preg_replace('/\s+/u', ' ', trim($str));
        return $str ?? '';
    }

    /**
     * Get column value safely
     */
    private function getColumn(array $row, ?int $index): string
    {
        if ($index === null || !isset($row[$index])) {
            return '';
        }

        return trim((string) $row[$index]);
    }

    /**
     * Normalize amount (remove commas, convert to float)
     */
    private function normalizeAmount(string $value): ?float
    {
        if (empty($value)) {
            return null;
        }

        // Remove commas and spaces
        $clean = str_replace([',', ' ', 'SAR', 'ريال'], '', $value);

        if (!is_numeric($clean)) {
            return null;
        }

        return round(floatval($clean), 2);
    }

    /**
     * Normalize date to Y-m-d format
     */
    private function normalizeDate(string $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        // Try strtotime
        $timestamp = strtotime($value);
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }

        // Try Excel serial number
        if (is_numeric($value)) {
            $unixDate = ($value - 25569) * 86400;
            return date('Y-m-d', (int) $unixDate); // Changed from gmdate to use Riyadh timezone
        }

        // Return as-is if unable to parse
        return $value;
    }

    /**
     * Validate import data before saving
     */
    public function validateImportData(array $data): array
    {
        $errors = [];

        if (empty($data['guarantee_number'])) {
            $errors[] = 'رقم الضمان مطلوب';
        }

        if (empty($data['supplier'])) {
            $errors[] = 'اسم المورد مطلوب';
        }

        if (empty($data['bank'])) {
            $errors[] = 'اسم البنك مطلوب';
        }

        if (isset($data['amount']) && !is_numeric($data['amount'])) {
            $errors[] = 'المبلغ يجب أن يكون رقماً';
        }

        return $errors;
    }

    /**
     * Preview Excel file contents without saving
     */
    public function previewExcel(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException('الملف غير موجود');
        }

        try {
            $rows = SimpleXlsxReader::read($filePath);
        } catch (\Exception $e) {
            throw new RuntimeException('فشل قراءة ملف Excel: ' . $e->getMessage());
        }

        if (count($rows) < 2) {
            throw new RuntimeException('الملف فارغ');
        }

        $headerMap = $this->detectColumns($rows[0]);
        $preview = [];

        // Preview first 10 rows
        $dataRows = array_slice($rows, 1, 10);

        foreach ($dataRows as $row) {
            $preview[] = [
                'supplier' => $this->getColumn($row, $headerMap['supplier'] ?? null),
                'bank' => $this->getColumn($row, $headerMap['bank'] ?? null),
                'guarantee_number' => $this->getColumn($row, $headerMap['guarantee'] ?? null),
                'amount' => $this->normalizeAmount($this->getColumn($row, $headerMap['amount'] ?? null)),
                'type' => $this->getColumn($row, $headerMap['type'] ?? null),
            ];
        }

        return [
            'headers' => $headerMap,
            'preview' => $preview,
            'total_rows' => count($rows) - 1,
        ];
    }

    /**
     * Record a physical occurrence of a guarantee in a batch
     * This is the "Batch as Context" enabler.
     */
    public static function recordOccurrence(
        int $guaranteeId,
        string $batchIdentifier,
        string $type,
        ?string $occurredAt = null,
        ?PDO $db = null
    ): void
    {
        $db = $db ?? Database::connect();
        // Check if already exists in this batch (idempotency within same batch)
        $stmt = $db->prepare("SELECT id FROM guarantee_occurrences WHERE guarantee_id = ? AND batch_identifier = ?");
        $stmt->execute([$guaranteeId, $batchIdentifier]);
        
        if (!$stmt->fetch()) {
            $typeColumn = self::resolveOccurrenceTypeColumn($db);
            $insert = $db->prepare(sprintf(
                'INSERT INTO guarantee_occurrences (guarantee_id, batch_identifier, %s, occurred_at)
                 VALUES (?, ?, ?, ?)',
                $typeColumn
            ));
            $occurredAtValue = trim((string)$occurredAt);
            if ($occurredAtValue === '') {
                $occurredAtValue = date('Y-m-d H:i:s');
            }
            $insert->execute([$guaranteeId, $batchIdentifier, $type, $occurredAtValue]);
        }
    }

    /**
     * Ensure the selected batch identifier cannot mix real and test guarantees.
     * If the preferred identifier is already used by the opposite data class,
     * an isolated suffix is appended.
     */
    public static function resolveCompatibleBatchIdentifier(PDO $db, string $preferredBatchIdentifier, bool $isTestData): string
    {
        $base = trim($preferredBatchIdentifier);
        if ($base === '') {
            $base = ($isTestData ? 'test_paste_' : 'manual_paste_') . date('Ymd_His');
        }

        $composition = self::fetchBatchComposition($db, $base);
        if (!self::hasBatchConflict($composition, $isTestData)) {
            return $base;
        }

        $suffixBase = $base . '_' . ($isTestData ? 'test' : 'real');
        $candidate = $suffixBase;
        for ($i = 1; $i <= 50; $i++) {
            $composition = self::fetchBatchComposition($db, $candidate);
            if (!self::hasBatchConflict($composition, $isTestData)) {
                return $candidate;
            }
            $candidate = $suffixBase . '_' . $i;
        }

        throw new RuntimeException('Unable to resolve a non-mixed batch identifier');
    }

    /**
     * @return array{test_count:int,real_count:int}
     */
    private static function fetchBatchComposition(PDO $db, string $batchIdentifier): array
    {
        $stmt = $db->prepare(
            'SELECT
                COUNT(DISTINCT CASE WHEN COALESCE(g.is_test_data, 0) = 1 THEN o.guarantee_id END) AS test_count,
                COUNT(DISTINCT CASE WHEN COALESCE(g.is_test_data, 0) = 0 THEN o.guarantee_id END) AS real_count
             FROM guarantee_occurrences o
             JOIN guarantees g ON g.id = o.guarantee_id
             WHERE o.batch_identifier = ?'
        );
        $stmt->execute([$batchIdentifier]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'test_count' => (int)($row['test_count'] ?? 0),
            'real_count' => (int)($row['real_count'] ?? 0),
        ];
    }

    /**
     * @param array{test_count:int,real_count:int} $composition
     */
    private static function hasBatchConflict(array $composition, bool $isTestData): bool
    {
        if ($isTestData) {
            return $composition['real_count'] > 0;
        }

        return $composition['test_count'] > 0;
    }

    private static function resolveOccurrenceTypeColumn(PDO $db): string
    {
        if (self::$occurrenceTypeColumn !== null) {
            return self::$occurrenceTypeColumn;
        }

        $stmt = $db->prepare(
            "SELECT column_name
             FROM information_schema.columns
             WHERE table_schema = 'public'
               AND table_name = 'guarantee_occurrences'"
        );
        $stmt->execute();

        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $columnName = $row['column_name'] ?? null;
            if (is_string($columnName) && $columnName !== '') {
                $columns[] = $columnName;
            }
        }

        if (in_array('batch_type', $columns, true)) {
            self::$occurrenceTypeColumn = 'batch_type';
            return self::$occurrenceTypeColumn;
        }

        if (in_array('import_source', $columns, true)) {
            self::$occurrenceTypeColumn = 'import_source';
            return self::$occurrenceTypeColumn;
        }

        throw new RuntimeException(
            "guarantee_occurrences must expose either 'batch_type' or 'import_source' column."
        );
    }

    private static function isDuplicateGuaranteeConstraint(\PDOException $e): bool
    {
        $message = strtolower((string)$e->getMessage());
        $sqlState = strtoupper((string)$e->getCode());
        $errorInfoState = strtoupper((string)($e->errorInfo[0] ?? ''));

        // PostgreSQL unique_violation SQLSTATE
        if ($sqlState === '23505' || $errorInfoState === '23505') {
            return true;
        }

        // Legacy wording compatibility (SQLite / generic adapters)
        if (str_contains($message, 'unique constraint')) {
            return true;
        }

        return str_contains($message, 'duplicate key value violates unique constraint');
    }
}
