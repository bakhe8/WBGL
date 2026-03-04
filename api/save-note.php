<?php
/**
 * API: Save Note
 */
require_once __DIR__ . '/_bootstrap.php';

use App\Repositories\NoteRepository;
use App\Support\Database;
use App\Support\Input;

wbgl_api_require_permission('notes_create');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    wbgl_api_compat_fail(405, 'Method not allowed');
}

// Get JSON Input
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

$guaranteeId = Input::int($input, 'guarantee_id');
$content = Input::string($input, 'content', '');

if (!$guaranteeId || $content === '') {
    wbgl_api_compat_fail(400, 'Missing guarantee_id or content');
}

wbgl_api_require_guarantee_visibility((int)$guaranteeId);

try {
    $db = Database::connect();
    $context = wbgl_api_policy_surface_for_guarantee($db, (int)$guaranteeId);
    $policy = $context['policy'];
    $surface = $context['surface'];

    if (!($surface['can_create_notes'] ?? false)) {
        $stepStmt = $db->prepare('SELECT workflow_step FROM guarantee_decisions WHERE guarantee_id = ? LIMIT 1');
        $stepStmt->execute([(int)$guaranteeId]);
        $currentStep = (string)($stepStmt->fetchColumn() ?: 'unknown');

        wbgl_api_compat_fail(403, 'Permission Denied', [
            'message' => 'ليس لديك صلاحية إضافة ملاحظة على هذا السجل في حالته الحالية.',
            'required_permission' => 'notes_create',
            'current_step' => $currentStep,
            'reason_code' => 'SURFACE_NOT_GRANTED_NOTES_CREATE',
            'policy' => $policy,
            'surface' => $surface,
            'reasons' => $policy['reasons'] ?? [],
        ]);
    }

    $createdBy = wbgl_api_current_user_display();
    $repo = new NoteRepository();
    $id = $repo->create([
        'guarantee_id' => $guaranteeId,
        'content' => $content,
        'created_by' => $createdBy
    ]);
    
    wbgl_api_compat_success([
        'note' => [
            'id' => $id,
            'content' => $content,
            'created_at' => date('Y-m-d H:i:s'),
            'created_by' => $createdBy
        ],
        'policy' => $policy,
        'surface' => $surface,
        'reasons' => $policy['reasons'] ?? [],
    ]);
    
} catch (Exception $e) {
    wbgl_api_compat_fail(500, $e->getMessage(), [], 'internal');
}
