<?php
declare(strict_types=1);

require_once __DIR__ . '/../Support/autoload.php';

use App\Support\Database;

function wbgl_e2e_fetch_role_id(PDO $db, string $slug): int
{
    $stmt = $db->prepare('SELECT id FROM roles WHERE slug = ? LIMIT 1');
    $stmt->execute([$slug]);
    $id = (int)$stmt->fetchColumn();
    if ($id <= 0) {
        throw new RuntimeException('Missing role slug: ' . $slug);
    }
    return $id;
}

function wbgl_e2e_upsert_user(PDO $db, array $user): void
{
    $stmt = $db->prepare(
        "INSERT INTO users (username, password_hash, full_name, email, role_id, preferred_language, preferred_theme, preferred_direction)
         VALUES (?, ?, ?, ?, ?, 'ar', 'system', 'auto')
         ON CONFLICT (username) DO UPDATE
         SET password_hash = EXCLUDED.password_hash,
             full_name = EXCLUDED.full_name,
             email = EXCLUDED.email,
             role_id = EXCLUDED.role_id,
             preferred_language = EXCLUDED.preferred_language,
             preferred_theme = EXCLUDED.preferred_theme,
             preferred_direction = EXCLUDED.preferred_direction"
    );

    $stmt->execute([
        $user['username'],
        $user['password_hash'],
        $user['full_name'],
        $user['email'],
        $user['role_id'],
    ]);
}

function wbgl_e2e_find_or_create_supplier(PDO $db, string $officialName): int
{
    $select = $db->prepare('SELECT id FROM suppliers WHERE official_name = ? ORDER BY id ASC LIMIT 1');
    $select->execute([$officialName]);
    $existing = (int)$select->fetchColumn();
    if ($existing > 0) {
        return $existing;
    }

    $normalized = mb_strtolower(trim($officialName), 'UTF-8');
    $insert = $db->prepare('INSERT INTO suppliers (official_name, normalized_name, english_name) VALUES (?, ?, ?)');
    $insert->execute([$officialName, $normalized, 'E2E Supplier']);
    return (int)$db->lastInsertId();
}

function wbgl_e2e_find_or_create_bank(PDO $db, string $arabicName): int
{
    $select = $db->prepare('SELECT id FROM banks WHERE arabic_name = ? ORDER BY id ASC LIMIT 1');
    $select->execute([$arabicName]);
    $existing = (int)$select->fetchColumn();
    if ($existing > 0) {
        return $existing;
    }

    $normalized = mb_strtolower(trim($arabicName), 'UTF-8');
    $insert = $db->prepare(
        "INSERT INTO banks (arabic_name, english_name, short_name, department, contact_email, normalized_name, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP::text, CURRENT_TIMESTAMP::text)"
    );
    $insert->execute([$arabicName, 'E2E Bank', 'E2E', 'E2E Ops', 'e2e-bank@local.test', $normalized]);
    return (int)$db->lastInsertId();
}

function wbgl_e2e_upsert_guarantee(PDO $db, array $payload): int
{
    $stmt = $db->prepare(
        "INSERT INTO guarantees (guarantee_number, raw_data, import_source, imported_by, normalized_supplier_name, is_test_data)
         VALUES (?, ?::json, ?, ?, ?, 0)
         ON CONFLICT (guarantee_number) DO UPDATE
         SET raw_data = EXCLUDED.raw_data,
             import_source = EXCLUDED.import_source,
             imported_by = EXCLUDED.imported_by,
             normalized_supplier_name = EXCLUDED.normalized_supplier_name,
             is_test_data = EXCLUDED.is_test_data
         RETURNING id"
    );

    $stmt->execute([
        $payload['guarantee_number'],
        json_encode($payload['raw_data'], JSON_UNESCAPED_UNICODE),
        $payload['import_source'],
        $payload['imported_by'],
        $payload['normalized_supplier_name'],
    ]);

    return (int)$stmt->fetchColumn();
}

function wbgl_e2e_upsert_decision(PDO $db, array $payload): void
{
    $stmt = $db->prepare(
        "INSERT INTO guarantee_decisions
            (guarantee_id, status, supplier_id, bank_id, decision_source, decided_by, decided_at, workflow_step, signatures_received, active_action, active_action_set_at, last_modified_by, last_modified_at)
         VALUES
            (?, ?, ?, ?, 'manual', ?, CURRENT_TIMESTAMP, ?, ?, ?, CURRENT_TIMESTAMP, ?, CURRENT_TIMESTAMP)
         ON CONFLICT (guarantee_id) DO UPDATE
         SET status = EXCLUDED.status,
             supplier_id = EXCLUDED.supplier_id,
             bank_id = EXCLUDED.bank_id,
             decision_source = EXCLUDED.decision_source,
             decided_by = EXCLUDED.decided_by,
             decided_at = EXCLUDED.decided_at,
             workflow_step = EXCLUDED.workflow_step,
             signatures_received = EXCLUDED.signatures_received,
             active_action = EXCLUDED.active_action,
             active_action_set_at = EXCLUDED.active_action_set_at,
             last_modified_by = EXCLUDED.last_modified_by,
             last_modified_at = EXCLUDED.last_modified_at,
             is_locked = FALSE,
             locked_reason = NULL"
    );

    $stmt->execute([
        $payload['guarantee_id'],
        $payload['status'],
        $payload['supplier_id'],
        $payload['bank_id'],
        $payload['decided_by'],
        $payload['workflow_step'],
        $payload['signatures_received'],
        $payload['active_action'],
        $payload['decided_by'],
    ]);
}

