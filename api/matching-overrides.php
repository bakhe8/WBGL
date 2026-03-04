<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use App\Services\MatchingOverrideService;
use App\Support\Input;

header('Content-Type: application/json; charset=utf-8');
wbgl_api_require_permission('manage_data');

try {
    $service = new MatchingOverrideService();
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }

    if ($method === 'GET') {
        $limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 200;
        $activeOnly = isset($_GET['active_only']) ? ((int)$_GET['active_only'] === 1) : false;
        $rows = $service->list($limit, $activeOnly);

        wbgl_api_compat_success([
            'items' => $rows,
            'count' => count($rows),
        ]);
    }

    if ($method === 'POST') {
        $rawName = Input::string($input, 'raw_name', '');
        $supplierId = Input::int($input, 'supplier_id');
        $reason = Input::string($input, 'reason', '');
        $isActive = Input::bool($input, 'is_active', true);

        if (!$supplierId) {
            throw new RuntimeException('supplier_id مطلوب');
        }

        $item = $service->createOrUpdate(
            $rawName,
            (int)$supplierId,
            $reason !== '' ? $reason : null,
            $isActive,
            wbgl_api_current_user_display()
        );

        wbgl_api_compat_success([
            'item' => $item,
        ]);
    }

    if ($method === 'PUT' || $method === 'PATCH') {
        $id = Input::int($input, 'id');
        if (!$id) {
            throw new RuntimeException('id مطلوب');
        }

        $item = $service->updateById(
            (int)$id,
            $input,
            wbgl_api_current_user_display()
        );

        wbgl_api_compat_success([
            'item' => $item,
        ]);
    }

    if ($method === 'DELETE') {
        $id = Input::int($input, 'id');
        if (!$id && isset($_GET['id'])) {
            $id = (int)$_GET['id'];
        }
        if (!$id) {
            throw new RuntimeException('id مطلوب');
        }

        $deleted = $service->deleteById((int)$id);
        wbgl_api_compat_success([
            'deleted' => $deleted,
        ]);
    }

    wbgl_api_compat_fail(405, 'Method Not Allowed');
} catch (Throwable $e) {
    wbgl_api_compat_fail(400, $e->getMessage(), [], 'validation');
}
