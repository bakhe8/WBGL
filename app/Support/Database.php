<?php
declare(strict_types=1);

namespace App\Support;

use PDO;
use RuntimeException;
use Throwable;

class Database
{
    private static ?PDO $instance = null;
    /** @var array<string,mixed>|null */
    private static ?array $connectionMeta = null;

    public static function connect(): PDO
    {
        // Set timezone to match Saudi Arabia (GMT+3)
        date_default_timezone_set('Asia/Riyadh');
        
        if (self::$instance === null) {
            $config = self::resolveConfiguration();

            try {
                self::$instance = self::connectPgsql($config);
                self::$connectionMeta = $config;
            } catch (Throwable $e) {
                // If this fails, we can't do anything. Return 500 or die.
                // For API context, JSON response is better.
                if (php_sapi_name() === 'cli-server' || isset($_SERVER['HTTP_ACCEPT'])) {
                    $message = 'Database Connection Error: ' . $e->getMessage();
                    http_response_code(500);
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => false,
                        'message' => $message,
                        'error' => $message,
                    ]);
                    exit;
                }
                die('Database Connection Error');
            }
        }
        return self::$instance;
    }

    /**
     * Alias for connect() to match existing codebase usage
     */
    public static function connection(): PDO
    {
        return self::connect();
    }

    public static function reset(): void
    {
        self::$instance = null;
        self::$connectionMeta = null;
    }

    public static function currentDriver(): string
    {
        $summary = self::configurationSummary();
        return (string)($summary['driver'] ?? 'pgsql');
    }

    /**
     * @return array<string,mixed>
     */
    public static function configurationSummary(): array
    {
        $meta = self::$connectionMeta ?? self::resolveConfiguration();
        $summary = [
            'driver' => (string)($meta['driver'] ?? 'pgsql'),
        ];

        $summary['host'] = (string)($meta['host'] ?? '');
        $summary['port'] = (int)($meta['port'] ?? 5432);
        $summary['database'] = (string)($meta['database'] ?? '');
        $summary['sslmode'] = (string)($meta['sslmode'] ?? 'prefer');

        return $summary;
    }

    /**
     * @return array<string,mixed>
     */
    private static function resolveConfiguration(): array
    {
        $settings = null;
        try {
            if (class_exists(Settings::class)) {
                $settings = Settings::getInstance();
            }
        } catch (Throwable $e) {
            $settings = null;
        }

        $driverRaw = self::pickConfigValue('WBGL_DB_DRIVER', $settings, 'DB_DRIVER', 'pgsql');
        $driver = self::normalizeDriver((string)$driverRaw);
        if ($driver !== 'pgsql') {
            throw new RuntimeException(
                'Unsupported database driver "' . $driver . '". WBGL runtime is PostgreSQL-only.'
            );
        }

        return [
            'driver' => 'pgsql',
            'host' => (string)self::pickConfigValue('WBGL_DB_HOST', $settings, 'DB_HOST', '127.0.0.1'),
            'port' => (int)self::pickConfigValue('WBGL_DB_PORT', $settings, 'DB_PORT', 5432),
            'database' => (string)self::pickConfigValue('WBGL_DB_NAME', $settings, 'DB_NAME', 'wbgl'),
            'username' => (string)self::pickConfigValue('WBGL_DB_USER', $settings, 'DB_USER', ''),
            'password' => (string)self::pickConfigValue('WBGL_DB_PASS', $settings, 'DB_PASS', ''),
            'sslmode' => (string)self::pickConfigValue('WBGL_DB_SSLMODE', $settings, 'DB_SSLMODE', 'prefer'),
        ];
    }

    /**
     * @param array<string,mixed> $config
     */
    private static function connectPgsql(array $config): PDO
    {
        $host = (string)($config['host'] ?? '127.0.0.1');
        $port = (int)($config['port'] ?? 5432);
        $database = (string)($config['database'] ?? 'wbgl');
        $sslmode = (string)($config['sslmode'] ?? 'prefer');
        $username = (string)($config['username'] ?? '');
        $password = (string)($config['password'] ?? '');

        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
            $host,
            $port,
            $database,
            $sslmode
        );

        $pdo = new PDO($dsn, $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    }

    private static function normalizeDriver(string $driver): string
    {
        $normalized = strtolower(trim($driver));
        return $normalized === 'pgsql' ? 'pgsql' : $normalized;
    }

    /**
     * @param mixed $settings
     */
    private static function pickConfigValue(string $envKey, mixed $settings, string $settingsKey, mixed $fallback): mixed
    {
        // Project policy: settings.json is the primary runtime source.
        // Environment values are treated as a temporary override/fallback only.
        if ($settings instanceof Settings) {
            $value = $settings->get($settingsKey, null);
            if ($value !== null && trim((string)$value) !== '') {
                return $value;
            }
        }

        $envValue = getenv($envKey);
        if ($envValue !== false && trim((string)$envValue) !== '') {
            return $envValue;
        }

        return $fallback;
    }

}