try {
    $db = Database::connect();
    $password = (string)(getenv('WBGL_E2E_PASSWORD') ?: 'E2E#WBGL2026!');
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    $dataEntryRoleId = wbgl_e2e_fetch_role_id($db, 'data_entry');
    $auditorRoleId = wbgl_e2e_fetch_role_id($db, 'data_auditor');

    wbgl_e2e_upsert_user($db, [
        'username' => 'e2e_data_entry',
        'password_hash' => $passwordHash,
        'full_name' => 'E2E Data Entry',
        'email' => 'e2e_data_entry@local.test',
        'role_id' => $dataEntryRoleId,
    ]);

    wbgl_e2e_upsert_user($db, [
        'username' => 'e2e_auditor',
        'password_hash' => $passwordHash,
        'full_name' => 'E2E Auditor',
        'email' => 'e2e_auditor@local.test',
        'role_id' => $auditorRoleId,
    ]);

    $supplierId = wbgl_e2e_find_or_create_supplier($db, 'شركة E2E للتشغيل');
    $bankId = wbgl_e2e_find_or_create_bank($db, 'بنك E2E');

    $batchIdentifier = 'e2e_ui_flow_batch';
    $batchStmt = $db->prepare(
        "INSERT INTO batch_metadata (import_source, batch_name, batch_notes, status)
         VALUES (?, ?, ?, 'active')
         ON CONFLICT (import_source) DO UPDATE
         SET batch_name = EXCLUDED.batch_name,
             batch_notes = EXCLUDED.batch_notes"
    );
    $batchStmt->execute([$batchIdentifier, 'E2E UI Flow Batch', 'Seeded for Playwright role-scope coverage']);

    $readyAuditGuaranteeId = wbgl_e2e_upsert_guarantee($db, [
        'guarantee_number' => 'E2E-ACTION-001',
        'raw_data' => [
            'supplier' => 'شركة E2E للتشغيل',
            'bank' => 'بنك E2E',
            'amount' => 10000,
            'expiry_date' => '2027-12-31',
            'contract_number' => 'E2E-CN-001',
            'type' => 'Initial',
        ],
        'import_source' => $batchIdentifier,
        'imported_by' => 'e2e_seed',
        'normalized_supplier_name' => mb_strtolower('شركة E2E للتشغيل', 'UTF-8'),
    ]);

    $readyNoActionGuaranteeId = wbgl_e2e_upsert_guarantee($db, [
        'guarantee_number' => 'E2E-READY-001',
        'raw_data' => [
            'supplier' => 'شركة E2E للتشغيل',
            'bank' => 'بنك E2E',
            'amount' => 22000,
            'expiry_date' => '2027-10-01',
            'contract_number' => 'E2E-CN-002',
            'type' => 'Initial',
        ],
        'import_source' => $batchIdentifier,
        'imported_by' => 'e2e_seed',
        'normalized_supplier_name' => mb_strtolower('شركة E2E للتشغيل', 'UTF-8'),
    ]);

    wbgl_e2e_upsert_decision($db, [
        'guarantee_id' => $readyAuditGuaranteeId,
        'status' => 'ready',
        'supplier_id' => $supplierId,
        'bank_id' => $bankId,
        'decided_by' => 'e2e_seed',
        'workflow_step' => 'draft',
        'signatures_received' => 0,
        'active_action' => 'extension',
    ]);

    wbgl_e2e_upsert_decision($db, [
        'guarantee_id' => $readyNoActionGuaranteeId,
        'status' => 'ready',
        'supplier_id' => $supplierId,
        'bank_id' => $bankId,
        'decided_by' => 'e2e_seed',
        'workflow_step' => 'draft',
        'signatures_received' => 0,
        'active_action' => null,
    ]);

    $occurrenceStmt = $db->prepare(
        "INSERT INTO guarantee_occurrences (guarantee_id, batch_identifier, batch_type, occurred_at, raw_hash)
         VALUES (?, ?, 'manual', CURRENT_TIMESTAMP, NULL)
         ON CONFLICT (guarantee_id, batch_identifier) DO NOTHING"
    );
    $occurrenceStmt->execute([$readyAuditGuaranteeId, $batchIdentifier]);
    $occurrenceStmt->execute([$readyNoActionGuaranteeId, $batchIdentifier]);

    echo "E2E fixtures seeded successfully." . PHP_EOL;
    echo "username=e2e_data_entry password={$password}" . PHP_EOL;
    echo "username=e2e_auditor password={$password}" . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'E2E fixture seed failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
