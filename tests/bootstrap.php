<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Support/autoload.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Tests\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
    $fullPath = __DIR__ . DIRECTORY_SEPARATOR . $relativePath;
    if (is_file($fullPath)) {
        require_once $fullPath;
    }
});
