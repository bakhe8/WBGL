<?php
// api/save-import.php

require_once __DIR__ . '/_bootstrap.php';

use App\Support\Database;

wbgl_api_require_permission('import_excel');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Method Not Allowed', 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['import_id']) || !isset($input['guarantees'])) {
        throw new RuntimeException('Invalid Input Data', 400);
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
            wbgl_api_require_guarantee_visibility((int)$existing['id']);

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
        wbgl_api_compat_success([
            'saved_count' => 1,
            'redirect_id' => (int)$guaranteeId,
        ]);
    }

    $pdo->commit();
    wbgl_api_compat_success([
        'saved_count' => (int)$savedCount,
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $statusCode = (int)$e->getCode();
    if ($statusCode < 400 || $statusCode >= 600) {
        $statusCode = 500;
    }
    wbgl_api_compat_fail($statusCode, $e->getMessage());
}
