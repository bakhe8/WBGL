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
        mkdir($uploadDir, 0777, true);
    }

    // Generate secure filename
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $safeName = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9_-]/', '', pathinfo($file['name'], PATHINFO_FILENAME)) . '.' . $ext;
    $targetPath = $uploadDir . $safeName;

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        $uploadedBy = wbgl_api_current_user_display();
        $repo = new AttachmentRepository();
        $id = $repo->create([
            'guarantee_id' => $guaranteeId,
            'file_name' => $file['name'], // Original name
            'file_path' => 'attachments/' . $safeName, // Relative path for storage
            'file_size' => $file['size'],
            'file_type' => $file['type'],
            'uploaded_by' => $uploadedBy
        ]);
        
        wbgl_api_compat_success([
            'file' => [
                'id' => $id,
                'name' => $file['name'],
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
    wbgl_api_compat_fail(500, $e->getMessage(), [], 'internal');
}
