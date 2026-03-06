<?php
/**
 * API: Upload Attachment
 */
require_once __DIR__ . '/_bootstrap.php';

use App\Repositories\AttachmentRepository;
use App\Services\GuaranteeMutationPolicyService;
use App\Support\Database;

wbgl_api_require_permission('attachments_upload');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    wbgl_api_compat_fail(405, 'Method not allowed');
}

$guaranteeId = $_POST['guarantee_id'] ?? null;
if (!$guaranteeId) {
    wbgl_api_compat_fail(400, 'Missing guarantee_id');
}
wbgl_api_require_guarantee_visibility((int)$guaranteeId);

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    wbgl_api_compat_fail(400, 'File upload failed');
}

$file = $_FILES['file'];

$maxFileSize = 10 * 1024 * 1024; // 10 MB hard-limit at API layer.
if (!isset($file['size']) || (int)$file['size'] <= 0 || (int)$file['size'] > $maxFileSize) {
    wbgl_api_compat_fail(400, 'File size exceeds allowed limit');
}

$originalName = trim((string)($file['name'] ?? ''));
$extension = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
$allowedMimeByExtension = [
    'pdf' => ['application/pdf'],
    'png' => ['image/png'],
    'jpg' => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'doc' => ['application/msword'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    'xls' => ['application/vnd.ms-excel'],
    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
    'txt' => ['text/plain'],
];

if ($extension === '' || !array_key_exists($extension, $allowedMimeByExtension)) {
    wbgl_api_compat_fail(400, 'Unsupported file extension');
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$detectedMime = '';
if ($finfo !== false) {
    $detectedMime = (string)finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
}
if ($detectedMime === '' || !in_array($detectedMime, $allowedMimeByExtension[$extension], true)) {
    wbgl_api_compat_fail(400, 'Unsupported or mismatched file type');
}

try {
    $db = Database::connect();
    $context = wbgl_api_policy_surface_for_guarantee($db, (int)$guaranteeId);
    $policyContext = $context['policy'];
    $surface = $context['surface'];

    if (!($surface['can_upload_attachments'] ?? false)) {
        $stepStmt = $db->prepare('SELECT workflow_step FROM guarantee_decisions WHERE guarantee_id = ? LIMIT 1');
        $stepStmt->execute([(int)$guaranteeId]);
        $currentStep = (string)($stepStmt->fetchColumn() ?: 'unknown');

        wbgl_api_compat_fail(403, 'Permission Denied', [
            'message' => 'رفع المرفقات غير متاح على هذا السجل في حالته الحالية (عرض فقط).',
            'required_permission' => 'attachments_upload',
            'current_step' => $currentStep,
            'reason_code' => 'SURFACE_NOT_GRANTED_ATTACHMENTS_UPLOAD',
            'policy' => $policyContext,
            'surface' => $surface,
            'reasons' => $policyContext['reasons'] ?? [],
        ]);
    }

    $actor = wbgl_api_current_user_display();
    $policy = GuaranteeMutationPolicyService::evaluate(
        (int)$guaranteeId,
        $_POST,
        'upload_attachment',
        $actor
    );
    if (!$policy['allowed']) {
        $stepStmt = $db->prepare('SELECT workflow_step FROM guarantee_decisions WHERE guarantee_id = ? LIMIT 1');
        $stepStmt->execute([(int)$guaranteeId]);
        $currentStep = (string)($stepStmt->fetchColumn() ?: 'unknown');

        wbgl_api_compat_fail(403, 'released_read_only', [
            'message' => $policy['reason'],
            'required_permission' => 'attachments_upload',
            'current_step' => $currentStep,
            'reason_code' => 'MUTATION_POLICY_DENIED',
            'policy' => $policyContext,
            'surface' => $surface,
            'reasons' => $policyContext['reasons'] ?? [],
        ]);
    }

    $uploadDir = __DIR__ . '/../storage/attachments/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // Generate secure server-side filename
    $baseName = preg_replace('/[^a-zA-Z0-9_-]/', '', pathinfo($originalName, PATHINFO_FILENAME));
    if ($baseName === '') {
        $baseName = 'attachment';
    }
    $safeName = date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '_' . $baseName . '.' . $extension;
    $targetPath = $uploadDir . $safeName;

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        $uploadedBy = wbgl_api_current_user_display();
        $repo = new AttachmentRepository();
        $id = $repo->create([
            'guarantee_id' => $guaranteeId,
            'file_name' => $originalName, // Original user-visible name
            'file_path' => 'attachments/' . $safeName, // Relative path for storage
            'file_size' => $file['size'],
            'file_type' => $detectedMime,
            'uploaded_by' => $uploadedBy
        ]);
        
        wbgl_api_compat_success([
            'file' => [
                'id' => $id,
                'name' => $originalName,
                'path' => 'attachments/' . $safeName,
                'break_glass' => $policy['break_glass']
            ],
            'policy' => $policyContext,
            'surface' => $surface,
            'reasons' => $policyContext['reasons'] ?? [],
        ]);
        
    } else {
        throw new Exception('Failed to move uploaded file');
    }
} catch (Exception $e) {
    wbgl_api_compat_fail(500, 'Attachment upload failed', [], 'internal');
}
