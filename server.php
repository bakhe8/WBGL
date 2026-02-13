<?php
/**
 * V3 Standalone Server Router
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $uri;

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
