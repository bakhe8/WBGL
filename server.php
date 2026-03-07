<?php
/**
 * V3 Standalone Server Router
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $uri;
$normalizedUri = str_replace('\\', '/', (string)$uri);

// Sensitive file trees must never be served directly.
$blockedPrefixes = [
    '/storage/',
    '/public/uploads/',
    '/uploads/',
];
foreach ($blockedPrefixes as $blockedPrefix) {
    $blockedRoot = rtrim($blockedPrefix, '/');
    if ($normalizedUri === $blockedRoot || str_starts_with($normalizedUri, $blockedPrefix)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden';
        exit;
    }
}

// Missing PHP files should return 404 (do not silently fallback to index).
if ($uri !== '/' && str_ends_with($uri, '.php') && !file_exists($file)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not Found';
    exit;
}

// Serve static files directly
if ($uri !== '/' && file_exists($file) && !is_dir($file)) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    
    $mimes = [
        'css' => 'text/css',
        'js'  => 'application/javascript',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'map' => 'application/json'
    ];

    if (isset($mimes[$ext])) {
        header("Content-Type: " . $mimes[$ext]);
        readfile($file);
        exit;
    }
    
    return false; // Let PHP's built-in server handle others
}

// Default to index.php
require __DIR__ . '/index.php';
