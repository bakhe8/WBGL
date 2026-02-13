<?php
declare(strict_types=1);

// Set timezone from Settings (dynamic)
date_default_timezone_set('Asia/Riyadh'); // Will be overridden below after Settings loads

spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/../';
    
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    
    if (file_exists($file)) {
        require $file;
    }
});

// After Settings class is loaded, update timezone dynamically
if (class_exists('App\\Support\\Settings')) {
    $settings = new App\Support\Settings();
    $timezone = $settings->get('TIMEZONE', 'Asia/Riyadh');
    date_default_timezone_set($timezone);
}

// Composer autoload (PhpSpreadsheet)
$composerAutoload = base_path('vendor/autoload.php');
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
}

function base_path(string $path = ''): string
{
    $base = dirname(__DIR__, 1);
    // Move up one more level to reach project root
    $base = dirname($base);
    return $path ? $base . '/' . ltrim($path, '/') : $base;
}

function storage_path(string $path = ''): string
{
    $base = base_path('storage');
    return $path ? $base . '/' . ltrim($path, '/') : $base;
}

/**
 * Simple logger helper
 * Usage: \App\Support\Logger::error('message', ['context' => 'data']);
 */
