<?php
// api/save-import.php

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Method Not Allowed');
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['import_id']) || !isset($input['guarantees'])) {
        throw new Exception('Invalid Input Data');
    }

    $pdo = Database::connect();
    $settings = \App\Support\Settings::getInstance();
    $isProduction = $settings->isProductionMode();
    $autoMarkTestData = !$isProduction; // If not in production, auto-mark as test data

    $pdo->beginTransaction();

    $evidenceFiles = $input['evidence_files'] ?? [];
    $savedCount = 0;

    foreach ($input['guarantees'] as $g) {
        $gNo = $g['guarantee_number'];
        
        // 1. Find Existing or Create New
        $stmtFind = $pdo->prepare("SELECT id, raw_data FROM guarantees WHERE guarantee_number = ?");
        $stmtFind->execute([$gNo]);
        $existing = $stmtFind->fetch();

        $guaranteeId = null;

        if ($existing) {
            // Update Existing
            $currentData = json_decode($existing['raw_data'], true) ?? [];
            $newData = array_merge($currentData, [
                'amount' => $g['amount'],
                'expiry_date' => $g['new_expiry_date'],
                'po_number' => $g['po_number'],
                'bank' => $g['bank_name'] ?? null,
                'last_import_update' => date('Y-m-d H:i:s')
            ]);
            
            // NEW: Ensure is_test_data is set if we are in dev mode and it wasn't set
            $updateSql = "UPDATE guarantees SET raw_data = ?, imported_at = CURRENT_TIMESTAMP";
            $updateParams = [json_encode($newData), $existing['id']];
            
            if ($autoMarkTestData) {
                $updateSql .= ", is_test_data = 1";
            }
            
            $updateSql .= " WHERE id = ?";
            
            $stmtUpdate = $pdo->prepare($updateSql);
            $stmtUpdate->execute($updateParams);
            $guaranteeId = $existing['id'];
        } else {
            // New Record
            $rawData = [
                'amount' => $g['amount'],
                'expiry_date' => $g['new_expiry_date'],
                'po_number' => $g['po_number'],
                'bank' => $g['bank_name'] ?? null,
                'supplier' => $g['supplier'] ?? null,
                'contract_number' => $g['contract_number'] ?? null,
                'type' => $g['type'] ?? null,
            ];
            
            $isTestDataFlag = $autoMarkTestData ? 1 : 0;
            
            $stmtInsert = $pdo->prepare("INSERT INTO guarantees (guarantee_number, raw_data, import_source, imported_at, is_test_data) VALUES (?, ?, 'email_import', CURRENT_TIMESTAMP, ?)");
            $stmtInsert->execute([$gNo, json_encode($rawData), $isTestDataFlag]);
            $guaranteeId = $pdo->lastInsertId();
        }

        // 2. Handle Attachments
        foreach ($evidenceFiles as $type => $tempRelPath) {
            if (!$tempRelPath) continue;

            $publicDir = realpath(__DIR__ . '/../public');
            $absTempPath = $publicDir . '/' . ltrim($tempRelPath, '/');
            
            if (file_exists($absTempPath)) {
                $ext = pathinfo($tempRelPath, PATHINFO_EXTENSION);
                $origName = basename($tempRelPath);
                
                // Target Dir: public/uploads/guarantees/{id}
                $targetDirRel = 'uploads/guarantees/' . $guaranteeId;
                $targetDirAbs = $publicDir . '/' . $targetDirRel;
                
                if (!is_dir($targetDirAbs)) {
                    mkdir($targetDirAbs, 0777, true);
                }
                
                $newFileName = $type . '_' . time() . '.' . $ext;
                $targetPathAbs = $targetDirAbs . '/' . $newFileName;
                $targetPathRel = $targetDirRel . '/' . $newFileName;
                
                // Copy file (keep original temp for other loop iterations)
                if (copy($absTempPath, $targetPathAbs)) {
                    // Insert into DB
                    $stmtAttach = $pdo->prepare("INSERT INTO guarantee_attachments (guarantee_id, file_name, file_path, file_type, uploaded_by, created_at) VALUES (?, ?, ?, ?, 'System Import', CURRENT_TIMESTAMP)");
                    $stmtAttach->execute([$guaranteeId, $origName, $targetPathRel, $type]);
                }
            }
        }
        $savedCount++;
    }

    // 3. Handle Fallback (No Guarantees Found but Files Exist)
    if (empty($input['guarantees']) && !empty($evidenceFiles)) {
        // Create a single DRAFT record to attach files to
        $draftNo = 'DRAFT-' . date('ymd-His');
        $rawData = [
            'status' => 'draft',
            'note' => 'تم الإنشاء تلقائياً من استيراد الإيميل (بدون إكسل). يرجى تعبئة البيانات.',
            'amount' => 0,
            'expiry_date' => null
        ];
        
        $isTestDataFlag = $autoMarkTestData ? 1 : 0;
        
        $stmtInsert = $pdo->prepare("INSERT INTO guarantees (guarantee_number, raw_data, import_source, imported_at, is_test_data) VALUES (?, ?, 'email_import_draft', CURRENT_TIMESTAMP, ?)");
        $stmtInsert->execute([$draftNo, json_encode($rawData), $isTestDataFlag]);
        $guaranteeId = $pdo->lastInsertId();
        
        // Attach files to this draft
        foreach ($evidenceFiles as $type => $tempRelPath) {
            if (!$tempRelPath) continue;

            $publicDir = realpath(__DIR__ . '/../public');
            $absTempPath = $publicDir . '/' . ltrim($tempRelPath, '/');
            
            if (file_exists($absTempPath)) {
                $ext = pathinfo($tempRelPath, PATHINFO_EXTENSION);
                $origName = basename($tempRelPath);
                $targetDirRel = 'uploads/guarantees/' . $guaranteeId;
                $targetDirAbs = $publicDir . '/' . $targetDirRel;
                
                if (!is_dir($targetDirAbs)) mkdir($targetDirAbs, 0777, true);
                
                $newFileName = $type . '_' . time() . '.' . $ext;
                $targetPathAbs = $targetDirAbs . '/' . $newFileName;
                $targetPathRel = $targetDirRel . '/' . $newFileName;
                
                if (copy($absTempPath, $targetPathAbs)) {
                    $stmtAttach = $pdo->prepare("INSERT INTO guarantee_attachments (guarantee_id, file_name, file_path, file_type, uploaded_by, created_at) VALUES (?, ?, ?, ?, 'System Import', CURRENT_TIMESTAMP)");
                    $stmtAttach->execute([$guaranteeId, $origName, $targetPathRel, $type]);
                }
            }
        }
        $savedCount++;
        // Return ID for redirection
        $pdo->commit();
        echo json_encode(['status' => 'success', 'saved_count' => 1, 'redirect_id' => $guaranteeId]);
        exit;
    }

    $pdo->commit();
    echo json_encode(['status' => 'success', 'saved_count' => $savedCount]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
