<?php
/**
 * Batch Operations API
 * Handles all batch-level operations
 */

require_once __DIR__ . '/_bootstrap.php';

use App\Services\BatchService;
use App\Support\Input;
use App\Support\Guard;
use App\Services\BreakGlassService;

header('Content-Type: application/json; charset=utf-8');
wbgl_api_require_login();

$method = $_SERVER['REQUEST_METHOD'];
$service = new BatchService();

try {
    if ($method === 'POST') {
        $rawBody = file_get_contents('php://input');
        $jsonInput = json_decode($rawBody, true);
        $input = array_merge($_POST, is_array($jsonInput) ? $jsonInput : []);

        $action = Input::string($input, 'action', '');
        $importSource = Input::string($input, 'import_source', '');
        
        if (!$importSource && $action !== 'list') {
            throw new \RuntimeException('import_source مطلوب');
        }

        $selectedGuaranteeIds = Input::array($input, 'guarantee_ids', null);
        if (is_array($selectedGuaranteeIds)) {
            foreach ($selectedGuaranteeIds as $selectedGuaranteeId) {
                if (is_numeric($selectedGuaranteeId) && (int)$selectedGuaranteeId > 0) {
                    wbgl_api_require_guarantee_visibility((int)$selectedGuaranteeId);
                }
            }
        }

        if ($action !== 'reopen') {
            wbgl_api_require_permission('manage_data');
        }
        
        switch ($action) {
            case 'extend':
                $newExpiry = Input::string($input, 'new_expiry', '');
                $newExpiry = $newExpiry !== '' ? $newExpiry : null;
                $result = $service->extendBatch(
                    $importSource,
                    $newExpiry,
                    Input::string($input, 'user_id', 'web_user'),
                    Input::array($input, 'guarantee_ids', null)
                );
                break;
                
            case 'release':
                $reason = Input::string($input, 'reason', '');
                $reason = $reason !== '' ? $reason : null;
                $result = $service->releaseBatch(
                    $importSource,
                    $reason,
                    Input::string($input, 'user_id', 'web_user'),
                    Input::array($input, 'guarantee_ids', null)
                );
                break;

            case 'reduce':
                $reductionsRaw = Input::array($input, 'reductions', null);
                $reductions = [];
                if (is_array($reductionsRaw) && !empty($reductionsRaw)) {
                    $isAssoc = array_keys($reductionsRaw) !== range(0, count($reductionsRaw) - 1);
                    if ($isAssoc) {
                        foreach ($reductionsRaw as $gid => $amount) {
                            if (is_numeric($gid) && is_numeric($amount)) {
                                $reductions[(int) $gid] = (float) $amount;
                            }
                        }
                    } else {
                        foreach ($reductionsRaw as $item) {
                            if (!is_array($item)) {
                                continue;
                            }
                            $gid = $item['guarantee_id'] ?? $item['id'] ?? null;
                            $amount = $item['new_amount'] ?? $item['amount'] ?? null;
                            if (is_numeric($gid) && is_numeric($amount)) {
                                $reductions[(int) $gid] = (float) $amount;
                            }
                        }
                    }
                }

                $newAmountRaw = Input::string($input, 'new_amount', '');
                $newAmount = null;
                if ($newAmountRaw !== '') {
                    if (!is_numeric($newAmountRaw)) {
                        throw new \RuntimeException('المبلغ غير صحيح');
                    }
                    $newAmount = (float) $newAmountRaw;
                }

                if (empty($reductions) && ($newAmount === null || $newAmount <= 0)) {
                    throw new \RuntimeException('المبلغ غير صحيح');
                }
                $result = $service->reduceBatch(
                    $importSource,
                    $newAmount,
                    Input::string($input, 'user_id', 'web_user'),
                    Input::array($input, 'guarantee_ids', null),
                    !empty($reductions) ? $reductions : null
                );
                break;
                
            case 'close':
                $result = $service->closeBatch($importSource, wbgl_api_current_user_display());
                break;
                
            case 'update_metadata':  // Decision #2
                $batchName = Input::string($input, 'batch_name', '');
                $batchNotes = Input::string($input, 'batch_notes', '');
                $batchName = $batchName !== '' ? $batchName : null;
                $batchNotes = $batchNotes !== '' ? $batchNotes : null;
                $result = $service->updateMetadata($importSource, $batchName, $batchNotes);
                break;
                
            case 'reopen':  // Decision #7
                $actor = wbgl_api_current_user_display();
                $reason = Input::string($input, 'reason', '');
                $reason = trim($reason);

                // Permanent governance policy: reason is always required for batch reopen.
                if ($reason === '') {
                    throw new \RuntimeException('سبب إعادة فتح الدفعة مطلوب');
                }

                $breakGlass = null;
                if (BreakGlassService::isRequested($input)) {
                    $breakGlass = BreakGlassService::authorizeAndRecord(
                        $input,
                        'batch_reopen',
                        'batch',
                        $importSource,
                        $actor
                    );
                }

                if (!Guard::has('reopen_batch') && $breakGlass === null) {
                    throw new \RuntimeException('ليس لديك صلاحية إعادة فتح الدفعات');
                }

                $result = $service->reopenBatch(
                    $importSource,
                    $actor,
                    $reason,
                    $breakGlass
                );
                break;
                
            case 'summary':
                $result = $service->getBatchSummary($importSource);
                if ($result === null) {
                    $result = [
                        'success' => false,
                        'error' => 'الدفعة غير موجودة أو فارغة'
                    ];
                } else {
                    $result['success'] = true;
                }
                break;
                
            default:
                throw new \RuntimeException('Action غير معروف: ' . $action);
        }

        if (is_array($result) && array_key_exists('success', $result) && $result['success'] === false) {
            wbgl_api_compat_fail(
                200,
                (string)($result['error'] ?? 'Batch operation failed'),
                $result,
                'validation'
            );
        }

        wbgl_api_compat_success(is_array($result) ? $result : []);
        
    } elseif ($method === 'GET') {
        wbgl_api_require_permission('manage_data');
        // Get batch summary
        $importSource = $_GET['import_source'] ?? '';
        
        if (!$importSource) {
            throw new \RuntimeException('import_source مطلوب');
        }
        
        $result = $service->getBatchSummary($importSource);
        if ($result === null) {
            wbgl_api_compat_fail(404, 'الدفعة غير موجودة', [
                'success' => false,
                'error' => 'الدفعة غير موجودة'
            ], 'not_found');
        } else {
            $result['success'] = true;
            wbgl_api_compat_success($result);
        }
        
    } else {
        throw new \RuntimeException('Method غير مدعوم');
    }
    
} catch (\Throwable $e) {
    wbgl_api_compat_fail(500, $e->getMessage(), [], 'internal');
}
