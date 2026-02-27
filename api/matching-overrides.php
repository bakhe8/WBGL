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

        echo json_encode([
            'success' => true,
            'items' => $rows,
            'count' => count($rows),
        ], JSON_UNESCAPED_UNICODE);
        exit;
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

        echo json_encode([
            'success' => true,
            'item' => $item,
        ], JSON_UNESCAPED_UNICODE);
        exit;
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

        echo json_encode([
            'success' => true,
            'item' => $item,
        ], JSON_UNESCAPED_UNICODE);
        exit;
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
        echo json_encode([
            'success' => true,
            'deleted' => $deleted,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method Not Allowed',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}

