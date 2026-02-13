<?php
declare(strict_types=1);

namespace App\Support;

class Logger
{
    private const LEVEL_DEBUG = 'DEBUG';
    private const LEVEL_INFO = 'INFO';
    private const LEVEL_WARNING = 'WARNING';
    private const LEVEL_ERROR = 'ERROR';

    private static ?bool $productionMode = null;

    private static function getLogPath(): string
    {
        $logDir = storage_path('logs');
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        return $logDir . '/app.log';
    }

    private static function isProductionMode(): bool
    {
        if (self::$productionMode !== null) {
            return self::$productionMode;
        }

        try {
            $settings = new Settings();
            self::$productionMode = (bool) $settings->get('PRODUCTION_MODE', false);
        } catch (\Throwable $e) {
            self::$productionMode = false;
        }

        return self::$productionMode;
    }

    private static function shouldLog(string $level): bool
    {
        if ($level !== self::LEVEL_DEBUG) {
            return true;
        }

        return !self::isProductionMode();
    }

    private static function write(string $level, string $message, array $context = []): void
    {
        if (!self::shouldLog($level)) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $logMessage = "[$timestamp] {$level}: $message";
        if ($contextStr) {
            $logMessage .= " | Context: $contextStr";
        }
        $logMessage .= PHP_EOL;

        file_put_contents(self::getLogPath(), $logMessage, FILE_APPEND | LOCK_EX);
    }

    public static function debug(string $message, array $context = []): void
    {
        self::write(self::LEVEL_DEBUG, $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write(self::LEVEL_INFO, $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write(self::LEVEL_WARNING, $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write(self::LEVEL_ERROR, $message, $context);
    }
}
