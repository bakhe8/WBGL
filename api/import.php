<?php
/**
 * V3 API - Import Excel File
 * Updated to use ImportService
 */


require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/Support/autoload.php';

use App\Services\ImportService;
use App\Support\Settings;

header('Content-Type: application/json; charset=utf-8');
ini_set('memory_limit', '512M');
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Fatal Error Handler
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        file_put_contents(__DIR__ . '/../debug_import_error.txt', date('Y-m-d H:i:s') . " FATAL: " . json_encode($error));
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Fatal Error', 'details' => $error]);
    }
});

try {
    // Validate upload
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new \RuntimeException('لم يتم استلام الملف أو حدث خطأ في الرفع');
    }

    $file = $_FILES['file'];

    // Production Mode: Block test data creation
    $settings = Settings::getInstance();
    if (!empty($_POST['is_test_data']) && $settings->isProductionMode()) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'لا يمكن إنشاء بيانات اختبار في وضع الإنتاج'
        ]);
        exit;
    }

    // Validate extension
    $filename = $file['name'];
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    if (!in_array($ext, ['xlsx', 'xls'])) {
        throw new \RuntimeException('نوع الملف غير مسموح. يجب أن يكون ملف Excel (.xlsx أو .xls)');
    }

    // Move to temporary location
    $uploadDir = __DIR__ . '/../storage/uploads';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $tempPath = $uploadDir . '/temp_' . date('Ymd_His') . '_' . uniqid() . '.xlsx';
    
    if (!move_uploaded_file($file['tmp_name'], $tempPath)) {
        throw new \RuntimeException('فشل نقل الملف المرفوع');
    }

    try {
        // Import using service
        $service = new ImportService();
        $isTestData = !empty($_POST['is_test_data']);
        $result = $service->importFromExcel($tempPath, $_POST['imported_by'] ?? 'web_user', $filename, $isTestData);

        // ✅ NEW: Mark as test data if requested (Phase 1)
        if (!empty($_POST['is_test_data']) && !empty($result['imported_records'])) {
            $testBatchId = $_POST['test_batch_id'] ?? null;
            $testNote = $_POST['test_note'] ?? null;
            
            $db = \App\Support\Database::connect();
            $repo = new \App\Repositories\GuaranteeRepository($db);
            
            foreach ($result['imported_records'] as $record) {
                $repo->markAsTestData($record['id'], $testBatchId, $testNote);
            }
        }

        // --- RECORD IMPORT EVENTS (Before Smart Processing!) ---
        try {
            if (!empty($result['imported_records'])) {
                foreach ($result['imported_records'] as $record) {

                    \App\Services\TimelineRecorder::recordImportEvent(
                        $record['id'], 
                        'excel', 
                        $record['raw_data'] // Pass explicit data
                    );
                }
            } elseif (!empty($result['imported_ids'])) { 
                 // Fallback for older ImportService versions if somehow mixed
                 foreach ($result['imported_ids'] as $gId) {
                    \App\Services\TimelineRecorder::recordImportEvent($gId, 'excel');
                 }
            }
        } catch (\Throwable $e) { 
            error_log("Failed to record import events: " . $e->getMessage());
        }
        // -------------------------------------------------------

        // --- POST IMPORT AUTOMATION ---
        $autoMatchStats = ['processed' => 0, 'auto_matched' => 0];
        try {
            // "Smart Processing" applies to any new guarantees, regardless of source (Excel, Manual, Paste)
            $importedBy = $_POST['imported_by'] ?? 'web_user';
            $processor = new \App\Services\SmartProcessingService('manual', $importedBy);
            $autoMatchStats = $processor->processNewGuarantees($result['imported']);
        } catch (\Throwable $e) { /* Ignore automation errors, keep import success */ }
        // ------------------------------

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data' => [
                'imported' => $result['imported'],
                'auto_matched' => $autoMatchStats['auto_matched'],
                'total_rows' => $result['total_rows'],
                'skipped' => count($result['skipped']),
                'errors' => count($result['errors']),
                'skipped_details' => $result['skipped'],
                'error_details' => $result['errors'],
            ],
            'message' => "تم استيراد {$result['imported']} سجل، وتمت المطابقة التلقائية لـ {$autoMatchStats['auto_matched']} سجل!",
        ]);
        
    } finally {
        // Cleanup: Delete temporary file
        if (file_exists($tempPath)) {
            @unlink($tempPath);
        }
    }

} catch (\Throwable $e) {
    // Log exception to file since user cannot see JSON response easily
    file_put_contents(__DIR__ . '/../debug_exception.txt', date('Y-m-d H:i:s') . " EXCEPTION: " . $e->getMessage() . "\nStack: " . $e->getTraceAsString() . "\n", FILE_APPEND);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error' => [
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
        ]
    ]);
}
