<?php
declare(strict_types=1);

/**
 * WBGL DB TLS Readiness Check (A06)
 *
 * Usage:
 *   php app/Scripts/db-tls-readiness.php
 */

require_once __DIR__ . '/../Support/autoload.php';

use App\Support\Settings;

/**
 * @return array{host:string,port:int,database:string,username:string,password:string,current_sslmode:string}
 */
function wbgl_db_tls_config(Settings $settings): array
{
    return [
        'host' => (string)$settings->get('DB_HOST', '127.0.0.1'),
        'port' => (int)$settings->get('DB_PORT', 5432),
        'database' => (string)$settings->get('DB_NAME', 'wbgl'),
        'username' => (string)$settings->get('DB_USER', ''),
        'password' => (string)$settings->get('DB_PASS', ''),
        'current_sslmode' => strtolower(trim((string)$settings->get('DB_SSLMODE', 'require'))) ?: 'require',
    ];
}

function wbgl_db_tls_try_connect(array $config, string $sslMode): array
{
    $dsn = sprintf(
        'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
        (string)$config['host'],
        (int)$config['port'],
        (string)$config['database'],
        $sslMode
    );

    try {
        $pdo = new PDO($dsn, (string)$config['username'], (string)$config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $ping = (string)($pdo->query('SELECT 1')->fetchColumn() ?: '');
        return [
            'ok' => $ping === '1',
            'error' => '',
        ];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'error' => $e->getMessage(),
        ];
    }
}

try {
    $settings = Settings::getInstance();
    $config = wbgl_db_tls_config($settings);
    $current = wbgl_db_tls_try_connect($config, (string)$config['current_sslmode']);
    $require = wbgl_db_tls_try_connect($config, 'require');

    $summary = [
        'ok' => (bool)$require['ok'],
        'timestamp' => date('c'),
        'host' => $config['host'],
        'database' => $config['database'],
        'current_sslmode' => $config['current_sslmode'],
        'probe_current' => $current,
        'probe_require' => $require,
        'recommended_action' => $require['ok']
            ? 'TLS ready: you can enforce DB_SSLMODE=require.'
            : 'TLS not ready: configure PostgreSQL SSL then retry.',
    ];

    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($require['ok'] ? 0 : 2);
} catch (Throwable $e) {
    fwrite(STDERR, '[WBGL_DB_TLS_READINESS_ERROR] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
