<?php
// api/import-email.php

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Services\Import\EmailImportService;

header('Content-Type: application/json');

ob_start();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Method Not Allowed');
    }

    if (!isset($_FILES['email_file']) || $_FILES['email_file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No valid file uploaded');
    }

    $tmpPath = $_FILES['email_file']['tmp_name'];
    $originalName = $_FILES['email_file']['name'];
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if ($extension !== 'msg') {
        throw new Exception('Only .msg files are supported');
    }

    // Move to safe temp
    $safeTempPath = sys_get_temp_dir() . '/' . uniqid('upload_') . '.msg';
    if (!move_uploaded_file($tmpPath, $safeTempPath)) {
        throw new Exception('Failed to move uploaded file');
    }

    $service = new EmailImportService();
    $result = $service->processMsgFile($safeTempPath);

    @unlink($safeTempPath);

    ob_clean(); // Discard any warnings/notices outputted so far
    echo json_encode($result);

} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
