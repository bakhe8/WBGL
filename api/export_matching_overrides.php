<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use App\Services\MatchingOverrideService;

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="matching_overrides.json"');
wbgl_api_require_permission('manage_data');

try {
    $service = new MatchingOverrideService();
    $rows = $service->exportRows(5000);
    echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (function_exists('header_remove')) {
        @header_remove('Content-Disposition');
    }
    wbgl_api_compat_fail(500, $e->getMessage(), [], 'internal');
}
