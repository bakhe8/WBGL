<?php
declare(strict_types=1);

/**
 * CSP Report Endpoint
 *
 * Receives browser CSP violation reports while strict policy is in Report-Only mode.
 */
if (!defined('WBGL_API_SKIP_GLOBAL_CSRF')) {
    define('WBGL_API_SKIP_GLOBAL_CSRF', true);
}
require_once __DIR__ . '/_bootstrap.php';

if (!function_exists('wbgl_csp_report_value')) {
    function wbgl_csp_report_value(mixed $value, int $maxLength = 512): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $text = trim((string)$value);
        if ($text === '') {
            return '';
        }

        if (strlen($text) > $maxLength) {
            return substr($text, 0, $maxLength);
        }

        return $text;
    }
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'POST') {
    header('Allow: POST');
    wbgl_api_compat_fail(405, 'Method Not Allowed', [
        'message' => 'Method Not Allowed',
    ], 'validation');
}

$rawPayload = (string)file_get_contents('php://input');
if (strlen($rawPayload) > 131072) {
    wbgl_api_compat_fail(413, 'Payload Too Large', [
        'message' => 'Payload Too Large',
    ], 'validation');
}

$decoded = json_decode($rawPayload, true);
if (!is_array($decoded)) {
    http_response_code(204);
    exit;
}

$report = $decoded['csp-report'] ?? $decoded;
if (!is_array($report)) {
    http_response_code(204);
    exit;
}

$event = [
    'document_uri' => wbgl_csp_report_value($report['document-uri'] ?? $report['document_uri'] ?? null),
    'referrer' => wbgl_csp_report_value($report['referrer'] ?? null),
    'violated_directive' => wbgl_csp_report_value($report['violated-directive'] ?? $report['violated_directive'] ?? null),
    'effective_directive' => wbgl_csp_report_value($report['effective-directive'] ?? $report['effective_directive'] ?? null),
    'blocked_uri' => wbgl_csp_report_value($report['blocked-uri'] ?? $report['blocked_uri'] ?? null),
    'source_file' => wbgl_csp_report_value($report['source-file'] ?? $report['source_file'] ?? null),
    'line_number' => (int)($report['line-number'] ?? $report['line_number'] ?? 0),
    'column_number' => (int)($report['column-number'] ?? $report['column_number'] ?? 0),
    'disposition' => wbgl_csp_report_value($report['disposition'] ?? null),
];

error_log('[WBGL_CSP_REPORT][' . wbgl_api_request_id() . '] ' . json_encode(
    $event,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
));

http_response_code(204);
exit;
