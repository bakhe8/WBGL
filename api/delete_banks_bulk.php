<?php
require_once __DIR__ . '/_bootstrap.php';

use App\Support\Database;
use App\Support\Input;

wbgl_api_require_permission('bank_manage');

try {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        $data = [];
    }

    $idsRaw = Input::array($data, 'ids', []);
    if (!is_array($idsRaw) || $idsRaw === []) {
        wbgl_api_compat_fail(400, 'ids array is required');
    }

    $ids = [];
    foreach ($idsRaw as $value) {
        if (is_int($value) && $value > 0) {
            $ids[] = $value;
            continue;
        }
        if (is_string($value) && trim($value) !== '' && ctype_digit(trim($value))) {
            $normalized = (int)trim($value);
            if ($normalized > 0) {
                $ids[] = $normalized;
            }
        }
    }

    $ids = array_values(array_unique($ids));
    if ($ids === []) {
        wbgl_api_compat_fail(400, 'No valid ids supplied');
    }
    if (count($ids) > 500) {
        wbgl_api_compat_fail(400, 'Too many ids in one request');
    }

    $db = Database::connect();
    $placeholders = implode(', ', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("DELETE FROM banks WHERE id IN ({$placeholders})");
    $stmt->execute($ids);

    wbgl_api_compat_success([
        'success' => true,
        'requested_count' => count($ids),
        'deleted_count' => (int)$stmt->rowCount(),
    ]);
} catch (\Throwable $e) {
    wbgl_api_compat_fail(500, $e->getMessage(), [], 'internal');
}

