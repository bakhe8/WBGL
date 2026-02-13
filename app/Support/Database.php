<?php
declare(strict_types=1);

namespace App\Support;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $instance = null;

    public static function connect(): PDO
    {
        // Set timezone to match Saudi Arabia (GMT+3)
        date_default_timezone_set('Asia/Riyadh');
        
        if (self::$instance === null) {
            $dbPath = __DIR__ . '/../../storage/database/app.sqlite';
            if (!file_exists($dbPath)) {
                // Try create directory if not exists
                $dir = dirname($dbPath);
                if (!is_dir($dir)) {
                    mkdir($dir, 0777, true);
                }
                // Determine absolute path resolving differences
                $dbPath = realpath(__DIR__ . '/../../') . '/storage/database/app.sqlite';
            }

            try {
                self::$instance = new PDO('sqlite:' . $dbPath);
                self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$instance->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                self::$instance->exec('PRAGMA foreign_keys = ON;');
            } catch (PDOException $e) {
                // If this fails, we can't do anything. Return 500 or die.
                // For API context, JSON response is better.
                if (php_sapi_name() === 'cli-server' || isset($_SERVER['HTTP_ACCEPT'])) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'error' => 'Database Connection Error: ' . $e->getMessage()]);
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
}
