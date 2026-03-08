<?php
declare(strict_types=1);

namespace App\Support;

final class AssetVersion
{
    public static function forPath(string $relativePath): string
    {
        $root = dirname(__DIR__, 2);
        $normalized = str_replace('\\', '/', trim($relativePath));
        $normalized = ltrim($normalized, '/');
        $assetPath = $root . '/' . $normalized;

        if (is_file($assetPath)) {
            $mtime = @filemtime($assetPath);
            if ($mtime !== false) {
                return (string)$mtime;
            }
        }

        $versionFile = $root . '/VERSION';
        if (is_file($versionFile)) {
            $version = trim((string)@file_get_contents($versionFile));
            if ($version !== '') {
                return $version;
            }
        }

        return '1';
    }
}
