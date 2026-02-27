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
    private static ?string $logFormat = null;

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

    private static function resolveLogFormat(): string
    {
        if (self::$logFormat !== null) {
            return self::$logFormat;
        }

        $format = 'json';
        try {
            $settings = new Settings();
            $configured = strtolower(trim((string)$settings->get('LOG_FORMAT', 'json')));
            if (in_array($configured, ['json', 'text'], true)) {
                $format = $configured;
            }
        } catch (\Throwable $e) {
            $format = 'json';
        }

        self::$logFormat = $format;
        return self::$logFormat;
    }

    private static function resolveRequestId(): ?string
    {
        $candidates = [
            $_SERVER['WBGL_REQUEST_ID'] ?? null,
            $_SERVER['HTTP_X_REQUEST_ID'] ?? null,
            $_SERVER['UNIQUE_ID'] ?? null,
        ];

        foreach ($candidates as $value) {
            if (!is_string($value)) {
                continue;
            }
            $trimmed = trim($value);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return null;
    }

    private static function normalizeContext(array $context): array
    {
        $normalized = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $normalized[(string)$key] = $value;
                continue;
            }
            if (is_array($value)) {
                $normalized[(string)$key] = $value;
                continue;
            }
            $normalized[(string)$key] = (string)$value;
        }
        return $normalized;
    }

    /**
     * @return array<string,mixed>
     */
    private static function structuredPayload(string $level, string $message, array $context = []): array
    {
        $payload = [
            'timestamp' => date('c'),
            'level' => $level,
            'message' => $message,
            'request_id' => self::resolveRequestId(),
            'method' => $_SERVER['REQUEST_METHOD'] ?? null,
            'path' => $_SERVER['REQUEST_URI'] ?? null,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'context' => self::normalizeContext($context),
        ];

        try {
            $user = AuthService::getCurrentUser();
            if ($user !== null) {
                $payload['user'] = [
                    'id' => $user->id,
                    'username' => $user->username,
                    'role_id' => $user->roleId,
                ];
            }
        } catch (\Throwable $e) {
            // Non-blocking in logger context.
        }

        return $payload;
    }

    private static function write(string $level, string $message, array $context = []): void
    {
        if (!self::shouldLog($level)) {
            return;
        }

        if (self::resolveLogFormat() === 'json') {
            $payload = self::structuredPayload($level, $message, $context);
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $logMessage = ($encoded !== false ? $encoded : '{"level":"ERROR","message":"logger_encoding_failed"}');
            $logMessage .= PHP_EOL;
            file_put_contents(self::getLogPath(), $logMessage, FILE_APPEND | LOCK_EX);
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? json_encode(self::normalizeContext($context), JSON_UNESCAPED_UNICODE) : '';
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
