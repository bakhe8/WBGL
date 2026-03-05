<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Support\Database;

/**
 * Cleanup leaked integration users and keep one canonical user per role context.
 *
 * Safe scope:
 * - Removes usernames matching integration_*.
 * - Preserves/creates one canonical data_entry user.
 * - Normalizes baseline display names for core seeded accounts.
 */

function scalar(\PDO $db, string $sql, array $params = []): mixed
{
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function uniqueValue(\PDO $db, string $table, string $column, string $base, ?int $excludeId = null): string
{
    $candidate = $base;
    $suffix = 1;

    while (true) {
        if ($excludeId !== null) {
            $exists = scalar($db, "SELECT 1 FROM {$table} WHERE {$column} = ? AND id <> ? LIMIT 1", [$candidate, $excludeId]);
        } else {
            $exists = scalar($db, "SELECT 1 FROM {$table} WHERE {$column} = ? LIMIT 1", [$candidate]);
        }

        if (!$exists) {
            return $candidate;
        }

        $candidate = $base . '_' . $suffix;
        $suffix++;
    }
}

$db = Database::connect();
$db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

$beforeCounts = $db->query(
    "SELECT r.slug, COUNT(u.id) AS users_count
     FROM roles r
     LEFT JOIN users u ON u.role_id = r.id
     GROUP BY r.slug
     ORDER BY r.slug"
)->fetchAll(\PDO::FETCH_ASSOC);

$integrationBefore = (int)scalar($db, "SELECT COUNT(*) FROM users WHERE username LIKE 'integration_%'");

$report = [
    'integration_users_before' => $integrationBefore,
    'before_counts' => $beforeCounts,
    'actions' => [],
];

try {
    $db->beginTransaction();

    $dataEntryRoleId = (int)scalar($db, "SELECT id FROM roles WHERE slug = 'data_entry' LIMIT 1");
    if ($dataEntryRoleId <= 0) {
        throw new \RuntimeException('Role data_entry not found');
    }

    $canonicalDataEntryId = (int)scalar(
        $db,
        "SELECT id
         FROM users
         WHERE role_id = ? AND username NOT LIKE 'integration_%'
         ORDER BY id ASC
         LIMIT 1",
        [$dataEntryRoleId]
    );

    if ($canonicalDataEntryId <= 0) {
        $candidateStmt = $db->prepare(
            "SELECT id
             FROM users
             WHERE role_id = ? AND username LIKE 'integration_operator_%'
             ORDER BY id ASC
             LIMIT 1"
        );
        $candidateStmt->execute([$dataEntryRoleId]);
        $candidateId = (int)($candidateStmt->fetchColumn() ?: 0);

        if ($candidateId > 0) {
            $newUsername = uniqueValue($db, 'users', 'username', 'data_entry', $candidateId);
            $newEmail = uniqueValue($db, 'users', 'email', 'data_entry@example.com', $candidateId);

            $update = $db->prepare(
                "UPDATE users
                 SET username = ?,
                     full_name = ?,
                     email = ?,
                     preferred_language = 'ar',
                     preferred_theme = 'system',
                     preferred_direction = 'auto'
                 WHERE id = ?"
            );
            $update->execute([$newUsername, 'مدخل بيانات النظام', $newEmail, $candidateId]);
            $canonicalDataEntryId = $candidateId;
            $report['actions'][] = 'Repurposed oldest integration operator as canonical data_entry user (id=' . $candidateId . ')';
        } else {
            $newUsername = uniqueValue($db, 'users', 'username', 'data_entry');
            $newEmail = uniqueValue($db, 'users', 'email', 'data_entry@example.com');
            $passwordHash = password_hash('DataEntry@WBGL2026', PASSWORD_DEFAULT);

            $insert = $db->prepare(
                "INSERT INTO users (
                    username, password_hash, full_name, email, role_id,
                    preferred_language, preferred_theme, preferred_direction
                 ) VALUES (
                    ?, ?, ?, ?, ?, 'ar', 'system', 'auto'
                 )"
            );
            $insert->execute([$newUsername, $passwordHash, 'مدخل بيانات النظام', $newEmail, $dataEntryRoleId]);
            $canonicalDataEntryId = (int)$db->lastInsertId();
            $report['actions'][] = 'Created canonical data_entry user (id=' . $canonicalDataEntryId . ') with temporary password DataEntry@WBGL2026';
        }
    }

    $deleteStmt = $db->prepare("DELETE FROM users WHERE username LIKE 'integration_%' AND id <> ?");
    $deleteStmt->execute([$canonicalDataEntryId]);
    $report['actions'][] = 'Deleted integration users: ' . $deleteStmt->rowCount();

    $normalizeStmt = $db->prepare("UPDATE users SET full_name = ? WHERE username = ?");
    $nameMap = [
        'admin' => 'مدير النظام (مطور)',
        'auditor' => 'مدقق بيانات',
        'analyst1' => 'محلل ضمانات',
        'superv' => 'مشرف ضمانات',
        'manager' => 'مدير معتمد',
        'sig_user' => 'المفوض بالتوقيع',
    ];
    foreach ($nameMap as $username => $fullName) {
        $normalizeStmt->execute([$fullName, $username]);
    }
    $report['actions'][] = 'Normalized core full_name labels for baseline users';

    $db->commit();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'cleanup-integration-users failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$afterCounts = $db->query(
    "SELECT r.slug, COUNT(u.id) AS users_count
     FROM roles r
     LEFT JOIN users u ON u.role_id = r.id
     GROUP BY r.slug
     ORDER BY r.slug"
)->fetchAll(\PDO::FETCH_ASSOC);

$integrationAfter = (int)scalar($db, "SELECT COUNT(*) FROM users WHERE username LIKE 'integration_%'");

$report['integration_users_after'] = $integrationAfter;
$report['after_counts'] = $afterCounts;
$report['users'] = $db->query(
    "SELECT u.id, u.username, u.full_name, r.slug AS role_slug
     FROM users u
     LEFT JOIN roles r ON r.id = u.role_id
     ORDER BY r.id, u.id"
)->fetchAll(\PDO::FETCH_ASSOC);

$logDir = dirname(__DIR__, 2) . '/storage/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}
$stamp = date('Ymd_His');
$reportPath = $logDir . '/users-cleanup-report-' . $stamp . '.json';
file_put_contents($reportPath, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

echo json_encode([
    'ok' => true,
    'report_file' => $reportPath,
    'integration_before' => $integrationBefore,
    'integration_after' => $integrationAfter,
    'after_counts' => $afterCounts,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
