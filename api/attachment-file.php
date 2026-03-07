<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use App\Repositories\AttachmentRepository;
use App\Support\Database;

function wbgl_attachment_resolve_absolute_path(string $relativePath): ?string
{
    $relativePath = trim($relativePath);
    if ($relativePath === '') {
        return null;
    }

    $projectRoot = realpath(__DIR__ . '/..');
    if ($projectRoot === false) {
        return null;
    }

    $normalized = str_replace('\\', '/', $relativePath);
    $normalized = preg_replace('#/+#', '/', $normalized) ?: $normalized;
    $normalized = ltrim($normalized);

    $candidates = [];
    if (str_starts_with($normalized, 'attachments/')) {
        $candidates[] = $projectRoot . '/storage/' . $normalized;
    }
    if (str_starts_with($normalized, 'uploads/')) {
        $candidates[] = $projectRoot . '/storage/' . $normalized;
        $candidates[] = $projectRoot . '/public/' . $normalized; // Legacy compatibility
    }
    if (str_starts_with($normalized, 'storage/')) {
        $candidates[] = $projectRoot . '/' . $normalized;
    }
    if (str_starts_with($normalized, '/storage/')) {
        $candidates[] = $projectRoot . $normalized;
    }
    if (str_starts_with($normalized, 'public/uploads/')) {
        $candidates[] = $projectRoot . '/' . $normalized;
    }
    if (str_starts_with($normalized, '/public/uploads/')) {
        $candidates[] = $projectRoot . $normalized;
    }

    $allowedRoots = [
        str_replace('\\', '/', $projectRoot . '/storage/attachments/'),
        str_replace('\\', '/', $projectRoot . '/storage/uploads/'),
        str_replace('\\', '/', $projectRoot . '/public/uploads/'),
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

function wbgl_attachment_detect_mime(string $absolutePath): string
{
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        return 'application/octet-stream';
    }
    $mime = (string)finfo_file($finfo, $absolutePath);
    finfo_close($finfo);
    return $mime !== '' ? $mime : 'application/octet-stream';
}

function wbgl_attachment_safe_filename(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return 'attachment.bin';
    }

    $safe = preg_replace('/[^\w\-. ]+/u', '_', $name);
    $safe = trim((string)$safe, " .\t\n\r\0\x0B");
    return $safe !== '' ? $safe : 'attachment.bin';
}

wbgl_api_require_permission('attachments_view');

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        wbgl_api_compat_fail(405, 'Method not allowed');
    }

    $attachmentId = (int)($_GET['id'] ?? 0);
    if ($attachmentId <= 0) {
        wbgl_api_compat_fail(400, 'Attachment id is required');
    }

    $db = Database::connect();
    $repo = new AttachmentRepository($db);
    $attachment = $repo->find($attachmentId);
    if (!is_array($attachment)) {
        wbgl_api_compat_fail(404, 'Attachment not found');
    }

    $guaranteeId = (int)($attachment['guarantee_id'] ?? 0);
    if ($guaranteeId <= 0) {
        wbgl_api_compat_fail(404, 'Attachment not found');
    }

    wbgl_api_require_guarantee_visibility($guaranteeId);
    $surfaceContext = wbgl_api_policy_surface_for_guarantee($db, $guaranteeId);
    $canViewAttachments = (bool)($surfaceContext['surface']['can_view_attachments'] ?? false);
    if (!$canViewAttachments) {
        wbgl_api_compat_fail(403, 'Permission Denied');
    }

    $absolutePath = wbgl_attachment_resolve_absolute_path((string)($attachment['file_path'] ?? ''));
    if ($absolutePath === null) {
        wbgl_api_compat_fail(404, 'Attachment file not found');
    }

    $inline = (string)($_GET['inline'] ?? '1') !== '0';
    $disposition = $inline ? 'inline' : 'attachment';
    $downloadName = wbgl_attachment_safe_filename((string)($attachment['file_name'] ?? basename($absolutePath)));
    $mime = wbgl_attachment_detect_mime($absolutePath);
    $size = (int)(filesize($absolutePath) ?: 0);

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . $size);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=60');
    header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '', $downloadName) . '"');

    readfile($absolutePath);
    exit;
} catch (Throwable $e) {
    wbgl_api_compat_fail(500, $e->getMessage());
}
