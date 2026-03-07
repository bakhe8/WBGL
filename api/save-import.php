<?php
// api/save-import.php

require_once __DIR__ . '/_bootstrap.php';

use App\Support\Database;
use App\Services\ImportService;
use App\Services\TimelineRecorder;
use App\Support\TypeNormalizer;

/**
 * Resolve legacy/new evidence paths to absolute filesystem paths.
 *
 * Supported inputs:
 * - /api/evidence-file.php?temp_path=... (secured temp evidence URL)
 * - /uploads/... (legacy public temp)
 * - uploads/...  (legacy public temp)
 * - /storage/uploads/... (new storage temp)
 * - storage/uploads/...  (new storage temp)
 */
function wbgl_resolve_evidence_source_path(string $relativePath): ?string
{
    $relativePath = trim($relativePath);
    if ($relativePath === '') {
        return null;
    }
    $tempPathFromReference = wbgl_extract_temp_path_from_evidence_reference($relativePath);
    if (is_string($tempPathFromReference) && $tempPathFromReference !== '') {
        $relativePath = $tempPathFromReference;
    }

    $projectRoot = realpath(__DIR__ . '/..');
    if ($projectRoot === false) {
        return null;
    }

    $normalized = str_replace('\\', '/', $relativePath);
    $normalized = preg_replace('#/+#', '/', $normalized) ?: $normalized;
    $normalized = ltrim($normalized);

    $candidates = [];
    if (str_starts_with($normalized, '/storage/')) {
        $candidates[] = $projectRoot . $normalized;
    }
    if (str_starts_with($normalized, 'storage/')) {
        $candidates[] = $projectRoot . '/' . $normalized;
    }
    if (str_starts_with($normalized, '/uploads/')) {
        $candidates[] = $projectRoot . '/public' . $normalized;
    }
    if (str_starts_with($normalized, 'uploads/')) {
        $candidates[] = $projectRoot . '/public/' . $normalized;
    }
    if (str_starts_with($normalized, '/public/uploads/')) {
        $candidates[] = $projectRoot . $normalized;
    }
    if (str_starts_with($normalized, 'public/uploads/')) {
        $candidates[] = $projectRoot . '/' . $normalized;
    }

    foreach ($candidates as $candidate) {
        $real = realpath($candidate);
        if ($real === false || !is_file($real)) {
            continue;
        }

        $realNormalized = str_replace('\\', '/', $real);
        $allowedRoots = [
            str_replace('\\', '/', $projectRoot . '/public/uploads/'),
            str_replace('\\', '/', $projectRoot . '/storage/uploads/'),
        ];
        foreach ($allowedRoots as $root) {
            if (str_starts_with($realNormalized, $root)) {
                return $real;
            }
        }
    }

    return null;
}

function wbgl_extract_temp_path_from_evidence_reference(string $reference): ?string
{
    $reference = trim($reference);
    if ($reference === '') {
        return null;
    }

    $parts = @parse_url($reference);
    if (!is_array($parts)) {
        return null;
    }

    $path = trim((string)($parts['path'] ?? ''));
    if ($path === '') {
        return null;
    }

    if (!preg_match('#(^|/)api/evidence-file\.php$#i', $path)) {
        return null;
    }

    $query = (string)($parts['query'] ?? '');
    if ($query === '') {
        return null;
    }

    $parsedQuery = [];
    parse_str($query, $parsedQuery);
    $tempPath = trim((string)($parsedQuery['temp_path'] ?? ''));
    return $tempPath !== '' ? $tempPath : null;
}

function wbgl_extract_original_name_from_evidence_reference(string $reference): ?string
{
    $reference = trim($reference);
    if ($reference === '') {
        return null;
    }

    $parts = @parse_url($reference);
    if (!is_array($parts)) {
        return null;
    }

    $query = (string)($parts['query'] ?? '');
    if ($query === '') {
        return null;
    }

    $parsedQuery = [];
    parse_str($query, $parsedQuery);
    $name = trim((string)($parsedQuery['name'] ?? ''));
    return $name !== '' ? basename($name) : null;
}

function wbgl_resolve_evidence_original_name(string $reference, string $absTempPath): string
{
    $nameFromReference = wbgl_extract_original_name_from_evidence_reference($reference);
    if (is_string($nameFromReference) && $nameFromReference !== '') {
        return $nameFromReference;
    }

    $fromReferencePath = basename((string)(parse_url($reference, PHP_URL_PATH) ?: ''));
    if ($fromReferencePath !== '' && strtolower($fromReferencePath) !== 'evidence-file.php') {
        return $fromReferencePath;
    }

    return basename($absTempPath);
}

