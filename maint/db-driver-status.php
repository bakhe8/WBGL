<?php
declare(strict_types=1);

/**
 * Show effective DB driver/configuration summary.
 *
 * Usage:
 *   php maint/db-driver-status.php
 *   php maint/db-driver-status.php --json
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;

$asJson = in_array('--json', $argv ?? [], true);

$summary = Database::configurationSummary();
$driver = Database::currentDriver();

$connectivity = [
    'ok' => false,
    'error' => null,
];

try {
    $db = Database::connect();
    $stmt = $db->query('SELECT 1');
    $connectivity['ok'] = $stmt !== false;
} catch (Throwable $e) {
    $connectivity['ok'] = false;
    $connectivity['error'] = $e->getMessage();
}

$payload = [
    'generated_at' => date('c'),
    'driver' => $driver,
    'configuration' => $summary,
    'connectivity' => $connectivity,
];

if ($asJson) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($connectivity['ok'] ? 0 : 1);
}

echo "WBGL DB Driver Status\n";
echo str_repeat('-', 64) . "\n";
echo "Driver       : " . $driver . "\n";
echo "Host         : " . (string)($summary['host'] ?? '') . "\n";
echo "Port         : " . (string)($summary['port'] ?? '') . "\n";
echo "Database     : " . (string)($summary['database'] ?? '') . "\n";
echo "SSL Mode     : " . (string)($summary['sslmode'] ?? '') . "\n";

echo "Connectivity : " . ($connectivity['ok'] ? 'OK' : 'FAILED') . "\n";
if (!$connectivity['ok'] && is_string($connectivity['error']) && $connectivity['error'] !== '') {
    echo "Error        : " . $connectivity['error'] . "\n";
}

exit($connectivity['ok'] ? 0 : 1);
