<?php
/**
 * V3 API - Import Excel File
 * Updated to use ImportService
 */


require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_bootstrap.php';

use App\Services\ImportService;
use App\Services\BatchAuditService;
use App\Services\NotificationPolicyService;
use App\Support\Settings;
use App\Support\TestDataVisibility;

header('Content-Type: application/json; charset=utf-8');
wbgl_api_require_permission('import_excel');
ini_set('memory_limit', '512M');
error_reporting(E_ALL);
ini_set('display_errors', 0);

/**
 * @return array{occurrence_rows:int,distinct_guarantees:int,real_guarantees:int,test_guarantees:int}
 */
function wbgl_import_fetch_batch_counts(PDO $db, string $batchIdentifier): array
{
    $stmt = $db->prepare(
        "SELECT
            COUNT(*) AS occurrence_rows,
            COUNT(DISTINCT o.guarantee_id) AS distinct_guarantees,
            COUNT(DISTINCT CASE WHEN COALESCE(g.is_test_data, 0) = 0 THEN o.guarantee_id END) AS real_guarantees,
            COUNT(DISTINCT CASE WHEN COALESCE(g.is_test_data, 0) = 1 THEN o.guarantee_id END) AS test_guarantees
         FROM guarantee_occurrences o
         JOIN guarantees g ON g.id = o.guarantee_id
         WHERE o.batch_identifier = ?"
    );
    $stmt->execute([$batchIdentifier]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'occurrence_rows' => (int)($row['occurrence_rows'] ?? 0),
        'distinct_guarantees' => (int)($row['distinct_guarantees'] ?? 0),
        'real_guarantees' => (int)($row['real_guarantees'] ?? 0),
        'test_guarantees' => (int)($row['test_guarantees'] ?? 0),
    ];
}

