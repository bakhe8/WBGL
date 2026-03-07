<?php
// api/import-email.php

require_once __DIR__ . '/_bootstrap.php';

use App\Services\Import\EmailImportService;
use App\Support\Settings;

header('Content-Type: application/json');
wbgl_api_require_permission('import_excel');

ob_start();

try {
    if (!(bool)Settings::getInstance()->get('EMAIL_MSG_IMPORT_ENABLED', false)) {
        wbgl_api_compat_fail(
            410,
            'تم تعطيل استيراد ملفات MSG وفق سياسة النظام الحالية.',
            ['feature' => 'email_msg_import', 'enabled' => false],
            'validation'
        );
    }

    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        wbgl_api_compat_fail(405, 'Method Not Allowed');
    }

    if (!isset($_FILES['email_file']) || $_FILES['email_file']['error'] !== UPLOAD_ERR_OK) {
        wbgl_api_compat_fail(400, 'No valid file uploaded', [], 'validation');
    }

    $tmpPath = $_FILES['email_file']['tmp_name'];
    $originalName = $_FILES['email_file']['name'];
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if ($extension !== 'msg') {
        wbgl_api_compat_fail(400, 'Only .msg files are supported', [], 'validation');
    }

    // Move to safe temp
    $safeTempPath = sys_get_temp_dir() . '/' . uniqid('upload_') . '.msg';
    if (!move_uploaded_file($tmpPath, $safeTempPath)) {
        wbgl_api_compat_fail(500, 'Failed to move uploaded file', [], 'internal');
    }

    $service = new EmailImportService();
    $result = $service->processMsgFile($safeTempPath);

    @unlink($safeTempPath);

    ob_clean(); // Discard any warnings/notices outputted so far
    wbgl_api_compat_success(is_array($result) ? $result : ['result' => $result]);

} catch (Throwable $e) {
    ob_clean();
    wbgl_api_compat_fail(500, $e->getMessage(), [], 'internal');
}
