<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../app/Services/TimelineRecorder.php';

use App\Repositories\GuaranteeRepository;
use App\Repositories\AttachmentRepository;
use App\Support\Database;
use App\Support\TypeNormalizer;
use App\Models\Guarantee;

wbgl_api_require_permission('manage_data');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input) || empty($input['draft_id']) || empty($input['guarantees'])) {
        throw new \RuntimeException("بيانات غير مكتملة");
    }

    $db = Database::connect();
    $db->beginTransaction();

    $repo = new GuaranteeRepository($db);
    $attachRepo = new AttachmentRepository($db);
    $actor = wbgl_api_current_user_display();
    $sourceDraftId = (int)$input['draft_id'];
    $sourceGuarantees = $input['guarantees'];

    wbgl_api_require_guarantee_visibility($sourceDraftId);

    // 1. Get source attachments
    $sourceAttachments = $attachRepo->getByGuaranteeId($sourceDraftId);

    $createdIds = [];

    foreach ($sourceGuarantees as $index => $gData) {
        $cleanAmount = str_replace(',', '', (string)$gData['amount']);
        
        $rawData = [
            'bg_number' => $gData['guarantee_number'],
            'supplier' => $gData['supplier'],
            'bank' => $gData['bank'],
            'amount' => $cleanAmount,
            'contract_number' => $gData['contract_number'],
            'expiry_date' => $gData['expiry_date'] ?: null,
            'type' => TypeNormalizer::normalize((string)($gData['type'] ?? 'INITIAL')),
            'details' => $gData['comment'] ?? '',
            'source' => 'smart_workstation'
        ];

        if ($index === 0) {
            // Transform the Draft record (the first one)
            $oldSnapshot = \App\Services\TimelineRecorder::createSnapshot($sourceDraftId);
            $repo->updateRawData($sourceDraftId, json_encode($rawData, JSON_UNESCAPED_UNICODE));
            
            // Set guarantee_number on the canonical guarantees schema (no legacy status_flags write).
            $stmt = $db->prepare("UPDATE guarantees SET guarantee_number = ? WHERE id = ?");
            $stmt->execute([$gData['guarantee_number'], $sourceDraftId]);
            
            $createdIds[] = $sourceDraftId;
            
            // Record Event
            \App\Services\TimelineRecorder::recordManualEditEvent(
                $sourceDraftId,
                $rawData,
                is_array($oldSnapshot) ? $oldSnapshot : null
            );
        } else {
            // Create New Guarantee
            $model = new Guarantee(
                id: null,
                guaranteeNumber: $gData['guarantee_number'],
                rawData: $rawData,
                importSource: 'workstation_batch_' . date('Ymd'),
                importedAt: date('Y-m-d H:i:s'),
                importedBy: $actor
            );
            
            $saved = $repo->create($model);
            $newId = $saved->id;
            $createdIds[] = $newId;

            // Clone Attachments from source to new record
            foreach ($sourceAttachments as $att) {
                $attachRepo->create([
                    'guarantee_id' => $newId,
                    'file_name' => $att['file_name'],
                    'file_path' => $att['file_path'],
                    'file_size' => $att['file_size'],
                    'file_type' => $att['file_type'],
                    'uploaded_by' => 'cloned'
                ]);
            }

            // Record Event
            \App\Services\TimelineRecorder::recordImportEvent($newId, 'workstation_cloned', $rawData);
        }
    }

    $db->commit();

    wbgl_api_compat_success([
        'message' => 'تم حفظ ' . count($createdIds) . ' ضمانات بنجاح',
        'redirect_id' => $createdIds[0]
    ]);

} catch (\Throwable $e) {
    if (isset($db)) {
        $db->rollBack();
    }
    wbgl_api_compat_fail(400, $e->getMessage());
}