function wbgl_is_allowed_evidence_extension(string $filename): bool
{
    $ext = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
    if ($ext === '') {
        return false;
    }

    $allowed = [
        'pdf', 'png', 'jpg', 'jpeg', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'msg',
    ];

    return in_array($ext, $allowed, true);
}

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
    $batchIdentifier = trim((string)($input['import_id'] ?? ''));
    if ($batchIdentifier === '') {
        $batchIdentifier = 'email_import_' . date('Ymd_His');
    }
    $batchIdentifier = ImportService::resolveCompatibleBatchIdentifier($pdo, $batchIdentifier, $autoMarkTestData);

    foreach ($input['guarantees'] as $g) {
        $gNo = $g['guarantee_number'];
        $typeInput = trim((string)($g['type'] ?? ''));
        $normalizedType = $typeInput !== '' ? TypeNormalizer::normalize($typeInput) : null;
        
        // 1. Find Existing or Create New
        $stmtFind = $pdo->prepare("SELECT id, raw_data FROM guarantees WHERE guarantee_number = ?");
        $stmtFind->execute([$gNo]);
        $existing = $stmtFind->fetch();

        $guaranteeId = null;

        if ($existing) {
            wbgl_api_require_guarantee_visibility((int)$existing['id']);
            $existingId = (int)$existing['id'];
            $oldSnapshot = TimelineRecorder::createSnapshot($existingId);

            // Update Existing
            $currentData = json_decode($existing['raw_data'], true) ?? [];
            $newData = array_merge($currentData, [
                'amount' => $g['amount'],
                'expiry_date' => $g['new_expiry_date'],
                'po_number' => $g['po_number'],
                'bank' => $g['bank_name'] ?? null,
                'last_import_update' => date('Y-m-d H:i:s')
            ]);
            if ($normalizedType !== null) {
                $newData['type'] = $normalizedType;
            }
            
            // NEW: Ensure is_test_data is set if we are in dev mode and it wasn't set
            $updateSql = "UPDATE guarantees SET raw_data = ?, imported_at = CURRENT_TIMESTAMP";
            $updateParams = [json_encode($newData), $existing['id']];
            
            if ($autoMarkTestData) {
                $updateSql .= ", is_test_data = 1";
            }
            
            $updateSql .= " WHERE id = ?";
            
            $stmtUpdate = $pdo->prepare($updateSql);
            $stmtUpdate->execute($updateParams);
            $guaranteeId = $existingId;

            $manualEditEventId = TimelineRecorder::recordManualEditEvent(
                $existingId,
                $newData,
                is_array($oldSnapshot) ? $oldSnapshot : null
            );
            if (!$manualEditEventId) {
                throw new RuntimeException('Failed to record timeline event for email import update');
            }
        } else {
            // New Record
            $rawData = [
                'amount' => $g['amount'],
                'expiry_date' => $g['new_expiry_date'],
                'po_number' => $g['po_number'],
                'bank' => $g['bank_name'] ?? null,
                'supplier' => $g['supplier'] ?? null,
                'contract_number' => $g['contract_number'] ?? null,
                'type' => $normalizedType,
            ];
            
            $isTestDataFlag = $autoMarkTestData ? 1 : 0;
            
            $stmtInsert = $pdo->prepare("INSERT INTO guarantees (guarantee_number, raw_data, import_source, imported_at, is_test_data) VALUES (?, ?, 'email_import', CURRENT_TIMESTAMP, ?)");
            $stmtInsert->execute([$gNo, json_encode($rawData), $isTestDataFlag]);
            $guaranteeId = $pdo->lastInsertId();

            $importEventId = TimelineRecorder::recordImportEvent((int)$guaranteeId, 'email', $rawData);
            if (!$importEventId) {
                throw new RuntimeException('Failed to record timeline event for email import create');
            }
        }

        if ($guaranteeId !== null) {
            // Keep batch ledger consistent for both create and update flows.
            ImportService::recordOccurrence((int)$guaranteeId, $batchIdentifier, 'email', null, $pdo);
        }

        // 2. Handle Attachments
        foreach ($evidenceFiles as $type => $tempRelPath) {
            if (!$tempRelPath) continue;

            $absTempPath = wbgl_resolve_evidence_source_path((string)$tempRelPath);
            
            if (is_string($absTempPath) && file_exists($absTempPath)) {
                $origName = wbgl_resolve_evidence_original_name((string)$tempRelPath, $absTempPath);
                if (!wbgl_is_allowed_evidence_extension($origName)) {
                    continue;
                }
                $ext = strtolower((string)pathinfo($origName, PATHINFO_EXTENSION));
                
                // Target Dir: storage/attachments/guarantees/{id}
                $storageRoot = realpath(__DIR__ . '/../storage');
                if ($storageRoot === false) {
                    throw new RuntimeException('Storage directory is missing');
                }
                $targetDirRel = 'attachments/guarantees/' . $guaranteeId;
                $targetDirAbs = $storageRoot . '/' . $targetDirRel;
                
                if (!is_dir($targetDirAbs)) {
                    mkdir($targetDirAbs, 0755, true);
                }
                
                $safeType = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$type) ?: 'evidence';
                $newFileName = $safeType . '_' . time() . '.' . $ext;
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

        $importEventId = TimelineRecorder::recordImportEvent((int)$guaranteeId, 'email', $rawData);
        if (!$importEventId) {
            throw new RuntimeException('Failed to record timeline event for email import draft create');
        }

        ImportService::recordOccurrence((int)$guaranteeId, $batchIdentifier, 'email', null, $pdo);
        
        // Attach files to this draft
        foreach ($evidenceFiles as $type => $tempRelPath) {
            if (!$tempRelPath) continue;

            $absTempPath = wbgl_resolve_evidence_source_path((string)$tempRelPath);
            
            if (is_string($absTempPath) && file_exists($absTempPath)) {
                $origName = wbgl_resolve_evidence_original_name((string)$tempRelPath, $absTempPath);
                if (!wbgl_is_allowed_evidence_extension($origName)) {
                    continue;
                }
                $ext = strtolower((string)pathinfo($origName, PATHINFO_EXTENSION));
                $storageRoot = realpath(__DIR__ . '/../storage');
                if ($storageRoot === false) {
                    throw new RuntimeException('Storage directory is missing');
                }
                $targetDirRel = 'attachments/guarantees/' . $guaranteeId;
                $targetDirAbs = $storageRoot . '/' . $targetDirRel;
                
                if (!is_dir($targetDirAbs)) mkdir($targetDirAbs, 0755, true);
                
                $safeType = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$type) ?: 'evidence';
                $newFileName = $safeType . '_' . time() . '.' . $ext;
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
