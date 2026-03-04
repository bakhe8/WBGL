<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use App\Services\MatchingOverrideService;

header('Content-Type: application/json; charset=utf-8');
wbgl_api_require_permission('manage_data');

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        wbgl_api_compat_fail(405, 'Method Not Allowed');
    }

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('File upload failed');
    }

    $json = file_get_contents($_FILES['file']['tmp_name']);
    if ($json === false) {
        throw new RuntimeException('Unable to read uploaded file');
    }
    $rows = json_decode($json, true);
    if (!is_array($rows)) {
        throw new RuntimeException('Invalid JSON format');
    }

    $service = new MatchingOverrideService();
    $result = $service->importRows($rows, wbgl_api_current_user_display());

    $message = sprintf(
        'تم الاستيراد: %d إضافة، %d تحديث، %d تخطي',
        (int)$result['inserted'],
        (int)$result['updated'],
        (int)$result['skipped']
    );

    wbgl_api_compat_success([
        'message' => $message,
        'stats' => $result,
    ]);
} catch (Throwable $e) {
    wbgl_api_compat_fail(400, $e->getMessage(), [], 'validation');
}
