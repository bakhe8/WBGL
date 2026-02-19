<?php

namespace App\Services\Import;

use PhpOffice\PhpSpreadsheet\IOFactory;

class EmailImportService
{
    private MsgExtractor $extractor;

    public function __construct()
    {
        $this->extractor = new MsgExtractor();
    }

    /**
     * Process an uploaded .msg file
     */
    public function processMsgFile(string $filePath): array
    {
        // 1. Extract MSG Content
        // Create a dedicated folder for this import to keep attachments together
        $importId = uniqid('import_');
        $outputDir = __DIR__ . '/../../../public/uploads/temp/' . $importId;
        
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        // Save the original MSG file to this directory
        $savedMsgPath = $outputDir . '/' . basename($filePath);
        // If filename is generic (upload_xxx.msg), maybe rename to something nicer if we had original name?
        // But we receive just path here. The caller handles temp naming. 
        // Let's just keep valid name.
        copy($filePath, $savedMsgPath);

        $msgData = $this->extractor->extract($savedMsgPath, $outputDir);

        // 2. Identify Attachments
        $excelFile = null;
        $pdfFile = null;
        
        foreach ($msgData['attachments'] as $att) {
            $ext = strtolower(pathinfo($att['original_name'], PATHINFO_EXTENSION));
            
            // Priority: Excel
            if (in_array($ext, ['xlsx', 'xls']) && !$excelFile) {
                $excelFile = $att['saved_path'];
            }
            
            // Priority: PDF
            if ($ext === 'pdf' && !$pdfFile) {
                $pdfFile = $att['saved_path'];
            }
        }

        // 3. Strategy Decision
        if ($excelFile) {
            return $this->processExcelStrategy($excelFile, $msgData, $outputDir, $importId, $savedMsgPath);
        } else {
            return $this->processFallbackStrategy($msgData, $pdfFile, $outputDir, $importId, $savedMsgPath);
        }
    }