// Fatal Error Handler
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $logsDir = __DIR__ . '/../storage/logs';
        if (!is_dir($logsDir)) {
            @mkdir($logsDir, 0755, true);
        }
        file_put_contents($logsDir . '/import_fatal.log', date('Y-m-d H:i:s') . " FATAL: " . json_encode($error) . PHP_EOL, FILE_APPEND);
        try {
            NotificationPolicyService::emit(
                'import_failure',
                'فشل جسيم أثناء الاستيراد',
                'حدث خطأ قاتل أثناء معالجة الاستيراد.',
                [
                    'severity' => 'fatal',
                    'error_type' => (int)($error['type'] ?? 0),
                    'error_message' => (string)($error['message'] ?? ''),
                    'error_file' => basename((string)($error['file'] ?? '')),
                    'error_line' => (int)($error['line'] ?? 0),
                ],
                'import_fatal:' . date('YmdHi')
            );
        } catch (\Throwable $notificationError) {
            // Notification failure is non-blocking on fatal shutdown.
        }
        wbgl_api_compat_fail(500, 'Fatal Error', ['details' => $error], 'internal');
    }
});

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        wbgl_api_compat_fail(405, 'Method Not Allowed');
    }

    // Validate upload
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        wbgl_api_compat_fail(400, 'لم يتم استلام الملف أو حدث خطأ في الرفع', [], 'validation');
    }

    $file = $_FILES['file'];

    // Production Mode: Block test data creation
    $settings = Settings::getInstance();
    $isTestDataRequested = !empty($_POST['is_test_data']);
    if ($isTestDataRequested && !TestDataVisibility::canCurrentUserAccessTestData()) {
        wbgl_api_compat_fail(403, 'إنشاء بيانات الاختبار متاح للمطور فقط', [], 'permission');
    }
    if ($isTestDataRequested && $settings->isProductionMode()) {
        wbgl_api_compat_fail(403, 'لا يمكن إنشاء بيانات اختبار في وضع الإنتاج', [], 'permission');
    }

    // Validate extension
    $filename = $file['name'];
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    if (!in_array($ext, ['xlsx', 'xls'])) {
        wbgl_api_compat_fail(400, 'نوع الملف غير مسموح. يجب أن يكون ملف Excel (.xlsx أو .xls)', [], 'validation');
    }

    // Move to temporary location
    $uploadDir = __DIR__ . '/../storage/uploads';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $tempPath = $uploadDir . '/temp_' . date('Ymd_His') . '_' . uniqid() . '.xlsx';
    
    if (!move_uploaded_file($file['tmp_name'], $tempPath)) {
        wbgl_api_compat_fail(500, 'فشل نقل الملف المرفوع', [], 'internal');
    }

    try {
        // Import using service
        $service = new ImportService();
        $isTestData = $isTestDataRequested && TestDataVisibility::canCurrentUserAccessTestData();
        $actor = wbgl_api_current_user_display();
        $result = $service->importFromExcel($tempPath, $_POST['imported_by'] ?? $actor, $filename, $isTestData);
        $importedRecords = is_array($result['imported_records'] ?? null) ? $result['imported_records'] : [];
        $importedCount = count($importedRecords);
        $duplicateCount = (int)($result['duplicates'] ?? 0);
        $batchIdentifier = trim((string)($result['batch_identifier'] ?? ''));
        $skippedDetails = is_array($result['skipped'] ?? null) ? array_values($result['skipped']) : [];
        $errorDetails = is_array($result['errors'] ?? null) ? array_values($result['errors']) : [];

        // ✅ NEW: Mark as test data if requested (Phase 1)
        if ($isTestData && !empty($importedRecords)) {
            $testBatchId = $_POST['test_batch_id'] ?? null;
            $testNote = $_POST['test_note'] ?? null;
            
            $db = \App\Support\Database::connect();
            $repo = new \App\Repositories\GuaranteeRepository($db);
            
            foreach ($importedRecords as $record) {
                $repo->markAsTestData($record['id'], $testBatchId, $testNote);
            }
        }

        // Import events are now persisted atomically inside ImportService::importFromExcel.

        // --- POST IMPORT AUTOMATION ---
        $autoMatchStats = ['processed' => 0, 'auto_matched' => 0];
        try {
            // "Smart Processing" applies to any new guarantees, regardless of source (Excel, Manual, Paste)
            $importedBy = $_POST['imported_by'] ?? $actor;
            $processor = new \App\Services\SmartProcessingService('manual', $importedBy);
            $autoMatchStats = $processor->processNewGuarantees($importedCount);
        } catch (\Throwable $e) { /* Ignore automation errors, keep import success */ }
        // ------------------------------

        $batchCounts = [
            'occurrence_rows' => 0,
            'distinct_guarantees' => 0,
            'real_guarantees' => 0,
            'test_guarantees' => 0,
        ];
        $expectedBatchCount = $importedCount + $duplicateCount;
        $actualBatchCount = 0;
        $integrityWarning = false;
        if ($batchIdentifier !== '') {
            $batchCounts = wbgl_import_fetch_batch_counts($pdo, $batchIdentifier);
            $actualBatchCount = $isTestData ? $batchCounts['test_guarantees'] : $batchCounts['real_guarantees'];
            $integrityWarning = $actualBatchCount !== $expectedBatchCount;

            try {
                BatchAuditService::record(
                    $batchIdentifier,
                    $integrityWarning ? 'excel_import_completed_with_warning' : 'excel_import_completed',
                    $actor,
                    $integrityWarning ? 'IMPORT_COUNT_MISMATCH' : 'IMPORT_COMPLETED',
                    [
                        'file_name' => $filename,
                        'is_test_data' => $isTestData,
                        'imported' => $importedCount,
                        'duplicates' => $duplicateCount,
                        'skipped_count' => count($skippedDetails),
                        'errors_count' => count($errorDetails),
                        'expected_batch_count' => $expectedBatchCount,
                        'actual_batch_count' => $actualBatchCount,
                        'batch_counts' => $batchCounts,
                        'skipped_details' => array_slice($skippedDetails, 0, 50),
                        'error_details' => array_slice($errorDetails, 0, 50),
                    ]
                );
            } catch (\Throwable) {
                // Import result must still be returned even if batch audit insert fails.
            }
        }

        $message = "تم استيراد {$importedCount} سجل، وتمت المطابقة التلقائية لـ {$autoMatchStats['auto_matched']} سجل!";
        if ($duplicateCount > 0 || !empty($skippedDetails) || !empty($errorDetails) || $integrityWarning) {
            $message = sprintf(
                'تمت معالجة الملف: جديد %d، مكرر %d، متخطي %d، أخطاء %d%s',
                $importedCount,
                $duplicateCount,
                count($skippedDetails),
                count($errorDetails),
                $integrityWarning ? '، مع تحذير تحقق يحتاج مراجعة' : ''
            );
        }

        wbgl_api_compat_success([
            'data' => [
                'batch_identifier' => $batchIdentifier,
                'imported' => $importedCount,
                'duplicates' => $duplicateCount,
                'imported_records' => $importedRecords,
                'auto_matched' => $autoMatchStats['auto_matched'],
                'total_rows' => $result['total_rows'],
                'skipped' => count($skippedDetails),
                'errors' => count($errorDetails),
                'skipped_details' => $skippedDetails,
                'error_details' => $errorDetails,
                'expected_batch_count' => $expectedBatchCount,
                'actual_batch_count' => $actualBatchCount,
                'batch_counts' => $batchCounts,
                'integrity_warning' => $integrityWarning,
            ],
            'message' => $message,
        ]);
        
    } finally {
        // Cleanup: Delete temporary file
        if (file_exists($tempPath)) {
            @unlink($tempPath);
        }
    }

} catch (\Throwable $e) {
    // Log exception to file since user cannot see JSON response easily
    $logsDir = __DIR__ . '/../storage/logs';
    if (!is_dir($logsDir)) {
        @mkdir($logsDir, 0755, true);
    }
    file_put_contents(
        $logsDir . '/import_exceptions.log',
        date('Y-m-d H:i:s') . " EXCEPTION: " . $e->getMessage() . "\nStack: " . $e->getTraceAsString() . "\n",
        FILE_APPEND
    );

    try {
        $actor = wbgl_api_current_user_display();
        $fileName = isset($_FILES['file']['name']) ? (string)$_FILES['file']['name'] : '';
        NotificationPolicyService::emit(
            'import_failure',
            'فشل عملية الاستيراد',
            'تعذّر إكمال استيراد الملف.',
            [
                'severity' => 'error',
                'actor' => $actor,
                'file_name' => $fileName,
                'error_message' => $e->getMessage(),
                'error_file' => basename($e->getFile()),
                'error_line' => $e->getLine(),
            ],
            'import_failure:' . md5($e->getMessage() . ':' . basename($e->getFile()) . ':' . $e->getLine()) . ':' . date('YmdHi'),
            $actor
        );
    } catch (\Throwable $notificationError) {
        // Notification failure should not block API error response.
    }
    
    wbgl_api_compat_fail(500, $e->getMessage(), [
        'message' => $e->getMessage(),
        'error' => [
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
        ],
    ], 'internal');
}
