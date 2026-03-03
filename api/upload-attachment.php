<?php
/**
 * API: Upload Attachment
 */
require_once __DIR__ . '/_bootstrap.php';

use App\Repositories\AttachmentRepository;
use App\Services\GuaranteeMutationPolicyService;
use App\Support\Database;

wbgl_api_json_headers();
wbgl_api_require_permission('attachments_upload');

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
wbgl_api_require_guarantee_visibility((int)$guaranteeId);

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'File upload failed']);
    exit;
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

        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Permission Denied',
            'message' => 'ليس لديك صلاحية رفع مرفقات على هذا السجل في حالته الحالية.',
            'required_permission' => 'attachments_upload',
            'current_step' => $currentStep,
            'reason_code' => 'SURFACE_NOT_GRANTED_ATTACHMENTS_UPLOAD',
            'policy' => $policyContext,
            'surface' => $surface,
            'reasons' => $policyContext['reasons'] ?? [],
            'request_id' => wbgl_api_request_id(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
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

        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'released_read_only',
            'message' => $policy['reason'],
            'required_permission' => 'attachments_upload',
            'current_step' => $currentStep,
            'reason_code' => 'MUTATION_POLICY_DENIED',
            'policy' => $policyContext,
            'surface' => $surface,
            'reasons' => $policyContext['reasons'] ?? [],
            'request_id' => wbgl_api_request_id(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
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
        
        echo json_encode([
            'success' => true, 
            'file' => [
                'id' => $id,
                'name' => $file['name'],
                'path' => 'attachments/' . $safeName,
                'break_glass' => $policy['break_glass']
            ],
            'policy' => $policyContext,
            'surface' => $surface,
            'reasons' => $policyContext['reasons'] ?? [],
            'request_id' => wbgl_api_request_id(),
        ], JSON_UNESCAPED_UNICODE);
        
    } else {
        throw new Exception('Failed to move uploaded file');
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'request_id' => wbgl_api_request_id(),
    ], JSON_UNESCAPED_UNICODE);
}
