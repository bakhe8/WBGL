<?php
declare(strict_types=1);

/**
 * Legacy parse endpoint (V1) compatibility shim.
 *
 * This route now forwards to the V2 implementation while preserving:
 * - Auth/permission contract (`import_excel`)
 * - Endpoint availability toggle for controlled retirement
 * - Usage telemetry through requested endpoint version = v1
 */

require_once __DIR__ . '/_bootstrap.php';

use App\Support\Settings;

header('Content-Type: application/json; charset=utf-8');
wbgl_api_require_permission('import_excel');

$settings = Settings::getInstance();
$v1Enabled = (bool)$settings->get('PARSE_PASTE_V1_ENABLED', true);
if (!$v1Enabled) {
    wbgl_api_compat_fail(410, 'تم إيقاف مسار parse-paste.php. استخدم parse-paste-v2.php.', [
        'message' => 'تم إيقاف مسار parse-paste.php. استخدم parse-paste-v2.php.',
        'requested_version' => 'v1',
        'effective_version' => 'v2',
    ], 'validation');
}

if (!defined('WBGL_PARSE_PASTE_REQUESTED_VERSION')) {
    define('WBGL_PARSE_PASTE_REQUESTED_VERSION', 'v1');
}

require __DIR__ . '/parse-paste-v2.php';
