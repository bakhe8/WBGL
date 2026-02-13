<?php
/**
 * API: Upload Attachment
 */
require_once __DIR__ . '/../app/Support/autoload.php';

use App\Repositories\AttachmentRepository;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$guaranteeId = $_POST['guarantee_id'] ?? null;
if (!$guaranteeId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing guarantee_id']);
    exit;
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'File upload failed']);
    exit;
}

$file = $_FILES['file'];
$uploadDir = __DIR__ . '/../storage/attachments/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

// Generate secure filename
$ext = pathinfo($file['name'], PATHINFO_EXTENSION);
$safeName = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9_-]/', '', pathinfo($file['name'], PATHINFO_FILENAME)) . '.' . $ext;
$targetPath = $uploadDir . $safeName;

try {
    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        
        $repo = new AttachmentRepository();
        $id = $repo->create([
            'guarantee_id' => $guaranteeId,
            'file_name' => $file['name'], // Original name
            'file_path' => 'attachments/' . $safeName, // Relative path for storage
            'file_size' => $file['size'],
            'file_type' => $file['type'],
            'uploaded_by' => 'User' // Should come from session
        ]);
        
        echo json_encode([
            'success' => true, 
            'file' => [
                'id' => $id,
                'name' => $file['name'],
                'path' => 'attachments/' . $safeName
            ]
        ]);
        
    } else {
        throw new Exception('Failed to move uploaded file');
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
