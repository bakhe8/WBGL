<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

function wbgl_evidence_is_allowed_extension(string $filename): bool
{
    $extension = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
    if ($extension === '') {
        return false;
    }

    $allowed = ['pdf', 'png', 'jpg', 'jpeg', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'msg'];
    return in_array($extension, $allowed, true);
}

function wbgl_evidence_resolve_absolute_temp_path(string $tempPath): ?string
{
    $tempPath = trim($tempPath);
    if ($tempPath === '') {
        return null;
    }

    $projectRoot = realpath(__DIR__ . '/..');
    if ($projectRoot === false) {
        return null;
    }

    $normalized = str_replace('\\', '/', $tempPath);
    $normalized = preg_replace('#/+#', '/', $normalized) ?: $normalized;
    $normalized = ltrim($normalized);

    $candidates = [];
    if (str_starts_with($normalized, 'storage/uploads/temp/')) {
        $candidates[] = $projectRoot . '/' . $normalized;
    }
    if (str_starts_with($normalized, '/storage/uploads/temp/')) {
        $candidates[] = $projectRoot . $normalized;
    }
    if (str_starts_with($normalized, 'uploads/temp/')) {
        $candidates[] = $projectRoot . '/public/' . $normalized; // Legacy compatibility
    }
    if (str_starts_with($normalized, '/uploads/temp/')) {
        $candidates[] = $projectRoot . '/public' . $normalized; // Legacy compatibility
    }
    if (str_starts_with($normalized, 'public/uploads/temp/')) {
        $candidates[] = $projectRoot . '/' . $normalized; // Legacy compatibility
    }
    if (str_starts_with($normalized, '/public/uploads/temp/')) {
        $candidates[] = $projectRoot . $normalized; // Legacy compatibility
    }

    $allowedRoots = [
        str_replace('\\', '/', $projectRoot . '/storage/uploads/temp/'),
        str_replace('\\', '/', $projectRoot . '/public/uploads/temp/'),
    ];

    foreach ($candidates as $candidate) {
        $real = realpath($candidate);
        if ($real === false || !is_file($real)) {
            continue;
        }

        $realNormalized = str_replace('\\', '/', $real);
        foreach ($allowedRoots as $allowedRoot) {
            if (str_starts_with($realNormalized, $allowedRoot)) {
                return $real;
            }
        }
    }

    return null;
}

function wbgl_evidence_detect_mime(string $absolutePath): string
{
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        return 'application/octet-stream';
    }
    $mime = (string)finfo_file($finfo, $absolutePath);
    finfo_close($finfo);
    return $mime !== '' ? $mime : 'application/octet-stream';
}

wbgl_api_require_permission('import_excel');

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        wbgl_api_compat_fail(405, 'Method not allowed');
    }

    $tempPath = trim((string)($_GET['temp_path'] ?? ''));
    if ($tempPath === '') {
        wbgl_api_compat_fail(400, 'temp_path is required');
    }

    $absolutePath = wbgl_evidence_resolve_absolute_temp_path($tempPath);
    if ($absolutePath === null) {
        wbgl_api_compat_fail(404, 'Evidence file not found');
    }

    $displayName = trim((string)($_GET['name'] ?? basename($absolutePath)));
    if (!wbgl_evidence_is_allowed_extension($displayName)) {
        wbgl_api_compat_fail(400, 'Unsupported file extension');
    }

    $inline = (string)($_GET['inline'] ?? '1') !== '0';
    $disposition = $inline ? 'inline' : 'attachment';
    $mime = wbgl_evidence_detect_mime($absolutePath);
    $size = (int)(filesize($absolutePath) ?: 0);

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . $size);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=60');
    header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '', $displayName) . '"');

    readfile($absolutePath);
    exit;
} catch (Throwable $e) {
    wbgl_api_compat_fail(500, $e->getMessage());
}
