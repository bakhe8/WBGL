<?php
declare(strict_types=1);

/**
 * WBGL Migration Rehearsal Runner (Safe, no CREATEDB needed per run)
 *
 * Usage:
 *   php app/Scripts/rehearse-migrations.php
 *   php app/Scripts/rehearse-migrations.php --db=wbgl_rehearsal
 *   php app/Scripts/rehearse-migrations.php --with-integration
 *
 * Prerequisite (one-time, by DBA/superuser):
 *   CREATE DATABASE wbgl_rehearsal OWNER wbgl_user;
 */

require_once __DIR__ . '/../Support/autoload.php';

use App\Support\Database;
/**
 * @return array{db:string,with_integration:bool}
 */
function wbglParseRehearsalArgs(array $argv): array
{
    $db = 'wbgl_rehearsal';
    $withIntegration = false;

    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--db=')) {
            $value = trim((string)substr($arg, 5));
            if ($value !== '') {
                $db = $value;
            }
            continue;
        }

        if ($arg === '--with-integration') {
            $withIntegration = true;
        }
    }

    return [
        'db' => $db,
        'with_integration' => $withIntegration,
    ];
}

function wbglReadJsonFile(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException('Could not read file: ' . $path);
    }

    // Strip UTF-8 BOM if present (prevents json_decode failure).
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid JSON file: ' . $path);
    }

    return $decoded;
}

function wbglWriteJsonFile(string $path, array $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        throw new RuntimeException('Failed to encode JSON for: ' . $path);
    }
    if (file_put_contents($path, $json) === false) {
        throw new RuntimeException('Could not write file: ' . $path);
    }
}

function wbglRunCommand(string $command): void
{
    echo '> ' . $command . PHP_EOL;
    passthru($command, $exitCode);
    if ($exitCode !== 0) {
        throw new RuntimeException('Command failed with exit code ' . $exitCode . ': ' . $command);
    }
}

function wbglEnsureDatabaseExists(PDO $db, string $dbName): void
{
    $stmt = $db->prepare('SELECT 1 FROM pg_database WHERE datname = ? LIMIT 1');
    $stmt->execute([$dbName]);
    $exists = (bool)$stmt->fetchColumn();

    if ($exists) {
        return;
    }

    throw new RuntimeException(
        "Rehearsal database '{$dbName}' does not exist.\n"
        . "Run once as PostgreSQL superuser:\n"
        . "  CREATE DATABASE {$dbName} OWNER wbgl_user;\n"
        . "  GRANT ALL PRIVILEGES ON DATABASE {$dbName} TO wbgl_user;"
    );
}

function wbglResetPublicSchema(PDO $db): void
{
    $db->exec('DROP SCHEMA IF EXISTS public CASCADE');
    $db->exec('CREATE SCHEMA public');
}