    private function processExcelStrategy(string $excelPath, array $msgData, string $baseDir, string $importId, string $msgPath): array
    {
        $spreadsheet = IOFactory::load($excelPath);
        $sheetData = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
        
        $guarantees = [];
        $headerMap = [];
        
        // 1. Scan for Headers (First 5 rows max)
        $startRow = 1;
        $foundHeader = false;
        
        foreach ($sheetData as $rowIndex => $row) {
            $rowStr = implode(' ', $row);
            // Check for specific known headers
            if (stripos($rowStr, 'CONTRACTOR NAME') !== false && stripos($rowStr, 'BANK GUARANTEE') !== false) {
                // ... (Logic remains same) ...
                // Fixed mapping for this specific secure format
                $colGuarantee = 'C';
                $colSupplier = 'B';
                $colBank = 'D';
                $colAmount = 'E';
                // F is Validity (Current Expiry)
                $colExpiry = 'F'; 
                
                // Dynamic Check for Column I (PO vs Contract)
                // Layout B usually has 'CONTRACT NO.' in header, but let's be safe
                $headerColI = isset($sheetData[$rowIndex]['I']) ? strtoupper($sheetData[$rowIndex]['I']) : '';
                if (strpos($headerColI, 'PO') !== false || strpos($headerColI, 'PURCHASE') !== false) {
                    $colPO = 'I';
                    $colContract = null;
                } else {
                    $colContract = 'I'; // Default to Contract for this layout
                    $colPO = null;
                }

                $colType = 'J'; // Type (FINAL/INITIAL)
                
                $startRow = $rowIndex + 1;
                $foundHeader = true;
                break;
            }
            
            if (stripos($rowStr, 'GUARANTEE') !== false || stripos($rowStr, 'NO.') !== false) {
                 // === LAYOUT A: Generic/Previous Style ===
                foreach ($row as $col => $val) {
                    $headerMap[strtoupper(trim((string)$val))] = $col;
                }
                
                // Helper to find column by keywords
                $findCol = function($keywords) use ($headerMap) {
                    foreach ($headerMap as $header => $col) {
                        foreach ($keywords as $keyword) {
                            if (strpos($header, $keyword) !== false) return $col;
                        }
                    }
                    return null;
                };

                $colGuarantee = $findCol(['GUARANTEE NUM', 'GEN', 'NO.', 'NUM']) ?? 'D'; 
                $colExpiry = $findCol(['VALIDITY', 'EXPIRY', 'END DATE', 'NEW DATE']) ?? 'H';
                $colAmount = $findCol(['AMOUNT', 'VALUE']) ?? 'G';
                $colBank = $findCol(['BANK', 'BENEFICIARY']) ?? 'C';
                $colSupplier = $findCol(['SUPPLIER', 'CONTRACTOR', 'NAME']) ?? null;
                $colType = $findCol(['TYPE', 'CLASS', 'CATEGORY']) ?? null;
                
                // Split PO vs Contract search
                $colPO = $findCol(['PO NO', 'PO NUM', 'ORDER', 'PURCHASE']);
                $colContract = $findCol(['CONTRACT', 'AGREEMENT']);
                
                // Fallback for Layout A if only one found? 
                if (!$colPO && !$colContract) $colPO = 'B';

                $startRow = $rowIndex + 1;
                $foundHeader = true;
                break;
            }
        }

        // Default Fallback if no header found (unlikely but safe)
        if (!$foundHeader) {
            $colGuarantee = 'D'; $colExpiry = 'H'; $colAmount = 'G'; $colPO = 'B'; $colBank = 'C'; $colSupplier = null; $colType = null; $colContract = null;
        }

        // 2. Process Data
        foreach ($sheetData as $rowIndex => $row) {
            if ($rowIndex < $startRow) continue; 
            
            $guaranteeNo = trim($row[$colGuarantee] ?? '');
            if (empty($guaranteeNo) || strlen($guaranteeNo) < 3) continue; // Skip empty/garbage

            // Clean Amount (remove "SAR", ",", etc)
            $amountRaw = $row[$colAmount] ?? '';
            $amount = $this->parseAmount($amountRaw);

            // Clean Date
            $dateRaw = $row[$colExpiry] ?? '';
            
            // Clean Type
            $typeRaw = $colType ? trim($row[$colType] ?? '') : '';
            $type = strtolower($typeRaw);
            
            $guarantees[] = [
                'guarantee_number' => $guaranteeNo,
                'new_expiry_date' => $this->parseDate($dateRaw),
                'amount' => $amount,
                // Dynamic assignment based on what was found
                'contract_number' => $colContract ? ($row[$colContract] ?? '') : '', 
                'po_number' => $colPO ? ($row[$colPO] ?? '') : '', 
                'bank_name' => $row[$colBank] ?? '',
                'supplier' => $colSupplier ? ($row[$colSupplier] ?? '') : '', 
                'type' => $type,
                'source' => 'excel'
            ];
        }

        // Locate PDF again for verification (hacky but safe)
        $pdfFile = null;
        foreach ($msgData['attachments'] as $att) {
            if (strtolower(pathinfo($att['original_name'], PATHINFO_EXTENSION)) === 'pdf') {
                $pdfFile = $att['saved_path'];
                break;
            }
        }

        return [
            'status' => 'success',
            'strategy' => 'excel',
            'import_id' => $importId,
            'email_subject' => $msgData['subject'],
            'guarantees' => $guarantees,
            'evidence_files' => [
                'pdf' => $pdfFile ? $this->getFileUrl($pdfFile) : null,
                'msg' => $this->getFileUrl($msgPath)
            ]
        ];
    }

    private function processFallbackStrategy(array $msgData, ?string $pdfPath, string $baseDir, string $importId, string $msgPath): array
    {
        return [
            'status' => 'success',
            'strategy' => 'fallback', // Manual Entry
            'import_id' => $importId,
            'email_subject' => $msgData['subject'],
            'guarantees' => [], // Empty list = Create Draft
            'evidence_files' => [
                'pdf' => $pdfPath ? $this->getFileUrl($pdfPath) : null,
                'msg' => $this->getFileUrl($msgPath)
            ],
            'message' => 'No Excel file found. Please enter data manually from the attached PDF.'
        ];
    }

    private function parseDate($dateStr)
    {
        if (empty($dateStr)) return null;

        // 1. Handle Excel Serial Date (Numeric)
        if (is_numeric($dateStr)) {
            try {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($dateStr)->format('Y-m-d');
            } catch (\Exception $e) {
                // Ignore, try string parse
            }
        }

        // 2. Handle Strings (e.g. "18-Feb-2026", "2026-02-18")
        $timestamp = strtotime($dateStr);
        if ($timestamp) {
            return date('Y-m-d', $timestamp);
        }

        return $dateStr; // Return raw if all else fails
    }

    private function parseAmount($amountStr)
    {
        return (float) str_replace(',', '', $amountStr);
    }
    
    private function getFileUrl($path)
    {
        // Convert absolute path to relative public URL
        $publicPos = strpos($path, 'public');
        if ($publicPos !== false) {
            return substr($path, $publicPos + 6); // remove 'public'
        }
        return basename($path);
    }
}