function wbglEnsureBootstrapUser(PDO $db): void
{
    $usersCount = (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($usersCount > 0) {
        return;
    }

    $roleStmt = $db->prepare("SELECT id FROM roles WHERE slug = 'developer' LIMIT 1");
    $roleStmt->execute();
    $roleId = (int)$roleStmt->fetchColumn();
    if ($roleId <= 0) {
        throw new RuntimeException("Developer role is missing in rehearsal DB after migrations.");
    }

    $insert = $db->prepare(
        'INSERT INTO users (username, password_hash, full_name, email, role_id)
         VALUES (?, ?, ?, ?, ?)'
    );
    $insert->execute([
        'bootstrap_admin',
        password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
        'Bootstrap Admin',
        null,
        $roleId,
    ]);
}

function wbglSeedIntegrationBaseline(PDO $db): void
{
    $supplierId = (int)($db->query('SELECT id FROM suppliers ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);
    if ($supplierId <= 0) {
        $insertSupplier = $db->prepare(
            'INSERT INTO suppliers (official_name, display_name, normalized_name, is_confirmed, created_at, english_name)
             VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, ?)'
        );
        $insertSupplier->execute([
            'مورد تجريبي للتكامل',
            'Integration Supplier',
            'integration supplier',
            1,
            'Integration Supplier',
        ]);
        $supplierId = (int)$db->lastInsertId();
    }

    $bankId = (int)($db->query('SELECT id FROM banks ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);
    if ($bankId <= 0) {
        $insertBank = $db->prepare(
            'INSERT INTO banks (arabic_name, english_name, short_name, created_at, updated_at, normalized_name)
             VALUES (?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?)'
        );
        $insertBank->execute([
            'بنك تجريبي',
            'Integration Bank',
            'INT-BANK',
            'integration bank',
        ]);
        $bankId = (int)$db->lastInsertId();
    }

    $bankAltCountStmt = $db->prepare('SELECT COUNT(*) FROM bank_alternative_names WHERE bank_id = ?');
    $bankAltCountStmt->execute([$bankId]);
    $bankAltCount = (int)$bankAltCountStmt->fetchColumn();
    if ($bankAltCount <= 0) {
        $insertBankAlt = $db->prepare(
            'INSERT INTO bank_alternative_names (bank_id, alternative_name, normalized_name, created_at)
             VALUES (?, ?, ?, CURRENT_TIMESTAMP)'
        );
        $insertBankAlt->execute([
            $bankId,
            'Integration Bank Alt',
            'integration bank alt',
        ]);
    }

    $guaranteeId = (int)($db->query('SELECT id FROM guarantees ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);
    if ($guaranteeId <= 0) {
        $rawData = json_encode([
            'supplier' => 'Integration Supplier',
            'bank' => 'Integration Bank Alt',
            'amount' => 10000,
            'contract_number' => 'INT-SEED-001',
            'expiry_date' => date('Y-m-d', strtotime('+120 days')),
            'issue_date' => date('Y-m-d', strtotime('-30 days')),
            'type' => 'Initial',
            'related_to' => 'contract',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $insertGuarantee = $db->prepare(
            'INSERT INTO guarantees
                (guarantee_number, raw_data, import_source, imported_at, imported_by, normalized_supplier_name, is_test_data)
             VALUES (?, ?, ?, CURRENT_TIMESTAMP, ?, ?, ?)'
        );
        $insertGuarantee->execute([
            'INT-SEED-' . bin2hex(random_bytes(5)),
            $rawData,
            'integration_seed',
            'rehearsal-script',
            'integration supplier',
            1,
        ]);
        $guaranteeId = (int)$db->lastInsertId();
    }

    $decisionCountStmt = $db->prepare('SELECT COUNT(*) FROM guarantee_decisions WHERE guarantee_id = ?');
    $decisionCountStmt->execute([$guaranteeId]);
    $decisionCount = (int)$decisionCountStmt->fetchColumn();
    if ($decisionCount <= 0) {
        $insertDecision = $db->prepare(
            "INSERT INTO guarantee_decisions
                (guarantee_id, status, is_locked, supplier_id, bank_id, decision_source, decided_by, created_at, updated_at, workflow_step, signatures_received)
             VALUES (?, 'ready', FALSE, ?, ?, 'manual', 'rehearsal-script', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 'approved', 2)"
        );
        $insertDecision->execute([$guaranteeId, $supplierId, $bankId]);
    }

    $historyCountStmt = $db->prepare('SELECT COUNT(*) FROM guarantee_history WHERE guarantee_id = ?');
    $historyCountStmt->execute([$guaranteeId]);
    $historyCount = (int)$historyCountStmt->fetchColumn();
    if ($historyCount <= 0) {
        $insertHistory = $db->prepare(
            "INSERT INTO guarantee_history
                (guarantee_id, event_type, event_subtype, snapshot_data, event_details, created_at, created_by)
             VALUES (?, 'manual_override', 'reopened', '{}', '{}', CURRENT_TIMESTAMP, 'rehearsal-script')"
        );
        $insertHistory->execute([$guaranteeId]);
    }
}

$options = wbglParseRehearsalArgs(array_slice($argv ?? [], 1));
$targetDb = (string)$options['db'];
$withIntegration = (bool)$options['with_integration'];

$projectRoot = dirname(__DIR__, 2);
$settingsLocalPath = $projectRoot . '/storage/settings.local.json';
$hadSettingsLocal = is_file($settingsLocalPath);
$settingsLocalBackup = $hadSettingsLocal ? (string)file_get_contents($settingsLocalPath) : null;

chdir($projectRoot);

try {
    // Validate target DB existence from current operational connection.
    $currentDb = Database::connect();
    wbglEnsureDatabaseExists($currentDb, $targetDb);

    // Switch runtime DB_NAME to rehearsal DB via settings.local.json.
    $settingsLocal = wbglReadJsonFile($settingsLocalPath);
    $settingsLocal['DB_NAME'] = $targetDb;
    wbglWriteJsonFile($settingsLocalPath, $settingsLocal);

    Database::reset();
    $rehearsalDb = Database::connect();
    wbglResetPublicSchema($rehearsalDb);

    $php = escapeshellarg((string)PHP_BINARY);
    $migrateScript = escapeshellarg($projectRoot . '/app/Scripts/migrate.php');
    $statusScript = escapeshellarg($projectRoot . '/app/Scripts/migration-status.php');
    $integrationCommand = $php . ' ' . escapeshellarg($projectRoot . '/vendor/bin/phpunit')
        . ' ' . escapeshellarg($projectRoot . '/tests/Integration/EnterpriseApiFlowsTest.php')
        . ' --log-junit ' . escapeshellarg($projectRoot . '/storage/logs/phpunit-enterprise-rehearsal.xml');

    wbglRunCommand($php . ' ' . $migrateScript);
    wbglRunCommand($php . ' ' . $statusScript);

    if ($withIntegration) {
        Database::reset();
        $rehearsalDb = Database::connect();
        wbglEnsureBootstrapUser($rehearsalDb);
        wbglSeedIntegrationBaseline($rehearsalDb);
        wbglRunCommand($integrationCommand);
    }

    echo 'Rehearsal completed successfully on DB: ' . $targetDb . PHP_EOL;
    echo 'settings.local.json restored automatically at script exit.' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Rehearsal failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    Database::reset();
    if ($hadSettingsLocal) {
        if ($settingsLocalBackup === null) {
            $settingsLocalBackup = '';
        }
        file_put_contents($settingsLocalPath, $settingsLocalBackup);
    } elseif (is_file($settingsLocalPath)) {
        @unlink($settingsLocalPath);
    }
}
