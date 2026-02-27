<?php
declare(strict_types=1);

/**
 * WBGL execution loop runner.
 *
 * Purpose:
 * - Keep roadmap execution tied to evidence corpus in Docs/
 * - Produce machine + human status snapshots
 * - Surface the next execution batch automatically (P0-first)
 *
 * Usage:
 *   php maint/run-execution-loop.php
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;

function wbglLoopNormalize(string $path): string
{
    return str_replace('\\', '/', $path);
}

function wbglLoopRelativePath(string $base, string $path): string
{
    $base = rtrim(wbglLoopNormalize(realpath($base) ?: $base), '/');
    $path = wbglLoopNormalize(realpath($path) ?: $path);
    if (str_starts_with($path, $base . '/')) {
        return substr($path, strlen($base) + 1);
    }
    return $path;
}

function wbglLoopReadJson(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function wbglLoopFindPhpFiles(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $out = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if (!$file->isFile()) {
            continue;
        }
        if (strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        $out[] = $file->getPathname();
    }
    sort($out);
    return $out;
}

function wbglLoopReadFile(string $path): string
{
    if (!is_file($path)) {
        return '';
    }
    $raw = file_get_contents($path);
    return $raw === false ? '' : (string)$raw;
}

function wbglLoopDetectDbDriver(): string
{
    try {
        return Database::currentDriver();
    } catch (Throwable $e) {
        return 'pgsql';
    }
}

function wbglLoopTableExists(PDO $db, string $driver, string $table): bool
{
    try {
        $stmt = $db->prepare(
            "SELECT 1
             FROM information_schema.tables
             WHERE table_schema = 'public' AND table_name = :table
             LIMIT 1"
        );
        $stmt->execute(['table' => $table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @return string[]
 */
function wbglLoopTableColumns(PDO $db, string $driver, string $table): array
{
    try {
        if (!wbglLoopTableExists($db, $driver, $table)) {
            return [];
        }

        $stmt = $db->prepare(
            "SELECT column_name
             FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = :table"
        );
        $stmt->execute(['table' => $table]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $columns = [];
        foreach ($rows as $row) {
            if (!empty($row['column_name'])) {
                $columns[] = (string)$row['column_name'];
            }
        }
        return $columns;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @return string[]
 */
function wbglLoopExtractI18nKeys(string $content): array
{
    $matches = [];
    preg_match_all('/data-i18n(?:-placeholder|-title|-content)?="([^"]+)"/', $content, $matches);
    $keys = [];
    foreach (($matches[1] ?? []) as $key) {
        $trimmed = trim((string)$key);
        if ($trimmed !== '') {
            $keys[] = $trimmed;
        }
    }
    return array_values(array_unique($keys));
}

function wbglLoopCountPattern(string $content, string $pattern): int
{
    $count = 0;
    preg_match_all($pattern, $content, $ignored, PREG_SET_ORDER);
    $count = count($ignored);
    return $count;
}

function wbglLoopBuildMarkdown(array $status): string
{
    $lines = [];
    $lines[] = '# WBGL Execution Loop Status';
    $lines[] = '';
    $lines[] = 'Updated: ' . $status['run_at'];
    $lines[] = '';
    $lines[] = '## Loop Policy';
    $lines[] = '';
    $lines[] = '- Priority order: `P0 -> P1 -> P2 -> P3`';
    $lines[] = '- Delivery mode: small sequential batches, no hard deadline';
    $lines[] = '- Scope policy: WBGL-first, BG-as-reference';
    $lines[] = '';
    $lines[] = '## Corpus Coverage';
    $lines[] = '';
    $lines[] = '- Required docs found: ' . $status['corpus']['present'] . '/' . $status['corpus']['required_total'];
    $lines[] = '- Required docs missing: ' . count($status['corpus']['missing_files']);
    $lines[] = '';
    if (!empty($status['corpus']['missing_files'])) {
        $lines[] = '### Missing Required Docs';
        $lines[] = '';
        foreach ($status['corpus']['missing_files'] as $path) {
            $lines[] = '- `' . $path . '`';
        }
        $lines[] = '';
    }

    $lines[] = '## Gap Intake (WBGL Missing from BG)';
    $lines[] = '';
    $lines[] = '- Total gaps: ' . $status['gaps']['total'];
    $lines[] = '- High: ' . $status['gaps']['by_priority']['High'];
    $lines[] = '- Medium: ' . $status['gaps']['by_priority']['Medium'];
    $lines[] = '- Low: ' . $status['gaps']['by_priority']['Low'];
    $lines[] = '';
    $lines[] = '### Phase Mapping';
    $lines[] = '';
    foreach ($status['gaps']['phase_map'] as $phase => $ids) {
        $lines[] = '- ' . $phase . ': ' . (empty($ids) ? '-' : implode(', ', $ids));
    }
    $lines[] = '';

    $lines[] = '## API Guard Coverage';
    $lines[] = '';
    $lines[] = '- Guarded endpoints: ' . $status['api_guard']['guarded'] . '/' . $status['api_guard']['total'];
    $lines[] = '- Unguarded endpoints: ' . $status['api_guard']['unguarded'];
    $lines[] = '- Sensitive unguarded endpoints: ' . $status['api_guard']['sensitive_unguarded'];
    $lines[] = '';

    $lines[] = '## Login Rate Limiting';
    $lines[] = '';
    $lines[] = '- API hook present: ' . ($status['login_rate_limit']['api_hook_present'] ? 'yes' : 'no');
    $lines[] = '- Migration file present: ' . ($status['login_rate_limit']['migration_file_present'] ? 'yes' : 'no');
    $lines[] = '- DB table present: ' . ($status['login_rate_limit']['table_present'] ? 'yes' : 'no');
    $lines[] = '';

    $lines[] = '## Security Baseline (Wave-1)';
    $lines[] = '';
    $lines[] = '- Session security service present: ' . ($status['security_baseline']['session_security_service_present'] ? 'yes' : 'no');
    $lines[] = '- Security headers service present: ' . ($status['security_baseline']['security_headers_service_present'] ? 'yes' : 'no');
    $lines[] = '- CSRF guard service present: ' . ($status['security_baseline']['csrf_guard_service_present'] ? 'yes' : 'no');
    $lines[] = '- Autoload session hardening wired: ' . ($status['security_baseline']['autoload_session_hardening_wired'] ? 'yes' : 'no');
    $lines[] = '- Autoload headers wired: ' . ($status['security_baseline']['autoload_security_headers_wired'] ? 'yes' : 'no');
    $lines[] = '- Autoload non-API CSRF guard wired: ' . ($status['security_baseline']['autoload_non_api_csrf_guard_wired'] ? 'yes' : 'no');
    $lines[] = '- API bootstrap CSRF guard wired: ' . ($status['security_baseline']['api_bootstrap_csrf_guard_wired'] ? 'yes' : 'no');
    $lines[] = '- Login endpoint CSRF guard wired: ' . ($status['security_baseline']['login_endpoint_csrf_guard_wired'] ? 'yes' : 'no');
    $lines[] = '- Frontend security runtime present: ' . ($status['security_baseline']['frontend_security_runtime_present'] ? 'yes' : 'no');
    $lines[] = '- Frontend security runtime wired on key views: ' . ($status['security_baseline']['frontend_security_runtime_wired'] ? 'yes' : 'no');
    $lines[] = '- Logout hard-destroy wired: ' . ($status['security_baseline']['logout_hard_destroy_wired'] ? 'yes' : 'no');
    $lines[] = '- Rate limit UA fingerprint wired: ' . ($status['security_baseline']['rate_limit_user_agent_fingerprint_wired'] ? 'yes' : 'no');
    $lines[] = '';

    $lines[] = '## Undo Governance (Dual Approval Foundation)';
    $lines[] = '';
    $lines[] = '- API endpoint present: ' . ($status['undo_governance']['api_endpoint_present'] ? 'yes' : 'no');
    $lines[] = '- Service present: ' . ($status['undo_governance']['service_present'] ? 'yes' : 'no');
    $lines[] = '- Migration file present: ' . ($status['undo_governance']['migration_file_present'] ? 'yes' : 'no');
    $lines[] = '- DB table present: ' . ($status['undo_governance']['table_present'] ? 'yes' : 'no');
    $lines[] = '- Reopen endpoint integrated: ' . ($status['undo_governance']['reopen_integrated'] ? 'yes' : 'no');
    $lines[] = '- Undo workflow always enforced: ' . ($status['undo_governance']['always_enforced'] ? 'yes' : 'no');
    $lines[] = '';

    $lines[] = '## Role-Scoped Visibility (A7)';
    $lines[] = '';
    $lines[] = '- Service present: ' . ($status['role_visibility']['service_present'] ? 'yes' : 'no');
    $lines[] = '- Navigation integration: ' . ($status['role_visibility']['navigation_integrated'] ? 'yes' : 'no');
    $lines[] = '- Stats integration: ' . ($status['role_visibility']['stats_integrated'] ? 'yes' : 'no');
    $lines[] = '- Endpoint enforcement: ' . ($status['role_visibility']['endpoint_enforcement'] ? 'yes' : 'no');
    $lines[] = '- Visibility always enforced: ' . ($status['role_visibility']['always_enforced'] ? 'yes' : 'no');
    $lines[] = '';

    $lines[] = '## Notifications Inbox (A5)';
    $lines[] = '';
    $lines[] = '- API endpoint present: ' . ($status['notifications']['api_endpoint_present'] ? 'yes' : 'no');
    $lines[] = '- Service present: ' . ($status['notifications']['service_present'] ? 'yes' : 'no');
    $lines[] = '- Migration file present: ' . ($status['notifications']['migration_file_present'] ? 'yes' : 'no');
    $lines[] = '- DB table present: ' . ($status['notifications']['table_present'] ? 'yes' : 'no');
    $lines[] = '';

    $lines[] = '## Scheduler / Expiry Job (A4)';
    $lines[] = '';
    $lines[] = '- Scheduler runner present: ' . ($status['scheduler']['runner_present'] ? 'yes' : 'no');
    $lines[] = '- Expiry job script present: ' . ($status['scheduler']['expiry_job_present'] ? 'yes' : 'no');
    $lines[] = '- Scheduler wired to expiry job: ' . ($status['scheduler']['runner_wires_expiry_job'] ? 'yes' : 'no');
    $lines[] = '- Runtime service present: ' . ($status['scheduler']['runtime_service_present'] ? 'yes' : 'no');
    $lines[] = '- Run ledger migration present: ' . ($status['scheduler']['run_ledger_migration_present'] ? 'yes' : 'no');
    $lines[] = '- Run ledger table present: ' . ($status['scheduler']['run_ledger_table_present'] ? 'yes' : 'no');
    $lines[] = '- Retry support wired: ' . ($status['scheduler']['retry_support_wired'] ? 'yes' : 'no');
    $lines[] = '- Status command present: ' . ($status['scheduler']['status_command_present'] ? 'yes' : 'no');
    $lines[] = '- Dead-letter service present: ' . ($status['scheduler']['dead_letter_service_present'] ? 'yes' : 'no');
    $lines[] = '- Dead-letter API present: ' . ($status['scheduler']['dead_letter_api_present'] ? 'yes' : 'no');
    $lines[] = '- Dead-letter command present: ' . ($status['scheduler']['dead_letter_command_present'] ? 'yes' : 'no');
    $lines[] = '- Dead-letter migration present: ' . ($status['scheduler']['dead_letter_migration_present'] ? 'yes' : 'no');
    $lines[] = '- Dead-letter table present: ' . ($status['scheduler']['dead_letter_table_present'] ? 'yes' : 'no');
    $lines[] = '- Runtime failure integration wired: ' . ($status['scheduler']['runtime_dead_letter_integration'] ? 'yes' : 'no');
    $lines[] = '';

    $lines[] = '## Observability (Wave-3 Seed)';
    $lines[] = '';
    $lines[] = '- Metrics API present: ' . ($status['observability']['metrics_api_present'] ? 'yes' : 'no');
    $lines[] = '- Metrics service present: ' . ($status['observability']['metrics_service_present'] ? 'yes' : 'no');
    $lines[] = '- Metrics API permission-guarded: ' . ($status['observability']['metrics_api_permission_guarded'] ? 'yes' : 'no');
    $lines[] = '- Metrics API mapped in policy matrix: ' . ($status['observability']['metrics_api_policy_mapped'] ? 'yes' : 'no');
    $lines[] = '- API request-id header wiring: ' . ($status['observability']['api_request_id_wired'] ? 'yes' : 'no');
    $lines[] = '';

    $lines[] = '## DB Cutover (Wave-4 Baseline)';
    $lines[] = '';
    $lines[] = '- Active driver: `' . (string)($status['db_cutover']['active_driver'] ?? 'pgsql') . '`';
    $lines[] = '- Driver status command present: ' . ($status['db_cutover']['driver_status_command_present'] ? 'yes' : 'no');
    $lines[] = '- Cutover check command present: ' . ($status['db_cutover']['cutover_check_command_present'] ? 'yes' : 'no');
    $lines[] = '- Backup command present: ' . ($status['db_cutover']['backup_command_present'] ? 'yes' : 'no');
    $lines[] = '- Cutover runbook present: ' . ($status['db_cutover']['cutover_runbook_present'] ? 'yes' : 'no');
    $lines[] = '- Backup/restore runbook present: ' . ($status['db_cutover']['backup_restore_runbook_present'] ? 'yes' : 'no');
    $lines[] = '- Backup directory present: ' . ($status['db_cutover']['backup_directory_present'] ? 'yes' : 'no');
    $lines[] = '- Backup artifacts count: ' . (int)($status['db_cutover']['backup_artifacts_count'] ?? 0);
    $lines[] = '- Schema migrations table present: ' . ($status['db_cutover']['schema_migrations_table_present'] ? 'yes' : 'no');
    $lines[] = '- Migration tooling PG-ready: ' . ($status['db_cutover']['migration_tooling_driver_aware'] ? 'yes' : 'no');
    $lines[] = '- Portability check command present: ' . ($status['db_cutover']['portability_check_command_present'] ? 'yes' : 'no');
    $lines[] = '- Fingerprint command present: ' . ($status['db_cutover']['fingerprint_command_present'] ? 'yes' : 'no');
    $lines[] = '- PG activation rehearsal command present: ' . ($status['db_cutover']['pg_activation_rehearsal_command_present'] ? 'yes' : 'no');
    $lines[] = '- PG activation runbook present: ' . ($status['db_cutover']['pg_activation_runbook_present'] ? 'yes' : 'no');
    $lines[] = '- Portability high blockers: ' . (int)($status['db_cutover']['portability_high_blockers'] ?? 0);
    $lines[] = '- Latest fingerprint present: ' . ($status['db_cutover']['fingerprint_latest_present'] ? 'yes' : 'no');
    $lines[] = '- Latest PG rehearsal report present: ' . ($status['db_cutover']['pg_activation_rehearsal_latest_present'] ? 'yes' : 'no');
    $lines[] = '- Latest PG rehearsal ready: ' . ($status['db_cutover']['pg_activation_rehearsal_ready'] ? 'yes' : 'no');
    $lines[] = '- Cutover baseline ready: ' . ($status['db_cutover']['ready'] ? 'yes' : 'no');
    $lines[] = '- PG production-ready: ' . ($status['db_cutover']['production_ready'] ? 'yes' : 'no');
    $lines[] = '';

    $lines[] = '## CI/CD Enterprise Workflows';
    $lines[] = '';
    $lines[] = '- Change Gate workflow present: ' . ($status['ci_cd']['change_gate_workflow_present'] ? 'yes' : 'no');
    $lines[] = '- CI workflow present: ' . ($status['ci_cd']['ci_workflow_present'] ? 'yes' : 'no');
    $lines[] = '- Security workflow present: ' . ($status['ci_cd']['security_workflow_present'] ? 'yes' : 'no');
    $lines[] = '- Release readiness workflow present: ' . ($status['ci_cd']['release_workflow_present'] ? 'yes' : 'no');
    $lines[] = '- Enterprise workflows ready: ' . ($status['ci_cd']['enterprise_workflows_ready'] ? 'yes' : 'no');
    $lines[] = '';

    $lines[] = '## SoD / Compliance (Wave-5 Baseline)';
    $lines[] = '';
    $lines[] = '- Governance policy doc present: ' . ($status['sod_compliance']['governance_policy_doc_present'] ? 'yes' : 'no');
    $lines[] = '- SoD matrix doc present: ' . ($status['sod_compliance']['sod_matrix_doc_present'] ? 'yes' : 'no');
    $lines[] = '- Break-glass runbook present: ' . ($status['sod_compliance']['break_glass_runbook_present'] ? 'yes' : 'no');
    $lines[] = '- Self-approval guard enforced: ' . ($status['sod_compliance']['self_approval_guard_enforced'] ? 'yes' : 'no');
    $lines[] = '- Break-glass permission gate enforced: ' . ($status['sod_compliance']['break_glass_permission_gate_enforced'] ? 'yes' : 'no');
    $lines[] = '- Break-glass ticket policy present: ' . ($status['sod_compliance']['break_glass_ticket_policy_present'] ? 'yes' : 'no');
    $lines[] = '- Audit tables present: ' . ($status['sod_compliance']['audit_tables_present'] ? 'yes' : 'no');
    $lines[] = '- Compliance baseline ready: ' . ($status['sod_compliance']['ready'] ? 'yes' : 'no');
    $lines[] = '';

    $lines[] = '## Stage Gates (A-E)';
    $lines[] = '';
    $lines[] = '- Gate-A passed: ' . ($status['stage_gates']['gate_a_passed'] ? 'yes' : 'no');
    $lines[] = '- Gate-B passed: ' . ($status['stage_gates']['gate_b_passed'] ? 'yes' : 'no');
    $lines[] = '- Gate-C passed: ' . ($status['stage_gates']['gate_c_passed'] ? 'yes' : 'no');
    $lines[] = '- Gate-D rehearsal passed: ' . ($status['stage_gates']['gate_d_rehearsal_passed'] ? 'yes' : 'no');
    $lines[] = '- Gate-D PG rehearsal report passed: ' . ($status['stage_gates']['gate_d_pg_rehearsal_report_passed'] ? 'yes' : 'no');
    $lines[] = '- Gate-D PG activation passed: ' . ($status['stage_gates']['gate_d_pg_activation_passed'] ? 'yes' : 'no');
    $lines[] = '- Gate-E passed: ' . ($status['stage_gates']['gate_e_passed'] ? 'yes' : 'no');
    $lines[] = '';

    $lines[] = '## History V2 (Hybrid Ledger)';
    $lines[] = '';
    $lines[] = '- Migration file present: ' . ($status['history_hybrid']['migration_file_present'] ? 'yes' : 'no');
    $lines[] = '- DB columns present: ' . ($status['history_hybrid']['columns_present'] ? 'yes' : 'no');
    $lines[] = '- Service present: ' . ($status['history_hybrid']['service_present'] ? 'yes' : 'no');
    $lines[] = '- Policy locked (always-on): ' . ($status['history_hybrid']['policy_locked'] ? 'yes' : 'no');
    $lines[] = '- Recorder integration present: ' . ($status['history_hybrid']['recorder_integration_present'] ? 'yes' : 'no');
    $lines[] = '- Snapshot reader integration present: ' . ($status['history_hybrid']['snapshot_reader_integration_present'] ? 'yes' : 'no');
    $lines[] = '- Event catalog extraction present: ' . ($status['history_hybrid']['event_catalog_extracted'] ? 'yes' : 'no');
    $lines[] = '';

    $lines[] = '## Matching Overrides (A6)';
    $lines[] = '';
    $lines[] = '- API endpoint present: ' . ($status['matching_overrides']['api_endpoint_present'] ? 'yes' : 'no');
    $lines[] = '- Service present: ' . ($status['matching_overrides']['service_present'] ? 'yes' : 'no');
    $lines[] = '- Repository CRUD present: ' . ($status['matching_overrides']['repository_crud_present'] ? 'yes' : 'no');
    $lines[] = '- Migration file present: ' . ($status['matching_overrides']['migration_file_present'] ? 'yes' : 'no');
    $lines[] = '- DB table present: ' . ($status['matching_overrides']['table_present'] ? 'yes' : 'no');
    $lines[] = '- Authority feeder wired: ' . ($status['matching_overrides']['authority_feeder_wired'] ? 'yes' : 'no');
    $lines[] = '- Export endpoint present: ' . ($status['matching_overrides']['export_endpoint_present'] ? 'yes' : 'no');
    $lines[] = '- Import endpoint present: ' . ($status['matching_overrides']['import_endpoint_present'] ? 'yes' : 'no');
    $lines[] = '- Settings tab wired: ' . ($status['matching_overrides']['settings_tab_present'] ? 'yes' : 'no');
    $lines[] = '- Settings loader wired: ' . ($status['matching_overrides']['settings_loader_present'] ? 'yes' : 'no');
    $lines[] = '';

    $lines[] = '## Print Governance (P2 Browser Print)';
    $lines[] = '';
    $lines[] = '- API endpoint present: ' . ($status['print_governance']['api_endpoint_present'] ? 'yes' : 'no');
    $lines[] = '- Service present: ' . ($status['print_governance']['service_present'] ? 'yes' : 'no');
    $lines[] = '- JS helper present: ' . ($status['print_governance']['js_helper_present'] ? 'yes' : 'no');
    $lines[] = '- Migration file present: ' . ($status['print_governance']['migration_file_present'] ? 'yes' : 'no');
    $lines[] = '- DB table present: ' . ($status['print_governance']['table_present'] ? 'yes' : 'no');
    $lines[] = '- Single-letter audit wiring: ' . ($status['print_governance']['single_letter_wiring'] ? 'yes' : 'no');
    $lines[] = '- Batch-print audit wiring: ' . ($status['print_governance']['batch_wiring'] ? 'yes' : 'no');
    $lines[] = '- Preview API audit wiring: ' . ($status['print_governance']['preview_api_wiring'] ? 'yes' : 'no');
    $lines[] = '';

    $lines[] = '## Config Governance (Settings Audit)';
    $lines[] = '';
    $lines[] = '- Settings save API present: ' . ($status['config_governance']['settings_api_present'] ? 'yes' : 'no');
    $lines[] = '- Audit service present: ' . ($status['config_governance']['service_present'] ? 'yes' : 'no');
    $lines[] = '- Audit endpoint present: ' . ($status['config_governance']['audit_endpoint_present'] ? 'yes' : 'no');
    $lines[] = '- Save hook wired: ' . ($status['config_governance']['save_hook_wired'] ? 'yes' : 'no');
    $lines[] = '- Migration file present: ' . ($status['config_governance']['migration_file_present'] ? 'yes' : 'no');
    $lines[] = '- DB table present: ' . ($status['config_governance']['table_present'] ? 'yes' : 'no');
    $lines[] = '';

    $lines[] = '## Reopen Governance + Break-Glass';
    $lines[] = '';
    $lines[] = '- Migration file present: ' . ($status['reopen_governance']['migration_file_present'] ? 'yes' : 'no');
    $lines[] = '- `batch_audit_events` table present: ' . ($status['reopen_governance']['batch_audit_table_present'] ? 'yes' : 'no');
    $lines[] = '- `break_glass_events` table present: ' . ($status['reopen_governance']['break_glass_table_present'] ? 'yes' : 'no');
    $lines[] = '- Batch reopen permission gate wired: ' . ($status['reopen_governance']['batch_permission_gate_wired'] ? 'yes' : 'no');
    $lines[] = '- Batch reopen reason enforcement wired: ' . ($status['reopen_governance']['batch_reason_enforced'] ? 'yes' : 'no');
    $lines[] = '- Batch reopen audit trail wired: ' . ($status['reopen_governance']['batch_audit_wired'] ? 'yes' : 'no');
    $lines[] = '- Guarantee reopen permission gate wired: ' . ($status['reopen_governance']['guarantee_permission_gate_wired'] ? 'yes' : 'no');
    $lines[] = '- Break-glass authorization wired: ' . ($status['reopen_governance']['break_glass_auth_wired'] ? 'yes' : 'no');
    $lines[] = '- Break-glass runtime enabled: ' . ($status['reopen_governance']['break_glass_runtime_enabled'] ? 'yes' : 'no');
    $lines[] = '';

    $lines[] = '## Released Read-Only Policy';
    $lines[] = '';
    $lines[] = '- Policy service present: ' . ($status['released_read_only']['policy_service_present'] ? 'yes' : 'no');
    $lines[] = '- Extend API guarded: ' . ($status['released_read_only']['extend_guarded'] ? 'yes' : 'no');
    $lines[] = '- Reduce API guarded: ' . ($status['released_read_only']['reduce_guarded'] ? 'yes' : 'no');
    $lines[] = '- Update API guarded: ' . ($status['released_read_only']['update_guarded'] ? 'yes' : 'no');
    $lines[] = '- Save-and-next API guarded: ' . ($status['released_read_only']['save_and_next_guarded'] ? 'yes' : 'no');
    $lines[] = '- Attachment upload API guarded: ' . ($status['released_read_only']['attachment_guarded'] ? 'yes' : 'no');
    $lines[] = '';

    $lines[] = '## API Token Auth (BG Strength Adoption)';
    $lines[] = '';
    $lines[] = '- Token service present: ' . ($status['token_auth']['service_present'] ? 'yes' : 'no');
    $lines[] = '- Migration file present: ' . ($status['token_auth']['migration_file_present'] ? 'yes' : 'no');
    $lines[] = '- DB table present: ' . ($status['token_auth']['table_present'] ? 'yes' : 'no');
    $lines[] = '- API bootstrap integration: ' . ($status['token_auth']['bootstrap_integration_present'] ? 'yes' : 'no');
    $lines[] = '- Login token issuance support: ' . ($status['token_auth']['login_issue_support'] ? 'yes' : 'no');
    $lines[] = '- Logout token revoke support: ' . ($status['token_auth']['logout_revoke_support'] ? 'yes' : 'no');
    $lines[] = '- Me endpoint present: ' . ($status['token_auth']['me_endpoint_present'] ? 'yes' : 'no');
    $lines[] = '';

    $lines[] = '## User Language Preferences';
    $lines[] = '';
    $lines[] = '- `users.preferred_language` column present: ' . ($status['user_language']['db_column_present'] ? 'yes' : 'no');
    $lines[] = '- User model field present: ' . ($status['user_language']['user_model_field_present'] ? 'yes' : 'no');
    $lines[] = '- Repository support present: ' . ($status['user_language']['repository_support_present'] ? 'yes' : 'no');
    $lines[] = '- Create/update users API support: ' . ($status['user_language']['users_api_support_present'] ? 'yes' : 'no');
    $lines[] = '- Users list API output support: ' . ($status['user_language']['users_list_support_present'] ? 'yes' : 'no');
    $lines[] = '- Preferences API present: ' . ($status['user_language']['preferences_api_present'] ? 'yes' : 'no');
    $lines[] = '';

    $lines[] = '## UI i18n + Direction';
    $lines[] = '';
    $lines[] = '- i18n runtime file present: ' . ($status['ui_i18n']['runtime_present'] ? 'yes' : 'no');
    $lines[] = '- Dynamic direction handling present: ' . ($status['ui_i18n']['dynamic_direction_present'] ? 'yes' : 'no');
    $lines[] = '- Unified header wired: ' . ($status['ui_i18n']['header_wired'] ? 'yes' : 'no');
    $lines[] = '- Login view wired: ' . ($status['ui_i18n']['login_wired'] ? 'yes' : 'no');
    $lines[] = '- Users view wired: ' . ($status['ui_i18n']['users_wired'] ? 'yes' : 'no');
    $lines[] = '- Batch print view wired: ' . ($status['ui_i18n']['batch_print_wired'] ? 'yes' : 'no');
    $lines[] = '';

    $lines[] = '## Global Keyboard Shortcuts';
    $lines[] = '';
    $lines[] = '- Shortcuts runtime file present: ' . ($status['global_shortcuts']['runtime_present'] ? 'yes' : 'no');
    $lines[] = '- Help modal support present: ' . ($status['global_shortcuts']['help_modal_present'] ? 'yes' : 'no');
    $lines[] = '- Unified header wired: ' . ($status['global_shortcuts']['header_wired'] ? 'yes' : 'no');
    $lines[] = '- Login view wired: ' . ($status['global_shortcuts']['login_wired'] ? 'yes' : 'no');
    $lines[] = '- Users view wired: ' . ($status['global_shortcuts']['users_wired'] ? 'yes' : 'no');
    $lines[] = '- Batch print view wired: ' . ($status['global_shortcuts']['batch_print_wired'] ? 'yes' : 'no');
    $lines[] = '';

    $lines[] = '## UI Architecture Readiness';
    $lines[] = '';
    $lines[] = '- View guard coverage: ' . $status['ui_architecture']['view_guard']['guarded'] . '/' . $status['ui_architecture']['view_guard']['expected_total']
        . ' (' . $status['ui_architecture']['view_guard']['coverage_pct'] . '%)';
    $lines[] = '- API policy matrix parity: ' . ($status['ui_architecture']['api_policy_matrix']['parity'] ? 'yes' : 'no')
        . ' (missing=' . $status['ui_architecture']['api_policy_matrix']['missing_count']
        . ', extra=' . $status['ui_architecture']['api_policy_matrix']['extra_count'] . ')';
    $lines[] = '- Translation coverage: ' . $status['ui_architecture']['translation']['coverage_pct'] . '%'
        . ' (used=' . $status['ui_architecture']['translation']['used_keys']
        . ', missing=' . $status['ui_architecture']['translation']['missing_keys'] . ')';
    $lines[] = '- RTL readiness: ' . $status['ui_architecture']['rtl_readiness']['coverage_pct'] . '%'
        . ' (' . $status['ui_architecture']['rtl_readiness']['screens_wired']
        . '/' . $status['ui_architecture']['rtl_readiness']['screens_total'] . ')';
    $lines[] = '- Theme token coverage: ' . $status['ui_architecture']['theme_token_coverage']['coverage_pct'] . '%'
        . ' (vars=' . $status['ui_architecture']['theme_token_coverage']['var_refs']
        . ', hex=' . $status['ui_architecture']['theme_token_coverage']['hex_refs'] . ')';
    $lines[] = '- Component policy tags: ' . $status['ui_architecture']['component_policy']['authorize_tag_count'];
    $lines[] = '- Readiness gates: translation=' . ($status['ui_architecture']['readiness']['translation_target_pass'] ? 'pass' : 'fail')
        . ', rtl=' . ($status['ui_architecture']['readiness']['rtl_target_pass'] ? 'pass' : 'fail')
        . ', theme=' . ($status['ui_architecture']['readiness']['theme_token_target_pass'] ? 'pass' : 'fail')
        . ', view-guard=' . ($status['ui_architecture']['readiness']['view_guard_target_pass'] ? 'pass' : 'fail')
        . ', api-matrix=' . ($status['ui_architecture']['readiness']['api_matrix_parity_pass'] ? 'pass' : 'fail');
    $lines[] = '';

    $lines[] = '## Playwright Readiness';
    $lines[] = '';
    $lines[] = '- Package present: ' . ($status['playwright']['package_present'] ? 'yes' : 'no');
    $lines[] = '- Config present: ' . ($status['playwright']['config_present'] ? 'yes' : 'no');
    $lines[] = '- Script present: ' . ($status['playwright']['script_present'] ? 'yes' : 'no');
    $lines[] = '- Dependency present: ' . ($status['playwright']['dependency_present'] ? 'yes' : 'no');
    $lines[] = '- E2E tests count: ' . $status['playwright']['tests_count'];
    $lines[] = '- Overall ready: ' . ($status['playwright']['ready'] ? 'yes' : 'no');
    $lines[] = '';

    $lines[] = '## UX/A11y Hardening (P3)';
    $lines[] = '';
    $lines[] = '- A11y CSS file present: ' . ($status['ux_a11y']['a11y_css_present'] ? 'yes' : 'no');
    $lines[] = '- Focus-visible rule present: ' . ($status['ux_a11y']['focus_visible_rule_present'] ? 'yes' : 'no');
    $lines[] = '- `sr-only` utility present: ' . ($status['ux_a11y']['sr_only_utility_present'] ? 'yes' : 'no');
    $lines[] = '- A11y CSS linked in index: ' . ($status['ux_a11y']['index_linked'] ? 'yes' : 'no');
    $lines[] = '- A11y CSS linked in batch detail: ' . ($status['ux_a11y']['batch_detail_linked'] ? 'yes' : 'no');
    $lines[] = '- A11y CSS linked in settings: ' . ($status['ux_a11y']['settings_linked'] ? 'yes' : 'no');
    $lines[] = '- Settings tab semantics wired: ' . ($status['ux_a11y']['settings_tab_semantics'] ? 'yes' : 'no');
    $lines[] = '- Settings modal semantics wired: ' . ($status['ux_a11y']['settings_modal_semantics'] ? 'yes' : 'no');
    $lines[] = '- Batch detail modal semantics wired: ' . ($status['ux_a11y']['batch_detail_modal_semantics'] ? 'yes' : 'no');
    $lines[] = '- Batch detail icon labels present: ' . ($status['ux_a11y']['batch_detail_icon_labels'] ? 'yes' : 'no');
    $lines[] = '';

    $lines[] = '## Migrations';
    $lines[] = '';
    $lines[] = '- SQL files: ' . $status['migrations']['sql_files'];
    $lines[] = '- Applied: ' . $status['migrations']['applied'];
    $lines[] = '- Pending: ' . $status['migrations']['pending'];
    $lines[] = '';

    $lines[] = '## Tests';
    $lines[] = '';
    $lines[] = '- Test files: ' . $status['tests']['files_total'];
    $lines[] = '- Unit files: ' . $status['tests']['unit_files'];
    $lines[] = '- Integration files: ' . $status['tests']['integration_files'];
    $lines[] = '- Enterprise API integration suite present: ' . ($status['tests']['integration_flow_suite_present'] ? 'yes' : 'no');
    $lines[] = '';

    $lines[] = '## Next Batch (Autogenerated)';
    $lines[] = '';
    foreach ($status['next_batch'] as $item) {
        $lines[] = '- `' . $item['task_id'] . '` ' . $item['title'];
        $lines[] = '  - Target: `' . $item['target'] . '`';
        $lines[] = '  - Gap refs: `' . implode(', ', $item['gap_refs']) . '`';
    }
    $lines[] = '';

    return implode(PHP_EOL, $lines) . PHP_EOL;
}

$wbglRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$workspaceRoot = dirname($wbglRoot);
$docsRoot = $workspaceRoot . DIRECTORY_SEPARATOR . 'Docs';
$repoDocsRoot = $wbglRoot . DIRECTORY_SEPARATOR . 'docs';

$requiredDocs = [
    'ONE_PAGE_OPERATING_PLAN.md',
    'WBGL_MASTER_UPGRADE_PLAN.md',
    'WBGL_NATIVE_FEATURE_ADOPTION_CHARTER.md',
    'WBGL_SPRINT_0_HARDENING_BASELINE.md',
    'WBGL_ENTERPRISE_GRADE_EXECUTION_PLAN.md',
    'WBGL_EXECUTION_QUEUE_NO_DEADLINE.md',
    'FINAL_MISSING_FEATURES_MATRIX_WBGL_BG.md',
    'final_missing_features_matrix.json',
    'WBGL_BG_Full_Feature_Census.md',
    'differential_analysis.md',
    'final_statistical_summary.json',
    'UX_Comparison_WBGL_vs_BG_Report.md',
    'OBSERVABILITY_RUNBOOK.md',
    'DB_CUTOVER_RUNBOOK.md',
    'BACKUP_RESTORE_RUNBOOK.md',
    'PGSQL_ACTIVATION_RUNBOOK.md',
    'GOVERNANCE_POLICY.md',
    'SOD_MATRIX.md',
    'BREAK_GLASS_RUNBOOK.md',
    'ENTERPRISE_REMAINING_EXECUTION_TABLE.md',
    'ENTERPRISE_GATES_CLOSURE.md',
    'ENTERPRISE_DECLARATION.md',
];

$missingDocs = [];
foreach ($requiredDocs as $doc) {
    if (!is_file($docsRoot . DIRECTORY_SEPARATOR . $doc)) {
        $missingDocs[] = 'Docs/' . $doc;
    }
}

$matrixPath = $docsRoot . DIRECTORY_SEPARATOR . 'final_missing_features_matrix.json';
$matrix = wbglLoopReadJson($matrixPath);
$wbglMissingGapsRaw = isset($matrix['missing_in_wbgl_present_in_bg']) && is_array($matrix['missing_in_wbgl_present_in_bg'])
    ? $matrix['missing_in_wbgl_present_in_bg']
    : [];
$wbglMissingGaps = array_values(array_filter(
    $wbglMissingGapsRaw,
    static function (array $gap): bool {
        $status = strtolower(trim((string)($gap['status'] ?? 'open')));
        return $status !== 'closed';
    }
));

$priorityRank = ['High' => 1, 'Medium' => 2, 'Low' => 3];
usort($wbglMissingGaps, static function (array $a, array $b) use ($priorityRank): int {
    $pa = $priorityRank[(string)($a['priority'] ?? 'Low')] ?? 9;
    $pb = $priorityRank[(string)($b['priority'] ?? 'Low')] ?? 9;
    if ($pa !== $pb) {
        return $pa <=> $pb;
    }
    return strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
});

$byPriority = ['High' => 0, 'Medium' => 0, 'Low' => 0];
foreach ($wbglMissingGaps as $gap) {
    $p = (string)($gap['priority'] ?? 'Low');
    if (!isset($byPriority[$p])) {
        $byPriority[$p] = 0;
    }
    $byPriority[$p]++;
}

$phaseTemplate = [
    'P0' => ['A1', 'A2', 'A3', 'A7', 'A9', 'A10'],
    'P1' => ['A8'],
    'P2' => ['A4', 'A5'],
    'P3' => ['A6'],
];
$openGapIds = [];
foreach ($wbglMissingGaps as $gap) {
    $id = trim((string)($gap['id'] ?? ''));
    if ($id !== '') {
        $openGapIds[] = $id;
    }
}
$openGapIds = array_values(array_unique($openGapIds));

$phaseMap = [];
foreach ($phaseTemplate as $phase => $ids) {
    $phaseMap[$phase] = array_values(array_intersect($ids, $openGapIds));
}

$apiAllowlist = [
    'api/_bootstrap.php',
    'api/login.php',
    'api/logout.php',
];

$allApi = wbglLoopFindPhpFiles($wbglRoot . DIRECTORY_SEPARATOR . 'api');
$guarded = 0;
$unguarded = 0;
$sensitiveUnguarded = 0;
$unguardedList = [];
$sensitiveUnguardedList = [];

foreach ($allApi as $file) {
    $rel = wbglLoopRelativePath($wbglRoot, $file);
    $rel = wbglLoopNormalize($rel);

    if (in_array($rel, $apiAllowlist, true)) {
        continue;
    }

    $content = file_get_contents($file);
    if ($content === false) {
        continue;
    }

    $hasBootstrap = str_contains($content, '_bootstrap.php');
    $hasApiGuard = preg_match('/wbgl_api_require_login\s*\(|wbgl_api_require_permission\s*\(/', $content) === 1;
    $isGuarded = $hasBootstrap && $hasApiGuard;
    if ($isGuarded) {
        $guarded++;
    } else {
        $unguarded++;
        $unguardedList[] = $rel;
    }

    $isSensitive = (bool)preg_match(
        '/(create|update|delete|import|release|reduce|extend|reopen|settings|workflow-advance|upload-attachment|save-and-next|merge|users\/)/i',
        $rel
    );
    if ($isSensitive && !$isGuarded) {
        $sensitiveUnguarded++;
        $sensitiveUnguardedList[] = $rel;
    }
}

$migrationDir = $wbglRoot . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';
$migrationFiles = glob($migrationDir . DIRECTORY_SEPARATOR . '*.sql') ?: [];
sort($migrationFiles);
$loopDbDriver = wbglLoopDetectDbDriver();
$loopDbConfigSummary = [];
try {
    $loopDbConfigSummary = Database::configurationSummary();
} catch (Throwable $e) {
    $loopDbConfigSummary = [];
}

$appliedMigrations = [];
try {
    $db = Database::connect();
    $tableExists = wbglLoopTableExists($db, $loopDbDriver, 'schema_migrations');
    if ($tableExists) {
        $rows = $db->query('SELECT migration FROM schema_migrations ORDER BY migration ASC');
        if ($rows) {
            $appliedMigrations = array_map(
                static fn(array $r): string => (string)$r['migration'],
                $rows->fetchAll(PDO::FETCH_ASSOC)
            );
        }
    }
} catch (Throwable $e) {
    // Keep loop status generation resilient even when DB is temporarily unavailable.
}

$migrationFileNames = array_map(static fn(string $f): string => basename($f), $migrationFiles);
$pendingMigrations = array_values(array_diff($migrationFileNames, $appliedMigrations));

$loginApiFile = $wbglRoot . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'login.php';
$loginApiContent = is_file($loginApiFile) ? (string)file_get_contents($loginApiFile) : '';
$hasLoginRateApiHook = preg_match('/LoginRateLimiter::check|LoginRateLimiter::recordFailure|LoginRateLimiter::clear/', $loginApiContent) === 1;
$hasLoginRateMigration = in_array('20260226_000002_add_login_rate_limits.sql', $migrationFileNames, true);
$hasLoginRateTable = false;
try {
    $db = Database::connect();
    $hasLoginRateTable = wbglLoopTableExists($db, $loopDbDriver, 'login_rate_limits');
} catch (Throwable $e) {
    $hasLoginRateTable = false;
}

$undoApiFile = $wbglRoot . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'undo-requests.php';
$undoServiceFile = $wbglRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Services' . DIRECTORY_SEPARATOR . 'UndoRequestService.php';
$hasUndoApi = is_file($undoApiFile);
$hasUndoService = is_file($undoServiceFile);
$hasUndoMigration = in_array('20260226_000003_create_undo_requests_table.sql', $migrationFileNames, true);
$hasUndoTable = false;
try {
    $db = Database::connect();
    $hasUndoTable = wbglLoopTableExists($db, $loopDbDriver, 'undo_requests');
} catch (Throwable $e) {
    $hasUndoTable = false;
}
$reopenPath = $wbglRoot . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'reopen.php';
$reopenContent = is_file($reopenPath) ? (string)file_get_contents($reopenPath) : '';
$hasUndoReopenIntegration = str_contains($reopenContent, 'UndoRequestService::');
$hasUndoAlwaysEnforced = !str_contains($reopenContent, 'ENFORCE_UNDO_REQUEST_WORKFLOW')
    && str_contains($reopenContent, 'UndoRequestService::submit');
$settingsPath = $wbglRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'Settings.php';
$hasUndoEnforcementFlag = false;

$visibilityServicePath = $wbglRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Services' . DIRECTORY_SEPARATOR . 'GuaranteeVisibilityService.php';
$navigationPath = $wbglRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Services' . DIRECTORY_SEPARATOR . 'NavigationService.php';
$statsPath = $wbglRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Services' . DIRECTORY_SEPARATOR . 'StatsService.php';

$visibilityServicePresent = is_file($visibilityServicePath);
$navigationIntegrated = false;
$statsIntegrated = false;
$visibilityAlwaysEnforced = false;
$endpointEnforcement = true;

if (is_file($navigationPath)) {
    $content = (string)file_get_contents($navigationPath);
    $navigationIntegrated = str_contains($content, 'GuaranteeVisibilityService::buildSqlFilter');
}
if (is_file($statsPath)) {
    $content = (string)file_get_contents($statsPath);
    $statsIntegrated = str_contains($content, 'GuaranteeVisibilityService::buildSqlFilter');
}
if (is_file($visibilityServicePath)) {
    $content = (string)file_get_contents($visibilityServicePath);
    $visibilityAlwaysEnforced = !str_contains($content, 'ENABLE_ROLE_SCOPED_VISIBILITY');
}

$enforcedEndpointRules = [
    [
        'path' => $wbglRoot . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'get-current-state.php',
        'patterns' => ['GuaranteeVisibilityService::canAccessGuarantee'],
    ],
    [
        'path' => $wbglRoot . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'get-history-snapshot.php',
        'patterns' => ['GuaranteeVisibilityService::canAccessGuarantee'],
    ],
    [
        'path' => $wbglRoot . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'get-letter-preview.php',
        'patterns' => ['GuaranteeVisibilityService::canAccessGuarantee'],
    ],
    [
        'path' => $wbglRoot . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'get-timeline.php',
        'patterns' => [
            'GuaranteeVisibilityService::canAccessGuarantee',
            'NavigationService::getIdByIndex',
        ],
    ],
];
foreach ($enforcedEndpointRules as $rule) {
    $content = is_file($rule['path']) ? (string)file_get_contents($rule['path']) : '';
    $matched = false;
    foreach ($rule['patterns'] as $pattern) {
        if (str_contains($content, $pattern)) {
            $matched = true;
            break;
        }
    }
    if (!$matched) {
        $endpointEnforcement = false;
        break;
    }
}

$notificationApiPath = $wbglRoot . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'notifications.php';
$notificationServicePath = $wbglRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Services' . DIRECTORY_SEPARATOR . 'NotificationService.php';
$hasNotificationApi = is_file($notificationApiPath);
$hasNotificationService = is_file($notificationServicePath);
$hasNotificationMigration = in_array('20260226_000004_create_notifications_table.sql', $migrationFileNames, true);
$hasNotificationTable = false;
try {
    $db = Database::connect();
    $hasNotificationTable = wbglLoopTableExists($db, $loopDbDriver, 'notifications');
} catch (Throwable $e) {
    $hasNotificationTable = false;
}

$scheduleRunnerPath = $wbglRoot . DIRECTORY_SEPARATOR . 'maint' . DIRECTORY_SEPARATOR . 'schedule.php';
$expiryJobPath = $wbglRoot . DIRECTORY_SEPARATOR . 'maint' . DIRECTORY_SEPARATOR . 'notify-expiry.php';
$scheduleStatusPath = $wbglRoot . DIRECTORY_SEPARATOR . 'maint' . DIRECTORY_SEPARATOR . 'schedule-status.php';
$scheduleDeadLettersPath = $wbglRoot . DIRECTORY_SEPARATOR . 'maint' . DIRECTORY_SEPARATOR . 'schedule-dead-letters.php';
$schedulerRuntimeServicePath = $wbglRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Services' . DIRECTORY_SEPARATOR . 'SchedulerRuntimeService.php';
$schedulerDeadLetterServicePath = $wbglRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Services' . DIRECTORY_SEPARATOR . 'SchedulerDeadLetterService.php';
$schedulerJobCatalogPath = $wbglRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Services' . DIRECTORY_SEPARATOR . 'SchedulerJobCatalog.php';
$schedulerDeadLetterApiPath = $wbglRoot . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'scheduler-dead-letters.php';
$schedulerRunnerPresent = is_file($scheduleRunnerPath);
$schedulerExpiryJobPresent = is_file($expiryJobPath);
$schedulerRuntimeServicePresent = is_file($schedulerRuntimeServicePath);
$schedulerDeadLetterServicePresent = is_file($schedulerDeadLetterServicePath);
$schedulerDeadLetterApiPresent = is_file($schedulerDeadLetterApiPath);
$schedulerDeadLetterCommandPresent = is_file($scheduleDeadLettersPath);
$runnerWiresExpiry = false;
$schedulerRetrySupportWired = false;
if ($schedulerRunnerPresent) {
    $runnerContent = (string)file_get_contents($scheduleRunnerPath);
    $catalogContent = is_file($schedulerJobCatalogPath) ? (string)file_get_contents($schedulerJobCatalogPath) : '';
    $runnerWiresExpiry =
        str_contains($runnerContent, 'SchedulerJobCatalog::all')
        && str_contains($catalogContent, 'notify-expiry.php');
    $schedulerRetrySupportWired =
        str_contains($runnerContent, 'max_attempts')
        && str_contains($runnerContent, 'SchedulerRuntimeService::runJob');
}
$schedulerStatusCommandPresent = is_file($scheduleStatusPath);
$schedulerRunLedgerMigration = in_array('20260226_000008_create_scheduler_job_runs_table.sql', $migrationFileNames, true);
$schedulerDeadLetterMigration = in_array('20260226_000010_create_scheduler_dead_letters_table.sql', $migrationFileNames, true);
$schedulerRunLedgerTablePresent = false;
try {
    $db = Database::connect();
    $schedulerRunLedgerTablePresent = wbglLoopTableExists($db, $loopDbDriver, 'scheduler_job_runs');
} catch (Throwable $e) {
    $schedulerRunLedgerTablePresent = false;
}
$schedulerDeadLetterTablePresent = false;
try {
    $db = Database::connect();
    $schedulerDeadLetterTablePresent = wbglLoopTableExists($db, $loopDbDriver, 'scheduler_dead_letters');
} catch (Throwable $e) {
    $schedulerDeadLetterTablePresent = false;
}
$schedulerRuntimeDeadLetterIntegration = false;
if (is_file($schedulerRuntimeServicePath) && is_file($schedulerDeadLetterServicePath)) {
    $runtimeContent = (string)file_get_contents($schedulerRuntimeServicePath);
    $schedulerRuntimeDeadLetterIntegration = str_contains($runtimeContent, 'SchedulerDeadLetterService::recordFailure');
}

$historyHybridMigration = in_array('20260226_000005_add_hybrid_history_columns.sql', $migrationFileNames, true);
$historyHybridServicePath = $wbglRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Services' . DIRECTORY_SEPARATOR . 'TimelineHybridLedger.php';
$historyEventCatalogPath = $wbglRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Services' . DIRECTORY_SEPARATOR . 'TimelineEventCatalog.php';
$historySnapshotApiPath = $wbglRoot . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'get-history-snapshot.php';
$timelineRecorderPath = $wbglRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Services' . DIRECTORY_SEPARATOR . 'TimelineRecorder.php';

$historyHybridServicePresent = is_file($historyHybridServicePath);
$historyEventCatalogPresent = is_file($historyEventCatalogPath);
$historySettingsFlagPresent = false;
 $historyPolicyLocked = false;
if (is_file($settingsPath)) {
    $settingsContent = (string)file_get_contents($settingsPath);
    $historySettingsFlagPresent = str_contains($settingsContent, 'ENABLE_HISTORY_HYBRID_LEDGER');
}
if (is_file($historyHybridServicePath)) {
    $historyHybridContent = (string)file_get_contents($historyHybridServicePath);
    $historyPolicyLocked = str_contains($historyHybridContent, 'return true;');
}

$historyRecorderIntegration = false;
if (is_file($timelineRecorderPath)) {
    $content = (string)file_get_contents($timelineRecorderPath);
    $historyRecorderIntegration = str_contains($content, 'TimelineHybridLedger::buildHybridPayload');
}

$historySnapshotReaderIntegration = false;
if (is_file($historySnapshotApiPath)) {
    $content = (string)file_get_contents($historySnapshotApiPath);
    $historySnapshotReaderIntegration = str_contains($content, 'TimelineHybridLedger::resolveEventSnapshot');
}

$historyEventCatalogExtracted = false;
if (is_file($timelineRecorderPath) && $historyEventCatalogPresent) {
    $content = (string)file_get_contents($timelineRecorderPath);
    $historyEventCatalogExtracted =
        str_contains($content, 'TimelineEventCatalog::getEventDisplayLabel')
        && str_contains($content, 'TimelineEventCatalog::getEventIcon');
}

$historyHybridColumnsPresent = false;
try {
    $db = Database::connect();
    $columns = wbglLoopTableColumns($db, $loopDbDriver, 'guarantee_history');
    $requiredHybridColumns = [
        'history_version',
        'patch_data',
        'anchor_snapshot',
        'is_anchor',
        'anchor_reason',
        'letter_context',
        'template_version',
    ];
    $historyHybridColumnsPresent = count(array_diff($requiredHybridColumns, $columns)) === 0;
} catch (Throwable $e) {
    $historyHybridColumnsPresent = false;
}

$matchingOverridesApiPath = $wbglRoot . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'matching-overrides.php';
$matchingOverridesExportPath = $wbglRoot . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'export_matching_overrides.php';
$matchingOverridesImportPath = $wbglRoot . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'import_matching_overrides.php';
$matchingOverridesServicePath = $wbglRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Services' . DIRECTORY_SEPARATOR . 'MatchingOverrideService.php';
$matchingOverridesRepoPath = $wbglRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Repositories' . DIRECTORY_SEPARATOR . 'SupplierOverrideRepository.php';
$authorityFactoryPath = $wbglRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Services' . DIRECTORY_SEPARATOR . 'Learning' . DIRECTORY_SEPARATOR . 'AuthorityFactory.php';
$overrideFeederPath = $wbglRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Services' . DIRECTORY_SEPARATOR . 'Learning' . DIRECTORY_SEPARATOR . 'Feeders' . DIRECTORY_SEPARATOR . 'OverrideSignalFeeder.php';
$settingsViewPath = $wbglRoot . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'settings.php';

$matchingOverridesApiPresent = is_file($matchingOverridesApiPath);
$matchingOverridesExportPresent = is_file($matchingOverridesExportPath);
$matchingOverridesImportPresent = is_file($matchingOverridesImportPath);
$matchingOverridesServicePresent = is_file($matchingOverridesServicePath);
$matchingOverridesRepoCrudPresent = false;
if (is_file($matchingOverridesRepoPath)) {
    $content = (string)file_get_contents($matchingOverridesRepoPath);
    $matchingOverridesRepoCrudPresent =
        str_contains($content, 'function list(')
        && str_contains($content, 'function upsert(')
        && str_contains($content, 'function updateById(')
        && str_contains($content, 'function deleteById(');
}
$matchingOverridesMigration = in_array('20260226_000006_create_supplier_overrides_table.sql', $migrationFileNames, true);
$matchingOverridesTablePresent = false;
try {
    $db = Database::connect();
    $matchingOverridesTablePresent = wbglLoopTableExists($db, $loopDbDriver, 'supplier_overrides');
} catch (Throwable $e) {
    $matchingOverridesTablePresent = false;
}
$matchingOverridesAuthorityWired = false;
if (is_file($authorityFactoryPath) && is_file($overrideFeederPath)) {
    $content = (string)file_get_contents($authorityFactoryPath);
    $matchingOverridesAuthorityWired =
        str_contains($content, 'OverrideSignalFeeder')
        && str_contains($content, 'createOverrideFeeder')
        && str_contains($content, 'registerFeeder(self::createOverrideFeeder())');
}

$matchingOverridesSettingsTabPresent = false;
$matchingOverridesSettingsLoaderPresent = false;
if (is_file($settingsViewPath)) {
    $settingsViewContent = (string)file_get_contents($settingsViewPath);
    $matchingOverridesSettingsTabPresent =
        str_contains($settingsViewContent, "switchTab('overrides')")
        && str_contains($settingsViewContent, 'id="overridesTableContainer"');
    $matchingOverridesSettingsLoaderPresent =
        str_contains($settingsViewContent, 'function loadMatchingOverrides()')
        && str_contains($settingsViewContent, "tabId === 'overrides'");
}

$printEventsApiPath = $wbglRoot . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'print-events.php';
$printAuditServicePath = $wbglRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Services' . DIRECTORY_SEPARATOR . 'PrintAuditService.php';
$printAuditJsPath = $wbglRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'print-audit.js';
$letterTemplatePath = $wbglRoot . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'letter-template.php';
$batchPrintViewPath = $wbglRoot . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'batch-print.php';
$batchDetailViewPath = $wbglRoot . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'batch-detail.php';
$indexPath = $wbglRoot . DIRECTORY_SEPARATOR . 'index.php';
$previewApiPath = $wbglRoot . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'get-letter-preview.php';

$printGovernanceApiPresent = is_file($printEventsApiPath);
$printGovernanceServicePresent = is_file($printAuditServicePath);
$printGovernanceJsPresent = is_file($printAuditJsPath);
$printGovernanceMigration = in_array('20260226_000007_create_print_events_table.sql', $migrationFileNames, true);
$printGovernanceTablePresent = false;
try {
    $db = Database::connect();
    $printGovernanceTablePresent = wbglLoopTableExists($db, $loopDbDriver, 'print_events');
} catch (Throwable $e) {
    $printGovernanceTablePresent = false;
}

$printGovernanceSingleWiring = false;
if (is_file($letterTemplatePath) && is_file($indexPath)) {
    $templateContent = (string)file_get_contents($letterTemplatePath);
    $indexContent = (string)file_get_contents($indexPath);
    $printGovernanceSingleWiring =
        str_contains($templateContent, 'handleOverlayPrint')
        && str_contains($indexContent, 'print-audit.js');
}

$printGovernanceBatchWiring = false;
if (is_file($batchPrintViewPath) && is_file($batchDetailViewPath)) {
    $batchPrintContent = (string)file_get_contents($batchPrintViewPath);
    $batchDetailContent = (string)file_get_contents($batchDetailViewPath);
    $printGovernanceBatchWiring =
        str_contains($batchPrintContent, 'recordBatchPrint')
        && str_contains($batchDetailContent, 'batch_identifier');
}

$printGovernancePreviewApiWiring = false;
if (is_file($previewApiPath)) {
    $previewApiContent = (string)file_get_contents($previewApiPath);
    $printGovernancePreviewApiWiring = str_contains($previewApiContent, 'PrintAuditService::record');
}

$settingsApiPath = $wbglRoot . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'settings.php';
$settingsAuditApiPath = $wbglRoot . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'settings-audit.php';
$settingsAuditServicePath = $wbglRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Services' . DIRECTORY_SEPARATOR . 'SettingsAuditService.php';

$configGovernanceSettingsApiPresent = is_file($settingsApiPath);
$configGovernanceAuditApiPresent = is_file($settingsAuditApiPath);
$configGovernanceServicePresent = is_file($settingsAuditServicePath);
$configGovernanceSaveHookWired = false;
if ($configGovernanceSettingsApiPresent) {
    $settingsApiContent = (string)file_get_contents($settingsApiPath);
    $configGovernanceSaveHookWired = str_contains($settingsApiContent, 'SettingsAuditService::recordChangeSet');
}
$configGovernanceMigration = in_array('20260226_000009_create_settings_audit_logs_table.sql', $migrationFileNames, true);
$configGovernanceTablePresent = false;
try {
    $db = Database::connect();
    $configGovernanceTablePresent = wbglLoopTableExists($db, $loopDbDriver, 'settings_audit_logs');
} catch (Throwable $e) {
    $configGovernanceTablePresent = false;
}

$breakGlassServicePath = $wbglRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Services' . DIRECTORY_SEPARATOR . 'BreakGlassService.php';
$batchAuditServicePath = $wbglRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Services' . DIRECTORY_SEPARATOR . 'BatchAuditService.php';
$mutationPolicyServicePath = $wbglRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Services' . DIRECTORY_SEPARATOR . 'GuaranteeMutationPolicyService.php';
$extendApiPath = $wbglRoot . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'extend.php';
$reduceApiPath = $wbglRoot . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'reduce.php';
$saveAndNextApiPath = $wbglRoot . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'save-and-next.php';
$updateGuaranteeApiPath = $wbglRoot . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'update-guarantee.php';
$uploadAttachmentApiPath = $wbglRoot . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'upload-attachment.php';
$batchApiPath = $wbglRoot . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'batches.php';
$settingsStoragePath = $wbglRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'settings.json';

$reopenGovernanceMigrationPresent = in_array('20260226_000012_break_glass_and_batch_governance.sql', $migrationFileNames, true);
$batchAuditTablePresent = false;
try {
    $db = Database::connect();
    $batchAuditTablePresent = wbglLoopTableExists($db, $loopDbDriver, 'batch_audit_events');
} catch (Throwable $e) {
    $batchAuditTablePresent = false;
}
$breakGlassTablePresent = false;
try {
    $db = Database::connect();
    $breakGlassTablePresent = wbglLoopTableExists($db, $loopDbDriver, 'break_glass_events');
} catch (Throwable $e) {
    $breakGlassTablePresent = false;
}

$batchPermissionGateWired = false;
$batchReasonEnforced = false;
$batchBreakGlassWired = false;
if (is_file($batchApiPath)) {
    $batchApiContent = (string)file_get_contents($batchApiPath);
    $batchPermissionGateWired = str_contains($batchApiContent, "Guard::has('reopen_batch')");
    $batchReasonEnforced = str_contains($batchApiContent, 'سبب إعادة فتح الدفعة مطلوب');
    $batchBreakGlassWired = str_contains($batchApiContent, 'BreakGlassService::authorizeAndRecord');
}

$batchAuditWired = false;
if (is_file($batchAuditServicePath) && is_file($wbglRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Services' . DIRECTORY_SEPARATOR . 'BatchService.php')) {
    $batchServiceContent = (string)file_get_contents($wbglRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Services' . DIRECTORY_SEPARATOR . 'BatchService.php');
    $batchAuditWired = str_contains($batchServiceContent, 'BatchAuditService::record');
}

$guaranteePermissionGateWired = false;
$guaranteeBreakGlassWired = false;
if (is_file($reopenPath)) {
    $reopenContent = (string)file_get_contents($reopenPath);
    $guaranteePermissionGateWired = str_contains($reopenContent, "Guard::has('reopen_guarantee')");
    $guaranteeBreakGlassWired = str_contains($reopenContent, 'BreakGlassService::authorizeAndRecord');
}

$breakGlassRuntimeEnabled = false;
if (is_file($settingsStoragePath)) {
    $settingsRaw = (string)file_get_contents($settingsStoragePath);
    $decoded = json_decode($settingsRaw, true);
    if (is_array($decoded)) {
        $breakGlassRuntimeEnabled = !empty($decoded['BREAK_GLASS_ENABLED']);
    }
}

$releasedPolicyServicePresent = is_file($mutationPolicyServicePath);
$releasedExtendGuarded = false;
if (is_file($extendApiPath)) {
    $releasedExtendGuarded = str_contains((string)file_get_contents($extendApiPath), 'GuaranteeMutationPolicyService::evaluate');
}
$releasedReduceGuarded = false;
if (is_file($reduceApiPath)) {
    $releasedReduceGuarded = str_contains((string)file_get_contents($reduceApiPath), 'GuaranteeMutationPolicyService::evaluate');
}
$releasedUpdateGuarded = false;
if (is_file($updateGuaranteeApiPath)) {
    $releasedUpdateGuarded = str_contains((string)file_get_contents($updateGuaranteeApiPath), 'GuaranteeMutationPolicyService::evaluate');
}
$releasedSaveAndNextGuarded = false;
if (is_file($saveAndNextApiPath)) {
    $releasedSaveAndNextGuarded = str_contains((string)file_get_contents($saveAndNextApiPath), 'GuaranteeMutationPolicyService::evaluate');
}
$releasedAttachmentGuarded = false;
if (is_file($uploadAttachmentApiPath)) {
    $releasedAttachmentGuarded = str_contains((string)file_get_contents($uploadAttachmentApiPath), 'GuaranteeMutationPolicyService::evaluate');
}

$apiBootstrapPath = $wbglRoot . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . '_bootstrap.php';
$apiTokenServicePath = $wbglRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'ApiTokenService.php';
$meApiPath = $wbglRoot . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'me.php';
$userPreferencesApiPath = $wbglRoot . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'user-preferences.php';
$loginPath = $wbglRoot . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'login.php';
$logoutPath = $wbglRoot . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'logout.php';
$userModelPath = $wbglRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Models' . DIRECTORY_SEPARATOR . 'User.php';
$userRepositoryPath = $wbglRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Repositories' . DIRECTORY_SEPARATOR . 'UserRepository.php';
$usersCreateApiPath = $wbglRoot . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'users' . DIRECTORY_SEPARATOR . 'create.php';
$usersUpdateApiPath = $wbglRoot . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'users' . DIRECTORY_SEPARATOR . 'update.php';
$usersListApiPath = $wbglRoot . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'users' . DIRECTORY_SEPARATOR . 'list.php';
$autoloadPath = $wbglRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'autoload.php';
$authServicePath = $wbglRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'AuthService.php';
$loginRateLimiterPath = $wbglRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'LoginRateLimiter.php';
$sessionSecurityPath = $wbglRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'SessionSecurity.php';
$securityHeadersPath = $wbglRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'SecurityHeaders.php';
$csrfGuardPath = $wbglRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'CsrfGuard.php';
$securityRuntimePath = $wbglRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'security.js';
$i18nRuntimePath = $wbglRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'i18n.js';
$shortcutsRuntimePath = $wbglRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'global-shortcuts.js';
$unifiedHeaderPath = $wbglRoot . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'unified-header.php';
$loginViewPath = $wbglRoot . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'login.php';
$usersViewPath = $wbglRoot . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'users.php';

$tokenAuthMigrationPresent = in_array('20260226_000011_add_api_tokens_and_user_language.sql', $migrationFileNames, true);
$tokenAuthTablePresent = false;
try {
    $db = Database::connect();
    $tokenAuthTablePresent = wbglLoopTableExists($db, $loopDbDriver, 'api_access_tokens');
} catch (Throwable $e) {
    $tokenAuthTablePresent = false;
}

$tokenAuthServicePresent = is_file($apiTokenServicePath);
$tokenAuthBootstrapIntegration = false;
if (is_file($apiBootstrapPath)) {
    $bootstrapContent = (string)file_get_contents($apiBootstrapPath);
    $tokenAuthBootstrapIntegration = str_contains($bootstrapContent, 'ApiTokenService::authenticateRequest');
}
$tokenAuthLoginIssueSupport = false;
if (is_file($loginPath)) {
    $loginContent = (string)file_get_contents($loginPath);
    $tokenAuthLoginIssueSupport =
        str_contains($loginContent, 'issue_token')
        && str_contains($loginContent, 'ApiTokenService::issueToken');
}
$tokenAuthLogoutRevokeSupport = false;
if (is_file($logoutPath)) {
    $logoutContent = (string)file_get_contents($logoutPath);
    $tokenAuthLogoutRevokeSupport =
        str_contains($logoutContent, 'ApiTokenService::revokeCurrentToken');
}
$tokenAuthMeEndpointPresent = is_file($meApiPath);

$userLanguageDbColumnPresent = false;
try {
    $db = Database::connect();
    $columns = wbglLoopTableColumns($db, $loopDbDriver, 'users');
    $userLanguageDbColumnPresent = in_array('preferred_language', $columns, true);
} catch (Throwable $e) {
    $userLanguageDbColumnPresent = false;
}

$userLanguageModelFieldPresent = false;
if (is_file($userModelPath)) {
    $userModelContent = (string)file_get_contents($userModelPath);
    $userLanguageModelFieldPresent =
        str_contains($userModelContent, 'preferredLanguage')
        && str_contains($userModelContent, "'preferred_language'");
}

$userLanguageRepositorySupportPresent = false;
if (is_file($userRepositoryPath)) {
    $userRepoContent = (string)file_get_contents($userRepositoryPath);
    $userLanguageRepositorySupportPresent =
        str_contains($userRepoContent, 'preferred_language')
        && str_contains($userRepoContent, 'function updatePreferredLanguage');
}

$userLanguageUsersApiSupportPresent = false;
if (is_file($usersCreateApiPath) && is_file($usersUpdateApiPath)) {
    $usersCreateApiContent = (string)file_get_contents($usersCreateApiPath);
    $usersUpdateApiContent = (string)file_get_contents($usersUpdateApiPath);
    $userLanguageUsersApiSupportPresent =
        str_contains($usersCreateApiContent, 'preferred_language')
        && str_contains($usersUpdateApiContent, 'preferred_language');
}

$userLanguageUsersListSupportPresent = false;
if (is_file($usersListApiPath)) {
    $usersListApiContent = (string)file_get_contents($usersListApiPath);
    $userLanguageUsersListSupportPresent = str_contains($usersListApiContent, 'preferred_language');
}
$userLanguagePreferencesApiPresent = is_file($userPreferencesApiPath);

$uiI18nRuntimePresent = is_file($i18nRuntimePath);
$uiI18nDynamicDirectionPresent = false;
if ($uiI18nRuntimePresent) {
    $i18nRuntimeContent = (string)file_get_contents($i18nRuntimePath);
    $uiI18nDynamicDirectionPresent =
        (
            str_contains($i18nRuntimeContent, 'WBGLDirection.onLocaleChanged')
            || (
                (
                    str_contains($i18nRuntimeContent, "setAttribute('dir'")
                    || str_contains($i18nRuntimeContent, 'setAttribute("dir"')
                )
                && (
                    str_contains($i18nRuntimeContent, "setAttribute('lang'")
                    || str_contains($i18nRuntimeContent, 'setAttribute("lang"')
                )
            )
        );
}
$uiI18nHeaderWired = false;
if (is_file($unifiedHeaderPath)) {
    $unifiedHeaderContent = (string)file_get_contents($unifiedHeaderPath);
    $uiI18nHeaderWired = str_contains($unifiedHeaderContent, 'public/js/i18n.js');
}
$uiI18nLoginWired = false;
if (is_file($loginViewPath)) {
    $loginViewContent = (string)file_get_contents($loginViewPath);
    $uiI18nLoginWired = str_contains($loginViewContent, '/public/js/i18n.js');
}
$uiI18nUsersWired = false;
if (is_file($usersViewPath)) {
    $usersViewContent = (string)file_get_contents($usersViewPath);
    $uiI18nUsersWired = str_contains($usersViewContent, '../public/js/i18n.js');
}
$uiI18nBatchPrintWired = false;
if (is_file($batchPrintViewPath)) {
    $batchPrintContent = (string)file_get_contents($batchPrintViewPath);
    $uiI18nBatchPrintWired = str_contains($batchPrintContent, 'public/js/i18n.js');
}

$globalShortcutsRuntimePresent = is_file($shortcutsRuntimePath);
$globalShortcutsHelpModalPresent = false;
if ($globalShortcutsRuntimePresent) {
    $shortcutsContent = (string)file_get_contents($shortcutsRuntimePath);
    $globalShortcutsHelpModalPresent = str_contains($shortcutsContent, 'wbgl-shortcuts-modal');
}
$globalShortcutsHeaderWired = false;
if (is_file($unifiedHeaderPath)) {
    $unifiedHeaderContent = (string)file_get_contents($unifiedHeaderPath);
    $globalShortcutsHeaderWired = str_contains($unifiedHeaderContent, 'public/js/global-shortcuts.js');
}
$globalShortcutsLoginWired = false;
if (is_file($loginViewPath)) {
    $loginViewContent = (string)file_get_contents($loginViewPath);
    $globalShortcutsLoginWired = str_contains($loginViewContent, '/public/js/global-shortcuts.js');
}
$globalShortcutsUsersWired = false;
if (is_file($usersViewPath)) {
    $usersViewContent = (string)file_get_contents($usersViewPath);
    $globalShortcutsUsersWired = str_contains($usersViewContent, '../public/js/global-shortcuts.js');
}
$globalShortcutsBatchPrintWired = false;
if (is_file($batchPrintViewPath)) {
    $batchPrintContent = (string)file_get_contents($batchPrintViewPath);
    $globalShortcutsBatchPrintWired = str_contains($batchPrintContent, 'public/js/global-shortcuts.js');
}

$directionRuntimePath = $wbglRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'direction.js';
$themeRuntimePath = $wbglRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'theme.js';
$policyRuntimePath = $wbglRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'policy.js';
$navManifestPath = $wbglRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'nav-manifest.js';
$uiRuntimePath = $wbglRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'ui-runtime.js';
$themesCssPath = $wbglRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'themes.css';
$uiBootstrapPath = $wbglRoot . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'ui-bootstrap.php';
$viewPolicyPath = $wbglRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'ViewPolicy.php';
$apiPolicyMatrixPath = $wbglRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'ApiPolicyMatrix.php';
$packageJsonPath = $wbglRoot . DIRECTORY_SEPARATOR . 'package.json';
$playwrightConfigPath = $wbglRoot . DIRECTORY_SEPARATOR . 'playwright.config.js';
$playwrightTestsDir = $wbglRoot . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'e2e';

$directionRuntimePresent = is_file($directionRuntimePath);
$themeRuntimePresent = is_file($themeRuntimePath);
$policyRuntimePresent = is_file($policyRuntimePath);
$navManifestPresent = is_file($navManifestPath);
$uiRuntimePresent = is_file($uiRuntimePath);
$themesCssPresent = is_file($themesCssPath);
$uiBootstrapPresent = is_file($uiBootstrapPath);
$viewPolicyPresent = is_file($viewPolicyPath);
$apiPolicyMatrixPresent = is_file($apiPolicyMatrixPath);

$headerContent = wbglLoopReadFile($unifiedHeaderPath);
$loginViewContent = wbglLoopReadFile($loginViewPath);
$usersViewContent = wbglLoopReadFile($usersViewPath);
$batchPrintContent = wbglLoopReadFile($batchPrintViewPath);

$directionHeaderWired = str_contains($headerContent, 'public/js/direction.js');
$directionLoginWired = str_contains($loginViewContent, '/public/js/direction.js');
$directionUsersWired = str_contains($usersViewContent, '../public/js/direction.js');
$directionBatchPrintWired = str_contains($batchPrintContent, '/public/js/direction.js');

$themeHeaderWired = str_contains($headerContent, 'public/js/theme.js');
$themeLoginWired = str_contains($loginViewContent, '/public/js/theme.js');
$themeUsersWired = str_contains($usersViewContent, '../public/js/theme.js');
$themeBatchPrintWired = str_contains($batchPrintContent, '/public/js/theme.js');

$policyHeaderWired = str_contains($headerContent, 'public/js/policy.js')
    && str_contains($headerContent, 'public/js/nav-manifest.js')
    && str_contains($headerContent, 'public/js/ui-runtime.js');
$policyLoginWired = str_contains($loginViewContent, '/public/js/policy.js')
    && str_contains($loginViewContent, '/public/js/nav-manifest.js')
    && str_contains($loginViewContent, '/public/js/ui-runtime.js');
$policyUsersWired = str_contains($usersViewContent, '../public/js/policy.js')
    && str_contains($usersViewContent, '../public/js/nav-manifest.js')
    && str_contains($usersViewContent, '../public/js/ui-runtime.js');
$policyBatchPrintWired = str_contains($batchPrintContent, '/public/js/policy.js')
    && str_contains($batchPrintContent, '/public/js/nav-manifest.js')
    && str_contains($batchPrintContent, '/public/js/ui-runtime.js');

$themeControlsPresent = str_contains($headerContent, 'data-wbgl-theme-toggle');
$directionControlsPresent = str_contains($headerContent, 'data-wbgl-direction-toggle');
$langControlsPresent = str_contains($headerContent, 'data-wbgl-lang-toggle');
$navManifestContainerPresent = str_contains($headerContent, 'data-nav-root');

$guardedViewsExpected = [
    'views/batches.php',
    'views/batch-detail.php',
    'views/batch-print.php',
    'views/confidence-demo.php',
    'views/maintenance.php',
    'views/settings.php',
    'views/statistics.php',
    'views/users.php',
];
$guardedViewsWired = 0;
foreach ($guardedViewsExpected as $rel) {
    $full = $wbglRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    $content = wbglLoopReadFile($full);
    if ($content === '') {
        continue;
    }
    $expectedNeedle = "ViewPolicy::guardView('" . basename($rel) . "')";
    if (str_contains($content, $expectedNeedle)) {
        $guardedViewsWired++;
    }
}
$viewGuardCoveragePct = count($guardedViewsExpected) > 0
    ? round(($guardedViewsWired / count($guardedViewsExpected)) * 100, 2)
    : 100.0;

$apiPolicyMatrixMissing = [];
$apiPolicyMatrixExtra = [];
if ($apiPolicyMatrixPresent) {
    $allApiRel = [];
    foreach (wbglLoopFindPhpFiles($wbglRoot . DIRECTORY_SEPARATOR . 'api') as $apiPath) {
        $rel = wbglLoopRelativePath($wbglRoot, $apiPath);
        $rel = wbglLoopNormalize($rel);
        if ($rel === 'api/_bootstrap.php') {
            continue;
        }
        $allApiRel[] = $rel;
    }
    sort($allApiRel);
    $allApiRel = array_values(array_unique($allApiRel));

    $matrixContent = wbglLoopReadFile($apiPolicyMatrixPath);
    $matrixMatches = [];
    preg_match_all("/'api\\/[^']+\\.php'/", $matrixContent, $matrixMatches);
    $matrixEndpoints = array_map(
        static fn(string $item): string => trim($item, "'"),
        $matrixMatches[0] ?? []
    );
    sort($matrixEndpoints);
    $matrixEndpoints = array_values(array_unique($matrixEndpoints));

    $apiPolicyMatrixMissing = array_values(array_diff($allApiRel, $matrixEndpoints));
    $apiPolicyMatrixExtra = array_values(array_diff($matrixEndpoints, $allApiRel));
}
$apiPolicyMatrixParity = empty($apiPolicyMatrixMissing) && empty($apiPolicyMatrixExtra);
$observabilityMetricsApiPath = $wbglRoot . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'metrics.php';
$observabilityMetricsServicePath = $wbglRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Services' . DIRECTORY_SEPARATOR . 'OperationalMetricsService.php';
$observabilityMetricsApiPresent = is_file($observabilityMetricsApiPath);
$observabilityMetricsServicePresent = is_file($observabilityMetricsServicePath);
$observabilityMetricsApiPermissionGuarded = false;
if ($observabilityMetricsApiPresent) {
    $metricsApiContent = wbglLoopReadFile($observabilityMetricsApiPath);
    $observabilityMetricsApiPermissionGuarded = str_contains($metricsApiContent, "wbgl_api_require_permission('manage_users')");
}
$observabilityMetricsApiPolicyMapped = false;
if ($apiPolicyMatrixPresent) {
    $matrixContent = wbglLoopReadFile($apiPolicyMatrixPath);
    $observabilityMetricsApiPolicyMapped =
        str_contains($matrixContent, "'api/metrics.php'")
        && str_contains($matrixContent, "'permission' => 'manage_users'");
}
$observabilityRequestIdWired = false;
 $observabilityBootstrapPath = $wbglRoot . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . '_bootstrap.php';
if (is_file($observabilityBootstrapPath)) {
    $apiBootstrapContent = wbglLoopReadFile($observabilityBootstrapPath);
    $observabilityRequestIdWired =
        str_contains($apiBootstrapContent, 'function wbgl_api_request_id')
        && str_contains($apiBootstrapContent, 'X-Request-Id');
}

$dbDriverStatusCommandPath = $wbglRoot . DIRECTORY_SEPARATOR . 'maint' . DIRECTORY_SEPARATOR . 'db-driver-status.php';
$dbCutoverCheckCommandPath = $wbglRoot . DIRECTORY_SEPARATOR . 'maint' . DIRECTORY_SEPARATOR . 'db-cutover-check.php';
$dbBackupCommandPath = $wbglRoot . DIRECTORY_SEPARATOR . 'maint' . DIRECTORY_SEPARATOR . 'backup-db.php';
$pgActivationRehearsalPath = $wbglRoot . DIRECTORY_SEPARATOR . 'maint' . DIRECTORY_SEPARATOR . 'pgsql-activation-rehearsal.php';
$dbMigratePath = $wbglRoot . DIRECTORY_SEPARATOR . 'maint' . DIRECTORY_SEPARATOR . 'migrate.php';
$dbMigrationStatusPath = $wbglRoot . DIRECTORY_SEPARATOR . 'maint' . DIRECTORY_SEPARATOR . 'migration-status.php';
$dbCutoverRunbookRepoPath = $repoDocsRoot . DIRECTORY_SEPARATOR . 'DB_CUTOVER_RUNBOOK.md';
$dbBackupRestoreRunbookRepoPath = $repoDocsRoot . DIRECTORY_SEPARATOR . 'BACKUP_RESTORE_RUNBOOK.md';
$pgActivationRunbookRepoPath = $repoDocsRoot . DIRECTORY_SEPARATOR . 'PGSQL_ACTIVATION_RUNBOOK.md';
$dbCutoverRunbookWorkspacePath = $docsRoot . DIRECTORY_SEPARATOR . 'DB_CUTOVER_RUNBOOK.md';
$dbBackupRestoreRunbookWorkspacePath = $docsRoot . DIRECTORY_SEPARATOR . 'BACKUP_RESTORE_RUNBOOK.md';
$pgActivationRunbookWorkspacePath = $docsRoot . DIRECTORY_SEPARATOR . 'PGSQL_ACTIVATION_RUNBOOK.md';
$dbBackupsDir = $wbglRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'backups';
$dbBackupArtifacts = glob($dbBackupsDir . DIRECTORY_SEPARATOR . '*.sql') ?: [];
$dbBackupArtifactsCount = count($dbBackupArtifacts);
$dbSchemaMigrationsTablePresent = false;
try {
    $db = Database::connect();
    $dbSchemaMigrationsTablePresent = wbglLoopTableExists($db, $loopDbDriver, 'schema_migrations');
} catch (Throwable $e) {
    $dbSchemaMigrationsTablePresent = false;
}
$dbMigrationToolingDriverAware = false;
if (is_file($dbMigratePath) && is_file($dbMigrationStatusPath)) {
    $migrateContent = wbglLoopReadFile($dbMigratePath);
    $migrationStatusContent = wbglLoopReadFile($dbMigrationStatusPath);
    $dbMigrationToolingDriverAware =
        str_contains($migrateContent, 'Database::currentDriver')
        && str_contains($migrationStatusContent, 'Database::currentDriver')
        && str_contains($migrateContent, 'wbglMigrationTableSql(')
        && str_contains($migrationStatusContent, 'wbglMigrationStatusTableSql(');
}
$dbCutoverScriptsReady =
    is_file($dbDriverStatusCommandPath)
    && is_file($dbCutoverCheckCommandPath)
    && is_file($dbBackupCommandPath);
$dbCutoverRunbooksReady =
    (is_file($dbCutoverRunbookRepoPath) || is_file($dbCutoverRunbookWorkspacePath))
    && (is_file($dbBackupRestoreRunbookRepoPath) || is_file($dbBackupRestoreRunbookWorkspacePath));
$dbBackupsReady = is_dir($dbBackupsDir) && $dbBackupArtifactsCount > 0;
$dbCutoverReady =
    $dbCutoverScriptsReady
    && $dbCutoverRunbooksReady
    && $dbSchemaMigrationsTablePresent
    && $dbMigrationToolingDriverAware
    && $dbBackupsReady;

$migrationPortabilityPath = $wbglRoot . DIRECTORY_SEPARATOR . 'maint' . DIRECTORY_SEPARATOR . 'check-migration-portability.php';
$dbCutoverFingerprintPath = $wbglRoot . DIRECTORY_SEPARATOR . 'maint' . DIRECTORY_SEPARATOR . 'db-cutover-fingerprint.php';
$cutoverReportDir = $wbglRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'cutover';
$portabilityReportPath = $cutoverReportDir . DIRECTORY_SEPARATOR . 'migration_portability_report.json';
$pgActivationRehearsalLatestPath = $cutoverReportDir . DIRECTORY_SEPARATOR . 'pgsql_activation_rehearsal_latest.json';
$fingerprintLatestPath = $cutoverReportDir . DIRECTORY_SEPARATOR . 'fingerprint_' . $loopDbDriver . '_latest.json';
$portabilityReport = wbglLoopReadJson($portabilityReportPath);
$portabilitySummary = is_array($portabilityReport['summary'] ?? null) ? $portabilityReport['summary'] : [];
$portabilityHighBlockers = (int)($portabilitySummary['high_blockers'] ?? 0);
$pgActivationReport = wbglLoopReadJson($pgActivationRehearsalLatestPath);
$pgActivationSummary = is_array($pgActivationReport['summary'] ?? null) ? $pgActivationReport['summary'] : [];
$pgActivationRehearsalReady = (bool)($pgActivationSummary['ready_for_pg_activation'] ?? false);

$workflowsDir = $wbglRoot . DIRECTORY_SEPARATOR . '.github' . DIRECTORY_SEPARATOR . 'workflows';
$changeGateWorkflowPath = $workflowsDir . DIRECTORY_SEPARATOR . 'change-gate.yml';
$ciWorkflowPath = $workflowsDir . DIRECTORY_SEPARATOR . 'ci.yml';
$securityWorkflowPath = $workflowsDir . DIRECTORY_SEPARATOR . 'security.yml';
$releaseWorkflowPath = $workflowsDir . DIRECTORY_SEPARATOR . 'release-readiness.yml';
$enterpriseWorkflowsReady =
    is_file($changeGateWorkflowPath)
    && is_file($ciWorkflowPath)
    && is_file($securityWorkflowPath)
    && is_file($releaseWorkflowPath);

$governancePolicyDocPath = $docsRoot . DIRECTORY_SEPARATOR . 'GOVERNANCE_POLICY.md';
$sodMatrixDocPath = $docsRoot . DIRECTORY_SEPARATOR . 'SOD_MATRIX.md';
$breakGlassRunbookPath = $docsRoot . DIRECTORY_SEPARATOR . 'BREAK_GLASS_RUNBOOK.md';

$undoSelfApprovalGuarded = false;
if (is_file($undoServiceFile)) {
    $undoServiceContent = wbglLoopReadFile($undoServiceFile);
    $undoSelfApprovalGuarded = str_contains($undoServiceContent, 'Self-approval is not allowed');
}

$breakGlassPermissionGuarded = false;
if (is_file($breakGlassServicePath)) {
    $breakGlassContent = wbglLoopReadFile($breakGlassServicePath);
    $breakGlassPermissionGuarded = str_contains($breakGlassContent, "Guard::has('break_glass_override')");
}

$breakGlassTicketPolicyPresent = false;
if (is_file($settingsPath)) {
    $settingsContent = wbglLoopReadFile($settingsPath);
    $breakGlassTicketPolicyPresent = str_contains($settingsContent, 'BREAK_GLASS_REQUIRE_TICKET');
}

$sodDocsPresent =
    is_file($governancePolicyDocPath)
    && is_file($sodMatrixDocPath)
    && is_file($breakGlassRunbookPath);

$sodComplianceReady =
    $sodDocsPresent
    && $undoSelfApprovalGuarded
    && $breakGlassPermissionGuarded
    && $breakGlassTicketPolicyPresent
    && $batchAuditTablePresent
    && $breakGlassTablePresent
    && $reopenGovernanceMigrationPresent;

$i18nUsedKeys = [];
$i18nUsageTargets = [
    $indexPath,
    $unifiedHeaderPath,
    $loginViewPath,
];
foreach ($i18nUsageTargets as $targetPath) {
    $content = wbglLoopReadFile($targetPath);
    if ($content === '') {
        continue;
    }
    $i18nUsedKeys = array_merge($i18nUsedKeys, wbglLoopExtractI18nKeys($content));
}
$i18nUsedKeys = array_values(array_unique($i18nUsedKeys));

$localeArCommonPath = $wbglRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'locales' . DIRECTORY_SEPARATOR . 'ar' . DIRECTORY_SEPARATOR . 'common.json';
$localeEnCommonPath = $wbglRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'locales' . DIRECTORY_SEPARATOR . 'en' . DIRECTORY_SEPARATOR . 'common.json';
$localeArCommon = wbglLoopReadJson($localeArCommonPath);
$localeEnCommon = wbglLoopReadJson($localeEnCommonPath);
$commonLocaleKeys = array_values(array_unique(array_merge(array_keys($localeArCommon), array_keys($localeEnCommon))));
$missingI18nKeys = array_values(array_diff($i18nUsedKeys, $commonLocaleKeys));
$translationCoveragePct = count($i18nUsedKeys) > 0
    ? round(((count($i18nUsedKeys) - count($missingI18nKeys)) / count($i18nUsedKeys)) * 100, 2)
    : 100.0;

$uiAuthorizeTagCount = 0;
$uiAuthorizeScanFiles = [
    $indexPath,
    $unifiedHeaderPath,
    $usersViewPath,
    $settingsViewPath,
    $batchDetailViewPath,
];
foreach ($uiAuthorizeScanFiles as $scanPath) {
    $content = wbglLoopReadFile($scanPath);
    if ($content === '') {
        continue;
    }
    $uiAuthorizeTagCount += wbglLoopCountPattern($content, '/data-authorize-(permission|resource|action)=/');
}

$cssVarRefs = 0;
$cssHexRefs = 0;
$cssFiles = glob($wbglRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . '*.css') ?: [];
foreach ($cssFiles as $cssFile) {
    $cssContent = wbglLoopReadFile($cssFile);
    if ($cssContent === '') {
        continue;
    }
    $cssVarRefs += wbglLoopCountPattern($cssContent, '/var\\(--/');
    $cssHexRefs += wbglLoopCountPattern($cssContent, '/#[0-9a-fA-F]{3,8}/');
}
$themeTokenCoveragePct = ($cssVarRefs + $cssHexRefs) > 0
    ? round(($cssVarRefs / ($cssVarRefs + $cssHexRefs)) * 100, 2)
    : 0.0;

$rtlKeyScreens = [
    'index.php',
    'views/login.php',
    'views/settings.php',
    'views/batches.php',
    'views/batch-detail.php',
    'views/batch-print.php',
    'views/statistics.php',
    'views/users.php',
    'views/maintenance.php',
];
$rtlScreensWired = 0;
foreach ($rtlKeyScreens as $screenRel) {
    $full = $wbglRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $screenRel);
    $content = wbglLoopReadFile($full);
    if ($content === '') {
        continue;
    }
    $hasDirectionRuntime = str_contains($content, 'public/js/direction.js') || str_contains($content, '/public/js/direction.js');
    $hasUnifiedHeader = str_contains($content, "partials/unified-header.php");
    if ($hasDirectionRuntime || $hasUnifiedHeader) {
        $rtlScreensWired++;
    }
}
$rtlReadinessPct = count($rtlKeyScreens) > 0
    ? round(($rtlScreensWired / count($rtlKeyScreens)) * 100, 2)
    : 100.0;

$playwrightPackagePresent = is_file($packageJsonPath);
$playwrightConfigPresent = is_file($playwrightConfigPath);
$playwrightTestsCount = 0;
if (is_dir($playwrightTestsDir)) {
    $specs = glob($playwrightTestsDir . DIRECTORY_SEPARATOR . '*.spec.*') ?: [];
    $playwrightTestsCount = count($specs);
}
$playwrightScriptPresent = false;
$playwrightDependencyPresent = false;
if ($playwrightPackagePresent) {
    $packageRaw = wbglLoopReadFile($packageJsonPath);
    $playwrightScriptPresent = str_contains($packageRaw, '"test:e2e"');
    $playwrightDependencyPresent = str_contains($packageRaw, '@playwright/test');
}
$playwrightReady = $playwrightPackagePresent
    && $playwrightConfigPresent
    && $playwrightScriptPresent
    && $playwrightDependencyPresent
    && $playwrightTestsCount > 0;

$sessionSecurityServicePresent = is_file($sessionSecurityPath);
$securityHeadersServicePresent = is_file($securityHeadersPath);
$csrfGuardServicePresent = is_file($csrfGuardPath);

$autoloadSessionHardeningWired = false;
$autoloadSecurityHeadersWired = false;
$autoloadNonApiCsrfGuardWired = false;
if (is_file($autoloadPath)) {
    $autoloadContent = (string)file_get_contents($autoloadPath);
    $autoloadSessionHardeningWired =
        str_contains($autoloadContent, 'SessionSecurity::configureSessionCookieOptions')
        && str_contains($autoloadContent, 'SessionSecurity::startSessionIfNeeded')
        && str_contains($autoloadContent, 'SessionSecurity::enforceTimeouts');
    $autoloadSecurityHeadersWired = str_contains($autoloadContent, 'SecurityHeaders::apply');
    $autoloadNonApiCsrfGuardWired =
        str_contains($autoloadContent, 'CsrfGuard::isMutatingMethod')
        && str_contains($autoloadContent, '!$isApiPath')
        && str_contains($autoloadContent, 'CsrfGuard::validateRequest');
}

$apiBootstrapCsrfGuardWired = false;
if (is_file($apiBootstrapPath)) {
    $apiBootstrapContent = (string)file_get_contents($apiBootstrapPath);
    $apiBootstrapCsrfGuardWired =
        str_contains($apiBootstrapContent, 'function wbgl_api_require_csrf')
        && str_contains($apiBootstrapContent, 'CsrfGuard::isMutatingMethod')
        && str_contains($apiBootstrapContent, 'wbgl_api_require_csrf();');
}

$loginEndpointCsrfGuardWired = false;
if (is_file($loginPath)) {
    $loginApiContent = (string)file_get_contents($loginPath);
    $loginEndpointCsrfGuardWired = str_contains($loginApiContent, 'CsrfGuard::validateRequest');
}

$frontendSecurityRuntimePresent = is_file($securityRuntimePath);
$frontendSecurityRuntimeWired = false;
if (is_file($unifiedHeaderPath) && is_file($loginViewPath) && is_file($usersViewPath) && is_file($batchPrintViewPath)) {
    $unifiedHeaderContent = (string)file_get_contents($unifiedHeaderPath);
    $loginViewContent = (string)file_get_contents($loginViewPath);
    $usersViewContent = (string)file_get_contents($usersViewPath);
    $batchPrintContent = (string)file_get_contents($batchPrintViewPath);
    $frontendSecurityRuntimeWired =
        str_contains($unifiedHeaderContent, 'public/js/security.js')
        && str_contains($loginViewContent, '/public/js/security.js')
        && str_contains($usersViewContent, '../public/js/security.js')
        && str_contains($batchPrintContent, '/public/js/security.js');
}

$logoutHardDestroyWired = false;
if (is_file($authServicePath)) {
    $authServiceContent = (string)file_get_contents($authServicePath);
    $logoutHardDestroyWired =
        str_contains($authServiceContent, 'CsrfGuard::clearToken();')
        && str_contains($authServiceContent, 'SessionSecurity::invalidateSession();');
}

$rateLimitUserAgentFingerprintWired = false;
if (is_file($loginRateLimiterPath)) {
    $loginRateLimiterContent = (string)file_get_contents($loginRateLimiterPath);
    $rateLimitUserAgentFingerprintWired =
        str_contains($loginRateLimiterContent, 'clientUserAgent')
        && str_contains($loginRateLimiterContent, "self::clientIp() . '|' . self::clientUserAgent()");
}

$a11yCssPath = $wbglRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'a11y.css';
$a11yCssPresent = is_file($a11yCssPath);
$a11yCssContent = $a11yCssPresent ? (string)file_get_contents($a11yCssPath) : '';
$a11yFocusVisibleRulePresent = $a11yCssPresent && str_contains($a11yCssContent, ':focus-visible');
$a11ySrOnlyUtilityPresent = $a11yCssPresent && str_contains($a11yCssContent, '.sr-only');

$a11yIndexLinked = false;
if (is_file($indexPath)) {
    $indexContent = (string)file_get_contents($indexPath);
    $a11yIndexLinked = str_contains($indexContent, 'public/css/a11y.css');
}

$a11yBatchDetailLinked = false;
$a11yBatchDetailModalSemantics = false;
$a11yBatchDetailIconLabels = false;
if (is_file($batchDetailViewPath)) {
    $batchDetailContent = (string)file_get_contents($batchDetailViewPath);
    $a11yBatchDetailLinked = str_contains($batchDetailContent, '../public/css/a11y.css');
    $a11yBatchDetailModalSemantics =
        str_contains($batchDetailContent, 'role="dialog"')
        && str_contains($batchDetailContent, 'aria-hidden="true"')
        && str_contains($batchDetailContent, 'Modal.bindA11y');
    $a11yBatchDetailIconLabels =
        str_contains($batchDetailContent, 'aria-label="تعديل اسم وملاحظات الدفعة"')
        && str_contains($batchDetailContent, 'aria-label="إغلاق الدفعة"')
        && str_contains($batchDetailContent, 'aria-label="عرض تفاصيل الضمان');
}

$a11ySettingsLinked = false;
$a11ySettingsTabSemantics = false;
$a11ySettingsModalSemantics = false;
if (is_file($settingsViewPath)) {
    $settingsViewContent = (string)file_get_contents($settingsViewPath);
    $a11ySettingsLinked = str_contains($settingsViewContent, '../public/css/a11y.css');
    $a11ySettingsTabSemantics =
        str_contains($settingsViewContent, 'role="tablist"')
        && str_contains($settingsViewContent, 'role="tab"')
        && str_contains($settingsViewContent, 'role="tabpanel"')
        && str_contains($settingsViewContent, "tabButton.setAttribute('aria-selected'");
    $a11ySettingsModalSemantics =
        str_contains($settingsViewContent, 'aria-modal="true"')
        && str_contains($settingsViewContent, "modal.setAttribute('aria-hidden', 'false')")
        && str_contains($settingsViewContent, "if (event.key === 'Escape')");
}

$testFiles = wbglLoopFindPhpFiles($wbglRoot . DIRECTORY_SEPARATOR . 'tests');
$unitFiles = array_values(array_filter($testFiles, static fn(string $f): bool => str_contains(wbglLoopNormalize($f), '/tests/Unit/')));
$integrationFiles = array_values(array_filter($testFiles, static fn(string $f): bool => str_contains(wbglLoopNormalize($f), '/tests/Integration/')));
$integrationFlowSuitePath = $wbglRoot . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Integration' . DIRECTORY_SEPARATOR . 'EnterpriseApiFlowsTest.php';
$integrationFlowSuitePresent = is_file($integrationFlowSuitePath);

$gateAPassed =
    $sensitiveUnguarded === 0
    && $sessionSecurityServicePresent
    && $securityHeadersServicePresent
    && $csrfGuardServicePresent
    && $autoloadSessionHardeningWired
    && $autoloadSecurityHeadersWired
    && is_file($changeGateWorkflowPath);
$gateBPassed = $integrationFlowSuitePresent && count($integrationFiles) > 0 && $playwrightReady;
$gateCPassed =
    $observabilityMetricsApiPresent
    && $observabilityMetricsServicePresent
    && $observabilityMetricsApiPermissionGuarded
    && $observabilityMetricsApiPolicyMapped
    && $observabilityRequestIdWired;
$gateDRehearsalPassed =
    $dbCutoverReady
    && is_file($migrationPortabilityPath)
    && is_file($dbCutoverFingerprintPath)
    && $portabilityHighBlockers === 0;
$gateDPgRehearsalReportPassed = $pgActivationRehearsalReady;
$gateDPgActivationPassed = $gateDRehearsalPassed && $loopDbDriver === 'pgsql' && $gateDPgRehearsalReportPassed;
$gateEPassed = $sodComplianceReady;

$nextBatch = [];
$nextTargets = array_slice(array_merge($sensitiveUnguardedList, $unguardedList), 0, 8);
$taskCounter = 1;
foreach ($nextTargets as $target) {
    $nextBatch[] = [
        'task_id' => 'LOOP-TASK-' . str_pad((string)$taskCounter, 3, '0', STR_PAD_LEFT),
        'title' => 'Protect endpoint with centralized API bootstrap + guard',
        'target' => $target,
        'gap_refs' => ['A1', 'A7', 'A9'],
    ];
    $taskCounter++;
}

if (empty($nextBatch)) {
    $securityChecklist = [
        'security_support_services' => !$sessionSecurityServicePresent || !$securityHeadersServicePresent || !$csrfGuardServicePresent,
        'security_autoload_session_headers' => !$autoloadSessionHardeningWired || !$autoloadSecurityHeadersWired,
        'security_autoload_non_api_csrf' => !$autoloadNonApiCsrfGuardWired,
        'security_api_bootstrap_csrf' => !$apiBootstrapCsrfGuardWired,
        'security_login_csrf' => !$loginEndpointCsrfGuardWired,
        'security_frontend_runtime' => !$frontendSecurityRuntimePresent,
        'security_frontend_wiring' => !$frontendSecurityRuntimeWired,
        'security_logout_hard_destroy' => !$logoutHardDestroyWired,
        'security_login_rate_fingerprint' => !$rateLimitUserAgentFingerprintWired,
    ];

    foreach ($securityChecklist as $taskKey => $needed) {
        if (!$needed) {
            continue;
        }

        $title = match ($taskKey) {
            'security_support_services' => 'Add security support services (SessionSecurity + SecurityHeaders + CsrfGuard)',
            'security_autoload_session_headers' => 'Wire session hardening and security headers in autoload bootstrap',
            'security_autoload_non_api_csrf' => 'Enforce CSRF for non-API mutating web requests',
            'security_api_bootstrap_csrf' => 'Enforce CSRF centrally for mutating API requests',
            'security_login_csrf' => 'Require CSRF token validation in login endpoint',
            'security_frontend_runtime' => 'Add frontend security runtime for global CSRF fetch injection',
            'security_frontend_wiring' => 'Wire frontend security runtime across key UI shells',
            'security_logout_hard_destroy' => 'Upgrade logout flow to hard session destroy',
            'security_login_rate_fingerprint' => 'Expand login rate limit key with username+IP+user-agent fingerprint',
            default => 'Complete security baseline hardening task',
        };

        $target = match ($taskKey) {
            'security_support_services' => 'app/Support/SessionSecurity.php',
            'security_autoload_session_headers' => 'app/Support/autoload.php',
            'security_autoload_non_api_csrf' => 'app/Support/autoload.php',
            'security_api_bootstrap_csrf' => 'api/_bootstrap.php',
            'security_login_csrf' => 'api/login.php',
            'security_frontend_runtime' => 'public/js/security.js',
            'security_frontend_wiring' => 'partials/unified-header.php',
            'security_logout_hard_destroy' => 'app/Support/AuthService.php',
            'security_login_rate_fingerprint' => 'app/Support/LoginRateLimiter.php',
            default => 'Security baseline',
        };

        $nextBatch[] = [
            'task_id' => 'LOOP-TASK-' . str_pad((string)$taskCounter, 3, '0', STR_PAD_LEFT),
            'title' => $title,
            'target' => $target,
            'gap_refs' => ['Enterprise-W1', 'A1', 'A2', 'Governance'],
        ];
        $taskCounter++;
    }
}

if (empty($nextBatch)) {
    $observabilityChecklist = [
        'observability_metrics_api_add' => !$observabilityMetricsApiPresent,
        'observability_metrics_service_add' => !$observabilityMetricsServicePresent,
        'observability_metrics_guard_wire' => !$observabilityMetricsApiPermissionGuarded,
        'observability_matrix_sync' => !$observabilityMetricsApiPolicyMapped,
        'observability_request_id_wire' => !$observabilityRequestIdWired,
    ];

    foreach ($observabilityChecklist as $taskKey => $needed) {
        if (!$needed) {
            continue;
        }

        $title = match ($taskKey) {
            'observability_metrics_api_add' => 'Add guarded operational metrics API endpoint',
            'observability_metrics_service_add' => 'Add operational metrics aggregation service',
            'observability_metrics_guard_wire' => 'Enforce manage_users guard on metrics endpoint',
            'observability_matrix_sync' => 'Map metrics endpoint in API policy matrix',
            'observability_request_id_wire' => 'Wire request-id generation and response header in API bootstrap',
            default => 'Complete observability baseline task',
        };

        $target = match ($taskKey) {
            'observability_metrics_api_add' => 'api/metrics.php',
            'observability_metrics_service_add' => 'app/Services/OperationalMetricsService.php',
            'observability_metrics_guard_wire' => 'api/metrics.php',
            'observability_matrix_sync' => 'app/Support/ApiPolicyMatrix.php',
            'observability_request_id_wire' => 'api/_bootstrap.php',
            default => 'Observability',
        };

        $nextBatch[] = [
            'task_id' => 'LOOP-TASK-' . str_pad((string)$taskCounter, 3, '0', STR_PAD_LEFT),
            'title' => $title,
            'target' => $target,
            'gap_refs' => ['Enterprise-W3', 'Observability', 'Governance'],
        ];
        $taskCounter++;
    }
}

if (empty($nextBatch)) {
    $dbCutoverChecklist = [
        'db_cutover_driver_status_command_add' => !is_file($dbDriverStatusCommandPath),
        'db_cutover_check_command_add' => !is_file($dbCutoverCheckCommandPath),
        'db_cutover_backup_command_add' => !is_file($dbBackupCommandPath),
        'db_cutover_portability_command_add' => !is_file($migrationPortabilityPath),
        'db_cutover_fingerprint_command_add' => !is_file($dbCutoverFingerprintPath),
        'db_cutover_pg_rehearsal_command_add' => !is_file($pgActivationRehearsalPath),
        'db_cutover_runbook_add' => !(is_file($dbCutoverRunbookRepoPath) || is_file($dbCutoverRunbookWorkspacePath)),
        'db_backup_restore_runbook_add' => !(is_file($dbBackupRestoreRunbookRepoPath) || is_file($dbBackupRestoreRunbookWorkspacePath)),
        'db_pg_activation_runbook_add' => !(is_file($pgActivationRunbookRepoPath) || is_file($pgActivationRunbookWorkspacePath)),
        'db_cutover_backup_seed' => !$dbBackupsReady,
        'db_pg_rehearsal_report_seed' => !is_file($pgActivationRehearsalLatestPath),
        'db_cutover_migration_driver_awareness' => !$dbMigrationToolingDriverAware,
    ];

    foreach ($dbCutoverChecklist as $taskKey => $needed) {
        if (!$needed) {
            continue;
        }

        $title = match ($taskKey) {
            'db_cutover_driver_status_command_add' => 'Add DB driver status command (effective config + connectivity)',
            'db_cutover_check_command_add' => 'Add DB cutover readiness command',
            'db_cutover_backup_command_add' => 'Add DB backup command',
            'db_cutover_portability_command_add' => 'Add migration portability checker (SQLite -> PostgreSQL)',
            'db_cutover_fingerprint_command_add' => 'Add DB cutover fingerprint command (rowcount/schema hash)',
            'db_cutover_pg_rehearsal_command_add' => 'Add PostgreSQL activation rehearsal command',
            'db_cutover_runbook_add' => 'Document DB cutover runbook',
            'db_backup_restore_runbook_add' => 'Document backup/restore runbook',
            'db_pg_activation_runbook_add' => 'Document PostgreSQL activation runbook',
            'db_cutover_backup_seed' => 'Seed PostgreSQL backup artifact baseline in storage/database/backups',
            'db_pg_rehearsal_report_seed' => 'Generate baseline PostgreSQL activation rehearsal report',
            'db_cutover_migration_driver_awareness' => 'Ensure migration tooling remains PostgreSQL-ready',
            default => 'Complete DB cutover baseline task',
        };

        $target = match ($taskKey) {
            'db_cutover_driver_status_command_add' => 'maint/db-driver-status.php',
            'db_cutover_check_command_add' => 'maint/db-cutover-check.php',
            'db_cutover_backup_command_add' => 'maint/backup-db.php',
            'db_cutover_portability_command_add' => 'maint/check-migration-portability.php',
            'db_cutover_fingerprint_command_add' => 'maint/db-cutover-fingerprint.php',
            'db_cutover_pg_rehearsal_command_add' => 'maint/pgsql-activation-rehearsal.php',
            'db_cutover_runbook_add' => 'docs/DB_CUTOVER_RUNBOOK.md',
            'db_backup_restore_runbook_add' => 'docs/BACKUP_RESTORE_RUNBOOK.md',
            'db_pg_activation_runbook_add' => 'docs/PGSQL_ACTIVATION_RUNBOOK.md',
            'db_cutover_backup_seed' => 'storage/database/backups/*.sql',
            'db_pg_rehearsal_report_seed' => 'storage/database/cutover/pgsql_activation_rehearsal_latest.json',
            'db_cutover_migration_driver_awareness' => 'maint/migrate.php',
            default => 'DB Cutover',
        };

        $nextBatch[] = [
            'task_id' => 'LOOP-TASK-' . str_pad((string)$taskCounter, 3, '0', STR_PAD_LEFT),
            'title' => $title,
            'target' => $target,
            'gap_refs' => ['Enterprise-W4', 'DB', 'Resilience'],
        ];
        $taskCounter++;
    }
}

if (empty($nextBatch)) {
    $ciChecklist = [
        'ci_workflow_add' => !is_file($ciWorkflowPath),
        'security_workflow_add' => !is_file($securityWorkflowPath),
        'release_workflow_add' => !is_file($releaseWorkflowPath),
    ];

    foreach ($ciChecklist as $taskKey => $needed) {
        if (!$needed) {
            continue;
        }

        $title = match ($taskKey) {
            'ci_workflow_add' => 'Add CI workflow for unit/integration/e2e execution',
            'security_workflow_add' => 'Add security workflow for dependency audits',
            'release_workflow_add' => 'Add release-readiness workflow for execution loop and DB checks',
            default => 'Complete enterprise CI/CD workflow baseline',
        };

        $target = match ($taskKey) {
            'ci_workflow_add' => '.github/workflows/ci.yml',
            'security_workflow_add' => '.github/workflows/security.yml',
            'release_workflow_add' => '.github/workflows/release-readiness.yml',
            default => '.github/workflows',
        };

        $nextBatch[] = [
            'task_id' => 'LOOP-TASK-' . str_pad((string)$taskCounter, 3, '0', STR_PAD_LEFT),
            'title' => $title,
            'target' => $target,
            'gap_refs' => ['Enterprise-W4', 'Enterprise-W5', 'CI/CD'],
        ];
        $taskCounter++;
    }
}

if (empty($nextBatch)) {
    $sodChecklist = [
        'sod_governance_doc_add' => !is_file($governancePolicyDocPath),
        'sod_matrix_doc_add' => !is_file($sodMatrixDocPath),
        'sod_break_glass_doc_add' => !is_file($breakGlassRunbookPath),
        'sod_self_approval_guard' => !$undoSelfApprovalGuarded,
        'sod_break_glass_permission_guard' => !$breakGlassPermissionGuarded,
    ];

    foreach ($sodChecklist as $taskKey => $needed) {
        if (!$needed) {
            continue;
        }

        $title = match ($taskKey) {
            'sod_governance_doc_add' => 'Add governance policy documentation',
            'sod_matrix_doc_add' => 'Add SoD role matrix documentation',
            'sod_break_glass_doc_add' => 'Add break-glass runbook documentation',
            'sod_self_approval_guard' => 'Enforce self-approval prevention for undo workflows',
            'sod_break_glass_permission_guard' => 'Enforce break-glass permission gate in service layer',
            default => 'Complete SoD/compliance baseline task',
        };

        $target = match ($taskKey) {
            'sod_governance_doc_add' => 'Docs/GOVERNANCE_POLICY.md',
            'sod_matrix_doc_add' => 'Docs/SOD_MATRIX.md',
            'sod_break_glass_doc_add' => 'Docs/BREAK_GLASS_RUNBOOK.md',
            'sod_self_approval_guard' => 'app/Services/UndoRequestService.php',
            'sod_break_glass_permission_guard' => 'app/Services/BreakGlassService.php',
            default => 'SoD',
        };

        $nextBatch[] = [
            'task_id' => 'LOOP-TASK-' . str_pad((string)$taskCounter, 3, '0', STR_PAD_LEFT),
            'title' => $title,
            'target' => $target,
            'gap_refs' => ['Enterprise-W5', 'Governance', 'Compliance'],
        ];
        $taskCounter++;
    }
}

if (empty($nextBatch)) {
    $historyChecklist = [
        'history_hybrid_migration_apply' => !$historyHybridColumnsPresent,
        'history_hybrid_service_integration' => !$historyHybridServicePresent || !$historyRecorderIntegration,
        'history_hybrid_reader_integration' => !$historySnapshotReaderIntegration,
        'history_event_catalog_extraction' => !$historyEventCatalogExtracted,
    ];

    foreach ($historyChecklist as $taskKey => $needed) {
        if (!$needed) {
            continue;
        }
        $title = match ($taskKey) {
            'history_hybrid_migration_apply' => 'Apply History V2 hybrid migration to DB',
            'history_hybrid_service_integration' => 'Integrate hybrid ledger write path into TimelineRecorder',
            'history_hybrid_reader_integration' => 'Integrate hybrid snapshot reconstruction in history API',
            'history_event_catalog_extraction' => 'Extract timeline label/icon logic to catalog service',
            default => 'Complete History V2 hybrid foundation task',
        };
        $target = match ($taskKey) {
            'history_hybrid_migration_apply' => 'database/migrations/20260226_000005_add_hybrid_history_columns.sql',
            'history_hybrid_service_integration' => 'app/Services/TimelineRecorder.php',
            'history_hybrid_reader_integration' => 'api/get-history-snapshot.php',
            'history_event_catalog_extraction' => 'app/Services/TimelineEventCatalog.php',
            default => 'History V2',
        };
        $nextBatch[] = [
            'task_id' => 'LOOP-TASK-' . str_pad((string)$taskCounter, 3, '0', STR_PAD_LEFT),
            'title' => $title,
            'target' => $target,
            'gap_refs' => ['A8'],
        ];
        $taskCounter++;
    }
}

if (empty($nextBatch)) {
    $matchingChecklist = [
        'matching_overrides_migration_apply' => !$matchingOverridesTablePresent,
        'matching_overrides_api_add' => !$matchingOverridesApiPresent,
        'matching_overrides_service_add' => !$matchingOverridesServicePresent || !$matchingOverridesRepoCrudPresent,
        'matching_overrides_authority_wire' => !$matchingOverridesAuthorityWired,
        'matching_overrides_bulk_io_add' => !$matchingOverridesExportPresent || !$matchingOverridesImportPresent,
        'matching_overrides_settings_tab_wire' => !$matchingOverridesSettingsTabPresent || !$matchingOverridesSettingsLoaderPresent,
    ];

    foreach ($matchingChecklist as $taskKey => $needed) {
        if (!$needed) {
            continue;
        }
        $title = match ($taskKey) {
            'matching_overrides_migration_apply' => 'Apply supplier overrides migration to DB',
            'matching_overrides_api_add' => 'Add guarded matching overrides CRUD API endpoint',
            'matching_overrides_service_add' => 'Implement matching overrides service/repository CRUD',
            'matching_overrides_authority_wire' => 'Wire override feeder into UnifiedLearningAuthority',
            'matching_overrides_bulk_io_add' => 'Add matching overrides export/import endpoints',
            'matching_overrides_settings_tab_wire' => 'Wire matching overrides tab and loader in settings UI',
            default => 'Complete matching overrides foundation',
        };
        $target = match ($taskKey) {
            'matching_overrides_migration_apply' => 'database/migrations/20260226_000006_create_supplier_overrides_table.sql',
            'matching_overrides_api_add' => 'api/matching-overrides.php',
            'matching_overrides_service_add' => 'app/Services/MatchingOverrideService.php',
            'matching_overrides_authority_wire' => 'app/Services/Learning/AuthorityFactory.php',
            'matching_overrides_bulk_io_add' => 'api/export_matching_overrides.php',
            'matching_overrides_settings_tab_wire' => 'views/settings.php',
            default => 'Matching Overrides',
        };
        $nextBatch[] = [
            'task_id' => 'LOOP-TASK-' . str_pad((string)$taskCounter, 3, '0', STR_PAD_LEFT),
            'title' => $title,
            'target' => $target,
            'gap_refs' => ['A6'],
        ];
        $taskCounter++;
    }
}

if (empty($nextBatch)) {
    $schedulerChecklist = [
        'scheduler_runtime_service_add' => !$schedulerRuntimeServicePresent,
        'scheduler_run_ledger_migration_apply' => !$schedulerRunLedgerTablePresent,
        'scheduler_retry_wiring' => !$schedulerRetrySupportWired,
        'scheduler_status_command_add' => !$schedulerStatusCommandPresent,
        'scheduler_dead_letter_service_add' => !$schedulerDeadLetterServicePresent,
        'scheduler_dead_letter_api_add' => !$schedulerDeadLetterApiPresent,
        'scheduler_dead_letter_command_add' => !$schedulerDeadLetterCommandPresent,
        'scheduler_dead_letter_migration_apply' => !$schedulerDeadLetterTablePresent,
        'scheduler_dead_letter_integration_wire' => !$schedulerRuntimeDeadLetterIntegration,
    ];

    foreach ($schedulerChecklist as $taskKey => $needed) {
        if (!$needed) {
            continue;
        }
        $title = match ($taskKey) {
            'scheduler_runtime_service_add' => 'Implement scheduler runtime service with run logging',
            'scheduler_run_ledger_migration_apply' => 'Apply scheduler run ledger migration to DB',
            'scheduler_retry_wiring' => 'Wire scheduler runner to retry-capable runtime service',
            'scheduler_status_command_add' => 'Add scheduler status command for operational observability',
            'scheduler_dead_letter_service_add' => 'Implement scheduler dead-letter service',
            'scheduler_dead_letter_api_add' => 'Add scheduler dead-letters API endpoint',
            'scheduler_dead_letter_command_add' => 'Add scheduler dead-letters CLI command',
            'scheduler_dead_letter_migration_apply' => 'Apply scheduler dead-letter migration to DB',
            'scheduler_dead_letter_integration_wire' => 'Wire runtime failures to dead-letter service',
            default => 'Complete scheduler reliability hardening',
        };
        $target = match ($taskKey) {
            'scheduler_runtime_service_add' => 'app/Services/SchedulerRuntimeService.php',
            'scheduler_run_ledger_migration_apply' => 'database/migrations/20260226_000008_create_scheduler_job_runs_table.sql',
            'scheduler_retry_wiring' => 'maint/schedule.php',
            'scheduler_status_command_add' => 'maint/schedule-status.php',
            'scheduler_dead_letter_service_add' => 'app/Services/SchedulerDeadLetterService.php',
            'scheduler_dead_letter_api_add' => 'api/scheduler-dead-letters.php',
            'scheduler_dead_letter_command_add' => 'maint/schedule-dead-letters.php',
            'scheduler_dead_letter_migration_apply' => 'database/migrations/20260226_000010_create_scheduler_dead_letters_table.sql',
            'scheduler_dead_letter_integration_wire' => 'app/Services/SchedulerRuntimeService.php',
            default => 'Scheduler Reliability',
        };
        $nextBatch[] = [
            'task_id' => 'LOOP-TASK-' . str_pad((string)$taskCounter, 3, '0', STR_PAD_LEFT),
            'title' => $title,
            'target' => $target,
            'gap_refs' => ['A4'],
        ];
        $taskCounter++;
    }
}

if (empty($nextBatch)) {
    $printChecklist = [
        'print_governance_migration_apply' => !$printGovernanceTablePresent,
        'print_governance_api_add' => !$printGovernanceApiPresent,
        'print_governance_service_add' => !$printGovernanceServicePresent,
        'print_governance_js_helper_add' => !$printGovernanceJsPresent,
        'print_governance_single_wire' => !$printGovernanceSingleWiring,
        'print_governance_batch_wire' => !$printGovernanceBatchWiring,
        'print_governance_preview_wire' => !$printGovernancePreviewApiWiring,
    ];

    foreach ($printChecklist as $taskKey => $needed) {
        if (!$needed) {
            continue;
        }
        $title = match ($taskKey) {
            'print_governance_migration_apply' => 'Apply print events migration to DB',
            'print_governance_api_add' => 'Add guarded print-events API endpoint',
            'print_governance_service_add' => 'Implement print audit service for browser print events',
            'print_governance_js_helper_add' => 'Add frontend print-audit helper',
            'print_governance_single_wire' => 'Wire single-letter overlay print to audit API',
            'print_governance_batch_wire' => 'Wire batch print open/print events to audit API',
            'print_governance_preview_wire' => 'Wire letter preview API to print audit service',
            default => 'Complete print governance hardening',
        };
        $target = match ($taskKey) {
            'print_governance_migration_apply' => 'database/migrations/20260226_000007_create_print_events_table.sql',
            'print_governance_api_add' => 'api/print-events.php',
            'print_governance_service_add' => 'app/Services/PrintAuditService.php',
            'print_governance_js_helper_add' => 'public/js/print-audit.js',
            'print_governance_single_wire' => 'templates/letter-template.php',
            'print_governance_batch_wire' => 'views/batch-print.php',
            'print_governance_preview_wire' => 'api/get-letter-preview.php',
            default => 'Print Governance',
        };
        $nextBatch[] = [
            'task_id' => 'LOOP-TASK-' . str_pad((string)$taskCounter, 3, '0', STR_PAD_LEFT),
            'title' => $title,
            'target' => $target,
            'gap_refs' => ['B11'],
        ];
        $taskCounter++;
    }
}

if (empty($nextBatch)) {
    $configChecklist = [
        'config_governance_migration_apply' => !$configGovernanceTablePresent,
        'config_governance_service_add' => !$configGovernanceServicePresent,
        'config_governance_hook_wire' => !$configGovernanceSaveHookWired,
        'config_governance_endpoint_add' => !$configGovernanceAuditApiPresent,
    ];

    foreach ($configChecklist as $taskKey => $needed) {
        if (!$needed) {
            continue;
        }
        $title = match ($taskKey) {
            'config_governance_migration_apply' => 'Apply settings audit migration to DB',
            'config_governance_service_add' => 'Implement settings audit service',
            'config_governance_hook_wire' => 'Wire settings save API to audit trail service',
            'config_governance_endpoint_add' => 'Add settings audit API endpoint',
            default => 'Complete settings governance audit trail',
        };
        $target = match ($taskKey) {
            'config_governance_migration_apply' => 'database/migrations/20260226_000009_create_settings_audit_logs_table.sql',
            'config_governance_service_add' => 'app/Services/SettingsAuditService.php',
            'config_governance_hook_wire' => 'api/settings.php',
            'config_governance_endpoint_add' => 'api/settings-audit.php',
            default => 'Config Governance',
        };
        $nextBatch[] = [
            'task_id' => 'LOOP-TASK-' . str_pad((string)$taskCounter, 3, '0', STR_PAD_LEFT),
            'title' => $title,
            'target' => $target,
            'gap_refs' => ['A8'],
        ];
        $taskCounter++;
    }
}

if (empty($nextBatch)) {
    $reopenGovernanceChecklist = [
        'reopen_governance_migration_apply' => !$reopenGovernanceMigrationPresent || !$batchAuditTablePresent || !$breakGlassTablePresent,
        'reopen_governance_batch_permission_wire' => !$batchPermissionGateWired,
        'reopen_governance_reason_enforcement_wire' => !$batchReasonEnforced,
        'reopen_governance_batch_audit_wire' => !$batchAuditWired,
        'reopen_governance_guarantee_permission_wire' => !$guaranteePermissionGateWired,
        'reopen_governance_break_glass_wire' => !$batchBreakGlassWired || !$guaranteeBreakGlassWired || !is_file($breakGlassServicePath),
        'released_read_only_policy_wire' => !$releasedPolicyServicePresent
            || !$releasedExtendGuarded
            || !$releasedReduceGuarded
            || !$releasedUpdateGuarded
            || !$releasedSaveAndNextGuarded
            || !$releasedAttachmentGuarded,
    ];

    foreach ($reopenGovernanceChecklist as $taskKey => $needed) {
        if (!$needed) {
            continue;
        }
        $title = match ($taskKey) {
            'reopen_governance_migration_apply' => 'Apply reopen governance + break-glass migration to DB',
            'reopen_governance_batch_permission_wire' => 'Wire dedicated permission gate for batch reopen action',
            'reopen_governance_reason_enforcement_wire' => 'Enforce mandatory reason for batch reopen in API',
            'reopen_governance_batch_audit_wire' => 'Wire batch reopen/close audit trail service',
            'reopen_governance_guarantee_permission_wire' => 'Wire dedicated permission gate for guarantee reopen endpoint',
            'reopen_governance_break_glass_wire' => 'Wire emergency break-glass authorization and persistence',
            'released_read_only_policy_wire' => 'Wire released-state read-only mutation policy across mutating APIs',
            default => 'Complete reopen governance and released read-only policy',
        };
        $target = match ($taskKey) {
            'reopen_governance_migration_apply' => 'database/migrations/20260226_000012_break_glass_and_batch_governance.sql',
            'reopen_governance_batch_permission_wire' => 'api/batches.php',
            'reopen_governance_reason_enforcement_wire' => 'api/batches.php',
            'reopen_governance_batch_audit_wire' => 'app/Services/BatchService.php',
            'reopen_governance_guarantee_permission_wire' => 'api/reopen.php',
            'reopen_governance_break_glass_wire' => 'app/Services/BreakGlassService.php',
            'released_read_only_policy_wire' => 'app/Services/GuaranteeMutationPolicyService.php',
            default => 'Governance Policy',
        };
        $nextBatch[] = [
            'task_id' => 'LOOP-TASK-' . str_pad((string)$taskCounter, 3, '0', STR_PAD_LEFT),
            'title' => $title,
            'target' => $target,
            'gap_refs' => ['A3', 'A7', 'A10', 'Governance'],
        ];
        $taskCounter++;
    }
}

if (empty($nextBatch)) {
    $tokenI18nChecklist = [
        'token_auth_migration_apply' => !$tokenAuthTablePresent,
        'token_auth_service_add' => !$tokenAuthServicePresent,
        'token_auth_bootstrap_wire' => !$tokenAuthBootstrapIntegration,
        'token_auth_login_issue_wire' => !$tokenAuthLoginIssueSupport,
        'token_auth_logout_revoke_wire' => !$tokenAuthLogoutRevokeSupport,
        'token_auth_me_endpoint_add' => !$tokenAuthMeEndpointPresent,
        'user_language_schema_add' => !$userLanguageDbColumnPresent,
        'user_language_model_repo_wire' => !$userLanguageModelFieldPresent || !$userLanguageRepositorySupportPresent,
        'user_language_users_api_wire' => !$userLanguageUsersApiSupportPresent || !$userLanguageUsersListSupportPresent,
        'user_language_preferences_api_add' => !$userLanguagePreferencesApiPresent,
        'ui_i18n_runtime_add' => !$uiI18nRuntimePresent,
        'ui_i18n_direction_wire' => !$uiI18nDynamicDirectionPresent,
        'ui_i18n_views_wire' => !($uiI18nHeaderWired && $uiI18nLoginWired && $uiI18nUsersWired && $uiI18nBatchPrintWired),
        'shortcuts_runtime_add' => !$globalShortcutsRuntimePresent,
        'shortcuts_help_modal_add' => !$globalShortcutsHelpModalPresent,
        'shortcuts_views_wire' => !($globalShortcutsHeaderWired && $globalShortcutsLoginWired && $globalShortcutsUsersWired && $globalShortcutsBatchPrintWired),
    ];

    foreach ($tokenI18nChecklist as $taskKey => $needed) {
        if (!$needed) {
            continue;
        }
        $title = match ($taskKey) {
            'token_auth_migration_apply' => 'Apply API token + user language migration to DB',
            'token_auth_service_add' => 'Add API token service for issuance/revocation/authentication',
            'token_auth_bootstrap_wire' => 'Wire API bootstrap to bearer-token authentication fallback',
            'token_auth_login_issue_wire' => 'Wire login API to optional token issuance',
            'token_auth_logout_revoke_wire' => 'Wire logout API to revoke current bearer token',
            'token_auth_me_endpoint_add' => 'Add guarded current-user API endpoint (`me.php`)',
            'user_language_schema_add' => 'Add `users.preferred_language` column and guard fallback',
            'user_language_model_repo_wire' => 'Wire user model/repository for preferred language',
            'user_language_users_api_wire' => 'Wire users CRUD/list APIs for preferred language',
            'user_language_preferences_api_add' => 'Add user-preferences API for language setting',
            'ui_i18n_runtime_add' => 'Add shared i18n runtime script',
            'ui_i18n_direction_wire' => 'Wire dynamic `lang/dir` switching in UI runtime',
            'ui_i18n_views_wire' => 'Wire i18n runtime in header/login/users/batch-print views',
            'shortcuts_runtime_add' => 'Add global keyboard shortcuts runtime script',
            'shortcuts_help_modal_add' => 'Add shortcuts help modal wiring in runtime',
            'shortcuts_views_wire' => 'Wire shortcuts runtime in header/login/users/batch-print views',
            default => 'Complete token auth and i18n foundations',
        };
        $target = match ($taskKey) {
            'token_auth_migration_apply' => 'database/migrations/20260226_000011_add_api_tokens_and_user_language.sql',
            'token_auth_service_add' => 'app/Support/ApiTokenService.php',
            'token_auth_bootstrap_wire' => 'api/_bootstrap.php',
            'token_auth_login_issue_wire' => 'api/login.php',
            'token_auth_logout_revoke_wire' => 'api/logout.php',
            'token_auth_me_endpoint_add' => 'api/me.php',
            'user_language_schema_add' => 'database/migrations/20260226_000011_add_api_tokens_and_user_language.sql',
            'user_language_model_repo_wire' => 'app/Repositories/UserRepository.php',
            'user_language_users_api_wire' => 'api/users/list.php',
            'user_language_preferences_api_add' => 'api/user-preferences.php',
            'ui_i18n_runtime_add' => 'public/js/i18n.js',
            'ui_i18n_direction_wire' => 'public/js/i18n.js',
            'ui_i18n_views_wire' => 'partials/unified-header.php',
            'shortcuts_runtime_add' => 'public/js/global-shortcuts.js',
            'shortcuts_help_modal_add' => 'public/js/global-shortcuts.js',
            'shortcuts_views_wire' => 'partials/unified-header.php',
            default => 'Token Auth + i18n',
        };
        $nextBatch[] = [
            'task_id' => 'LOOP-TASK-' . str_pad((string)$taskCounter, 3, '0', STR_PAD_LEFT),
            'title' => $title,
            'target' => $target,
            'gap_refs' => ['A1', 'A7', 'A9', 'UX'],
        ];
        $taskCounter++;
    }
}

if (empty($nextBatch)) {
    $integrationChecklist = [
        'integration_suite_add' => !$integrationFlowSuitePresent || count($integrationFiles) === 0,
    ];

    foreach ($integrationChecklist as $taskKey => $needed) {
        if (!$needed) {
            continue;
        }

        $title = match ($taskKey) {
            'integration_suite_add' => 'Add HTTP integration suite for auth/print/history/undo/scheduler flows',
            default => 'Complete integration baseline',
        };

        $target = match ($taskKey) {
            'integration_suite_add' => 'tests/Integration/EnterpriseApiFlowsTest.php',
            default => 'tests/Integration',
        };

        $nextBatch[] = [
            'task_id' => 'LOOP-TASK-' . str_pad((string)$taskCounter, 3, '0', STR_PAD_LEFT),
            'title' => $title,
            'target' => $target,
            'gap_refs' => ['Enterprise-W2', 'A1', 'A3', 'A4', 'A5', 'A8'],
        ];
        $taskCounter++;
    }
}

if (empty($nextBatch)) {
    $uiArchitectureChecklist = [
        'ui_runtime_stack_wire' => !$directionRuntimePresent || !$themeRuntimePresent || !$policyRuntimePresent || !$navManifestPresent || !$uiRuntimePresent || !$themesCssPresent,
        'ui_header_controls_wire' => !$themeControlsPresent || !$directionControlsPresent || !$langControlsPresent || !$navManifestContainerPresent || !$uiBootstrapPresent,
        'ui_view_policy_wire' => $guardedViewsWired !== count($guardedViewsExpected) || !$viewPolicyPresent,
        'ui_api_policy_matrix_sync' => !$apiPolicyMatrixPresent || !$apiPolicyMatrixParity,
        'ui_translation_coverage_fill' => $translationCoveragePct < 95,
        'ui_theme_token_migration' => $themeTokenCoveragePct < 70,
        'ui_component_policy_gates' => $uiAuthorizeTagCount < 6,
        'ui_playwright_foundation' => !$playwrightReady,
    ];

    foreach ($uiArchitectureChecklist as $taskKey => $needed) {
        if (!$needed) {
            continue;
        }

        $title = match ($taskKey) {
            'ui_runtime_stack_wire' => 'Wire full UI runtime stack (direction/theme/policy/nav/runtime/themes css)',
            'ui_header_controls_wire' => 'Wire adaptive header controls + bootstrap contract',
            'ui_view_policy_wire' => 'Complete centralized view guard coverage',
            'ui_api_policy_matrix_sync' => 'Sync API policy matrix with live endpoints',
            'ui_translation_coverage_fill' => 'Fill missing i18n keys to reach >=95% coverage',
            'ui_theme_token_migration' => 'Increase theme token usage ratio to >=70% in CSS',
            'ui_component_policy_gates' => 'Add component-level policy gates for critical actions',
            'ui_playwright_foundation' => 'Establish Playwright smoke gates for UI governance',
            default => 'Complete UI architecture baseline',
        };

        $target = match ($taskKey) {
            'ui_runtime_stack_wire' => 'public/js/ui-runtime.js',
            'ui_header_controls_wire' => 'partials/unified-header.php',
            'ui_view_policy_wire' => 'app/Support/ViewPolicy.php',
            'ui_api_policy_matrix_sync' => 'app/Support/ApiPolicyMatrix.php',
            'ui_translation_coverage_fill' => 'public/locales/ar/common.json',
            'ui_theme_token_migration' => 'public/css/themes.css',
            'ui_component_policy_gates' => 'index.php',
            'ui_playwright_foundation' => 'tests/e2e/*.spec.js',
            default => 'UI Architecture',
        };

        $nextBatch[] = [
            'task_id' => 'LOOP-TASK-' . str_pad((string)$taskCounter, 3, '0', STR_PAD_LEFT),
            'title' => $title,
            'target' => $target,
            'gap_refs' => ['A1', 'A7', 'A9', 'UX', 'Governance'],
        ];
        $taskCounter++;
    }
}

if (empty($nextBatch)) {
    $a11yChecklist = [
        'a11y_css_add' => !$a11yCssPresent,
        'a11y_focus_visible_rule' => !$a11yFocusVisibleRulePresent,
        'a11y_sr_only_utility' => !$a11ySrOnlyUtilityPresent,
        'a11y_index_link' => !$a11yIndexLinked,
        'a11y_batch_detail_link' => !$a11yBatchDetailLinked,
        'a11y_settings_link' => !$a11ySettingsLinked,
        'a11y_settings_tab_semantics' => !$a11ySettingsTabSemantics,
        'a11y_settings_modal_semantics' => !$a11ySettingsModalSemantics,
        'a11y_batch_modal_semantics' => !$a11yBatchDetailModalSemantics,
        'a11y_batch_icon_labels' => !$a11yBatchDetailIconLabels,
    ];

    foreach ($a11yChecklist as $taskKey => $needed) {
        if (!$needed) {
            continue;
        }
        $title = match ($taskKey) {
            'a11y_css_add' => 'Add shared accessibility stylesheet',
            'a11y_focus_visible_rule' => 'Add visible keyboard focus styling in a11y stylesheet',
            'a11y_sr_only_utility' => 'Add sr-only utility class for assistive text',
            'a11y_index_link' => 'Link a11y stylesheet in index shell',
            'a11y_batch_detail_link' => 'Link a11y stylesheet in batch detail view',
            'a11y_settings_link' => 'Link a11y stylesheet in settings view',
            'a11y_settings_tab_semantics' => 'Wire ARIA tab semantics and keyboard navigation in settings',
            'a11y_settings_modal_semantics' => 'Wire modal ARIA semantics and keyboard handling in settings',
            'a11y_batch_modal_semantics' => 'Wire modal ARIA semantics and focus handling in batch detail',
            'a11y_batch_icon_labels' => 'Add aria-labels for icon-only actions in batch detail',
            default => 'Complete UX/A11y hardening task',
        };
        $target = match ($taskKey) {
            'a11y_css_add' => 'public/css/a11y.css',
            'a11y_focus_visible_rule' => 'public/css/a11y.css',
            'a11y_sr_only_utility' => 'public/css/a11y.css',
            'a11y_index_link' => 'index.php',
            'a11y_batch_detail_link' => 'views/batch-detail.php',
            'a11y_settings_link' => 'views/settings.php',
            'a11y_settings_tab_semantics' => 'views/settings.php',
            'a11y_settings_modal_semantics' => 'views/settings.php',
            'a11y_batch_modal_semantics' => 'views/batch-detail.php',
            'a11y_batch_icon_labels' => 'views/batch-detail.php',
            default => 'UX/A11y',
        };
        $nextBatch[] = [
            'task_id' => 'LOOP-TASK-' . str_pad((string)$taskCounter, 3, '0', STR_PAD_LEFT),
            'title' => $title,
            'target' => $target,
            'gap_refs' => ['UX', 'A11Y'],
        ];
        $taskCounter++;
    }
}

$status = [
    'run_at' => date('Y-m-d H:i:s'),
    'mode' => [
        'priority_order' => ['P0', 'P1', 'P2', 'P3'],
        'delivery_style' => 'progressive_no_deadline',
        'scope' => 'WBGL-first',
    ],
    'corpus' => [
        'required_total' => count($requiredDocs),
        'present' => count($requiredDocs) - count($missingDocs),
        'missing_files' => $missingDocs,
    ],
    'gaps' => [
        'total' => count($wbglMissingGaps),
        'by_priority' => [
            'High' => (int)($byPriority['High'] ?? 0),
            'Medium' => (int)($byPriority['Medium'] ?? 0),
            'Low' => (int)($byPriority['Low'] ?? 0),
        ],
        'phase_map' => $phaseMap,
    ],
    'api_guard' => [
        'total' => $guarded + $unguarded,
        'guarded' => $guarded,
        'unguarded' => $unguarded,
        'sensitive_unguarded' => $sensitiveUnguarded,
        'unguarded_endpoints' => $unguardedList,
        'sensitive_unguarded_endpoints' => $sensitiveUnguardedList,
    ],
    'login_rate_limit' => [
        'api_hook_present' => $hasLoginRateApiHook,
        'migration_file_present' => $hasLoginRateMigration,
        'table_present' => $hasLoginRateTable,
    ],
    'security_baseline' => [
        'session_security_service_present' => $sessionSecurityServicePresent,
        'security_headers_service_present' => $securityHeadersServicePresent,
        'csrf_guard_service_present' => $csrfGuardServicePresent,
        'autoload_session_hardening_wired' => $autoloadSessionHardeningWired,
        'autoload_security_headers_wired' => $autoloadSecurityHeadersWired,
        'autoload_non_api_csrf_guard_wired' => $autoloadNonApiCsrfGuardWired,
        'api_bootstrap_csrf_guard_wired' => $apiBootstrapCsrfGuardWired,
        'login_endpoint_csrf_guard_wired' => $loginEndpointCsrfGuardWired,
        'frontend_security_runtime_present' => $frontendSecurityRuntimePresent,
        'frontend_security_runtime_wired' => $frontendSecurityRuntimeWired,
        'logout_hard_destroy_wired' => $logoutHardDestroyWired,
        'rate_limit_user_agent_fingerprint_wired' => $rateLimitUserAgentFingerprintWired,
    ],
    'undo_governance' => [
        'api_endpoint_present' => $hasUndoApi,
        'service_present' => $hasUndoService,
        'migration_file_present' => $hasUndoMigration,
        'table_present' => $hasUndoTable,
        'reopen_integrated' => $hasUndoReopenIntegration,
        'enforcement_flag_present' => $hasUndoEnforcementFlag,
        'always_enforced' => $hasUndoAlwaysEnforced,
    ],
    'role_visibility' => [
        'service_present' => $visibilityServicePresent,
        'navigation_integrated' => $navigationIntegrated,
        'stats_integrated' => $statsIntegrated,
        'endpoint_enforcement' => $endpointEnforcement,
        'always_enforced' => $visibilityAlwaysEnforced,
    ],
    'notifications' => [
        'api_endpoint_present' => $hasNotificationApi,
        'service_present' => $hasNotificationService,
        'migration_file_present' => $hasNotificationMigration,
        'table_present' => $hasNotificationTable,
    ],
    'scheduler' => [
        'runner_present' => $schedulerRunnerPresent,
        'expiry_job_present' => $schedulerExpiryJobPresent,
        'runner_wires_expiry_job' => $runnerWiresExpiry,
        'runtime_service_present' => $schedulerRuntimeServicePresent,
        'run_ledger_migration_present' => $schedulerRunLedgerMigration,
        'run_ledger_table_present' => $schedulerRunLedgerTablePresent,
        'retry_support_wired' => $schedulerRetrySupportWired,
        'status_command_present' => $schedulerStatusCommandPresent,
        'dead_letter_service_present' => $schedulerDeadLetterServicePresent,
        'dead_letter_api_present' => $schedulerDeadLetterApiPresent,
        'dead_letter_command_present' => $schedulerDeadLetterCommandPresent,
        'dead_letter_migration_present' => $schedulerDeadLetterMigration,
        'dead_letter_table_present' => $schedulerDeadLetterTablePresent,
        'runtime_dead_letter_integration' => $schedulerRuntimeDeadLetterIntegration,
    ],
    'observability' => [
        'metrics_api_present' => $observabilityMetricsApiPresent,
        'metrics_service_present' => $observabilityMetricsServicePresent,
        'metrics_api_permission_guarded' => $observabilityMetricsApiPermissionGuarded,
        'metrics_api_policy_mapped' => $observabilityMetricsApiPolicyMapped,
        'api_request_id_wired' => $observabilityRequestIdWired,
    ],
    'db_cutover' => [
        'active_driver' => $loopDbDriver,
        'driver_configuration' => $loopDbConfigSummary,
        'driver_status_command_present' => is_file($dbDriverStatusCommandPath),
        'cutover_check_command_present' => is_file($dbCutoverCheckCommandPath),
        'backup_command_present' => is_file($dbBackupCommandPath),
        'portability_check_command_present' => is_file($migrationPortabilityPath),
        'fingerprint_command_present' => is_file($dbCutoverFingerprintPath),
        'pg_activation_rehearsal_command_present' => is_file($pgActivationRehearsalPath),
        'cutover_runbook_present' => is_file($dbCutoverRunbookRepoPath) || is_file($dbCutoverRunbookWorkspacePath),
        'backup_restore_runbook_present' => is_file($dbBackupRestoreRunbookRepoPath) || is_file($dbBackupRestoreRunbookWorkspacePath),
        'pg_activation_runbook_present' => is_file($pgActivationRunbookRepoPath) || is_file($pgActivationRunbookWorkspacePath),
        'backup_directory_present' => is_dir($dbBackupsDir),
        'backup_artifacts_count' => $dbBackupArtifactsCount,
        'schema_migrations_table_present' => $dbSchemaMigrationsTablePresent,
        'migration_tooling_driver_aware' => $dbMigrationToolingDriverAware,
        'portability_report_present' => is_file($portabilityReportPath),
        'portability_high_blockers' => $portabilityHighBlockers,
        'fingerprint_latest_present' => is_file($fingerprintLatestPath),
        'pg_activation_rehearsal_latest_present' => is_file($pgActivationRehearsalLatestPath),
        'pg_activation_rehearsal_ready' => $pgActivationRehearsalReady,
        'ready' => $dbCutoverReady,
        'production_ready' => $dbCutoverReady
            && is_file($migrationPortabilityPath)
            && is_file($dbCutoverFingerprintPath)
            && $pgActivationRehearsalReady
            && $portabilityHighBlockers === 0
            && $loopDbDriver === 'pgsql',
    ],
    'ci_cd' => [
        'change_gate_workflow_present' => is_file($changeGateWorkflowPath),
        'ci_workflow_present' => is_file($ciWorkflowPath),
        'security_workflow_present' => is_file($securityWorkflowPath),
        'release_workflow_present' => is_file($releaseWorkflowPath),
        'enterprise_workflows_ready' => $enterpriseWorkflowsReady,
    ],
    'sod_compliance' => [
        'governance_policy_doc_present' => is_file($governancePolicyDocPath),
        'sod_matrix_doc_present' => is_file($sodMatrixDocPath),
        'break_glass_runbook_present' => is_file($breakGlassRunbookPath),
        'self_approval_guard_enforced' => $undoSelfApprovalGuarded,
        'break_glass_permission_gate_enforced' => $breakGlassPermissionGuarded,
        'break_glass_ticket_policy_present' => $breakGlassTicketPolicyPresent,
        'audit_tables_present' => $batchAuditTablePresent && $breakGlassTablePresent,
        'ready' => $sodComplianceReady,
    ],
    'stage_gates' => [
        'gate_a_passed' => $gateAPassed,
        'gate_b_passed' => $gateBPassed,
        'gate_c_passed' => $gateCPassed,
        'gate_d_rehearsal_passed' => $gateDRehearsalPassed,
        'gate_d_pg_rehearsal_report_passed' => $gateDPgRehearsalReportPassed,
        'gate_d_pg_activation_passed' => $gateDPgActivationPassed,
        'gate_e_passed' => $gateEPassed,
    ],
    'history_hybrid' => [
        'migration_file_present' => $historyHybridMigration,
        'columns_present' => $historyHybridColumnsPresent,
        'service_present' => $historyHybridServicePresent,
        'settings_flag_present' => $historySettingsFlagPresent,
        'policy_locked' => $historyPolicyLocked,
        'recorder_integration_present' => $historyRecorderIntegration,
        'snapshot_reader_integration_present' => $historySnapshotReaderIntegration,
        'event_catalog_extracted' => $historyEventCatalogExtracted,
    ],
    'matching_overrides' => [
        'api_endpoint_present' => $matchingOverridesApiPresent,
        'service_present' => $matchingOverridesServicePresent,
        'repository_crud_present' => $matchingOverridesRepoCrudPresent,
        'migration_file_present' => $matchingOverridesMigration,
        'table_present' => $matchingOverridesTablePresent,
        'authority_feeder_wired' => $matchingOverridesAuthorityWired,
        'export_endpoint_present' => $matchingOverridesExportPresent,
        'import_endpoint_present' => $matchingOverridesImportPresent,
        'settings_tab_present' => $matchingOverridesSettingsTabPresent,
        'settings_loader_present' => $matchingOverridesSettingsLoaderPresent,
    ],
    'print_governance' => [
        'api_endpoint_present' => $printGovernanceApiPresent,
        'service_present' => $printGovernanceServicePresent,
        'js_helper_present' => $printGovernanceJsPresent,
        'migration_file_present' => $printGovernanceMigration,
        'table_present' => $printGovernanceTablePresent,
        'single_letter_wiring' => $printGovernanceSingleWiring,
        'batch_wiring' => $printGovernanceBatchWiring,
        'preview_api_wiring' => $printGovernancePreviewApiWiring,
    ],
    'config_governance' => [
        'settings_api_present' => $configGovernanceSettingsApiPresent,
        'service_present' => $configGovernanceServicePresent,
        'audit_endpoint_present' => $configGovernanceAuditApiPresent,
        'save_hook_wired' => $configGovernanceSaveHookWired,
        'migration_file_present' => $configGovernanceMigration,
        'table_present' => $configGovernanceTablePresent,
    ],
    'reopen_governance' => [
        'migration_file_present' => $reopenGovernanceMigrationPresent,
        'batch_audit_table_present' => $batchAuditTablePresent,
        'break_glass_table_present' => $breakGlassTablePresent,
        'batch_permission_gate_wired' => $batchPermissionGateWired,
        'batch_reason_enforced' => $batchReasonEnforced,
        'batch_audit_wired' => $batchAuditWired,
        'guarantee_permission_gate_wired' => $guaranteePermissionGateWired,
        'break_glass_auth_wired' => $batchBreakGlassWired && $guaranteeBreakGlassWired && is_file($breakGlassServicePath),
        'break_glass_runtime_enabled' => $breakGlassRuntimeEnabled,
    ],
    'released_read_only' => [
        'policy_service_present' => $releasedPolicyServicePresent,
        'extend_guarded' => $releasedExtendGuarded,
        'reduce_guarded' => $releasedReduceGuarded,
        'update_guarded' => $releasedUpdateGuarded,
        'save_and_next_guarded' => $releasedSaveAndNextGuarded,
        'attachment_guarded' => $releasedAttachmentGuarded,
    ],
    'token_auth' => [
        'service_present' => $tokenAuthServicePresent,
        'migration_file_present' => $tokenAuthMigrationPresent,
        'table_present' => $tokenAuthTablePresent,
        'bootstrap_integration_present' => $tokenAuthBootstrapIntegration,
        'login_issue_support' => $tokenAuthLoginIssueSupport,
        'logout_revoke_support' => $tokenAuthLogoutRevokeSupport,
        'me_endpoint_present' => $tokenAuthMeEndpointPresent,
    ],
    'user_language' => [
        'db_column_present' => $userLanguageDbColumnPresent,
        'user_model_field_present' => $userLanguageModelFieldPresent,
        'repository_support_present' => $userLanguageRepositorySupportPresent,
        'users_api_support_present' => $userLanguageUsersApiSupportPresent,
        'users_list_support_present' => $userLanguageUsersListSupportPresent,
        'preferences_api_present' => $userLanguagePreferencesApiPresent,
    ],
    'ui_i18n' => [
        'runtime_present' => $uiI18nRuntimePresent,
        'dynamic_direction_present' => $uiI18nDynamicDirectionPresent,
        'header_wired' => $uiI18nHeaderWired,
        'login_wired' => $uiI18nLoginWired,
        'users_wired' => $uiI18nUsersWired,
        'batch_print_wired' => $uiI18nBatchPrintWired,
    ],
    'global_shortcuts' => [
        'runtime_present' => $globalShortcutsRuntimePresent,
        'help_modal_present' => $globalShortcutsHelpModalPresent,
        'header_wired' => $globalShortcutsHeaderWired,
        'login_wired' => $globalShortcutsLoginWired,
        'users_wired' => $globalShortcutsUsersWired,
        'batch_print_wired' => $globalShortcutsBatchPrintWired,
    ],
    'ux_a11y' => [
        'a11y_css_present' => $a11yCssPresent,
        'focus_visible_rule_present' => $a11yFocusVisibleRulePresent,
        'sr_only_utility_present' => $a11ySrOnlyUtilityPresent,
        'index_linked' => $a11yIndexLinked,
        'batch_detail_linked' => $a11yBatchDetailLinked,
        'settings_linked' => $a11ySettingsLinked,
        'settings_tab_semantics' => $a11ySettingsTabSemantics,
        'settings_modal_semantics' => $a11ySettingsModalSemantics,
        'batch_detail_modal_semantics' => $a11yBatchDetailModalSemantics,
        'batch_detail_icon_labels' => $a11yBatchDetailIconLabels,
    ],
    'ui_architecture' => [
        'direction_runtime_present' => $directionRuntimePresent,
        'theme_runtime_present' => $themeRuntimePresent,
        'policy_runtime_present' => $policyRuntimePresent,
        'nav_manifest_present' => $navManifestPresent,
        'ui_runtime_present' => $uiRuntimePresent,
        'themes_css_present' => $themesCssPresent,
        'ui_bootstrap_present' => $uiBootstrapPresent,
        'view_policy_present' => $viewPolicyPresent,
        'api_policy_matrix_present' => $apiPolicyMatrixPresent,
        'direction_header_wired' => $directionHeaderWired,
        'direction_login_wired' => $directionLoginWired,
        'direction_users_wired' => $directionUsersWired,
        'direction_batch_print_wired' => $directionBatchPrintWired,
        'theme_header_wired' => $themeHeaderWired,
        'theme_login_wired' => $themeLoginWired,
        'theme_users_wired' => $themeUsersWired,
        'theme_batch_print_wired' => $themeBatchPrintWired,
        'policy_header_wired' => $policyHeaderWired,
        'policy_login_wired' => $policyLoginWired,
        'policy_users_wired' => $policyUsersWired,
        'policy_batch_print_wired' => $policyBatchPrintWired,
        'header_controls' => [
            'language' => $langControlsPresent,
            'direction' => $directionControlsPresent,
            'theme' => $themeControlsPresent,
            'nav_manifest_root' => $navManifestContainerPresent,
        ],
        'view_guard' => [
            'expected_total' => count($guardedViewsExpected),
            'guarded' => $guardedViewsWired,
            'coverage_pct' => $viewGuardCoveragePct,
        ],
        'api_policy_matrix' => [
            'parity' => $apiPolicyMatrixParity,
            'missing_count' => count($apiPolicyMatrixMissing),
            'extra_count' => count($apiPolicyMatrixExtra),
            'missing_endpoints' => $apiPolicyMatrixMissing,
            'extra_endpoints' => $apiPolicyMatrixExtra,
        ],
        'translation' => [
            'used_keys' => count($i18nUsedKeys),
            'missing_keys' => count($missingI18nKeys),
            'coverage_pct' => $translationCoveragePct,
        ],
        'rtl_readiness' => [
            'screens_total' => count($rtlKeyScreens),
            'screens_wired' => $rtlScreensWired,
            'coverage_pct' => $rtlReadinessPct,
        ],
        'theme_token_coverage' => [
            'var_refs' => $cssVarRefs,
            'hex_refs' => $cssHexRefs,
            'coverage_pct' => $themeTokenCoveragePct,
        ],
        'component_policy' => [
            'authorize_tag_count' => $uiAuthorizeTagCount,
        ],
        'readiness' => [
            'translation_target_pass' => $translationCoveragePct >= 95,
            'rtl_target_pass' => $rtlReadinessPct >= 90,
            'theme_token_target_pass' => $themeTokenCoveragePct >= 70,
            'view_guard_target_pass' => $viewGuardCoveragePct >= 100,
            'api_matrix_parity_pass' => $apiPolicyMatrixParity,
        ],
    ],
    'playwright' => [
        'package_present' => $playwrightPackagePresent,
        'config_present' => $playwrightConfigPresent,
        'script_present' => $playwrightScriptPresent,
        'dependency_present' => $playwrightDependencyPresent,
        'tests_count' => $playwrightTestsCount,
        'ready' => $playwrightReady,
    ],
    'migrations' => [
        'sql_files' => count($migrationFiles),
        'applied' => count($appliedMigrations),
        'pending' => count($pendingMigrations),
        'pending_files' => $pendingMigrations,
    ],
    'tests' => [
        'files_total' => count($testFiles),
        'unit_files' => count($unitFiles),
        'integration_files' => count($integrationFiles),
        'integration_flow_suite_present' => $integrationFlowSuitePresent,
    ],
    'next_batch' => $nextBatch,
];

$statusJsonPath = $docsRoot . DIRECTORY_SEPARATOR . 'WBGL_EXECUTION_LOOP_STATUS.json';
$statusMdPath = $docsRoot . DIRECTORY_SEPARATOR . 'WBGL_EXECUTION_LOOP_STATUS.md';
$logPath = $docsRoot . DIRECTORY_SEPARATOR . 'WBGL_EXECUTION_LOOP_LOG.md';

file_put_contents(
    $statusJsonPath,
    json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL
);

file_put_contents($statusMdPath, wbglLoopBuildMarkdown($status));

$logLine = '- ' . $status['run_at']
    . ' | guarded=' . $status['api_guard']['guarded'] . '/' . $status['api_guard']['total']
    . ' | sensitive_unguarded=' . $status['api_guard']['sensitive_unguarded']
    . ' | scheduler_runtime=' . ($status['scheduler']['run_ledger_table_present'] ? 'ready' : 'pending')
    . ' | scheduler_dead_letters=' . ($status['scheduler']['dead_letter_table_present'] ? 'ready' : 'pending')
    . ' | observability=' . (
        $status['observability']['metrics_api_present']
        && $status['observability']['metrics_service_present']
        && $status['observability']['metrics_api_permission_guarded']
        && $status['observability']['metrics_api_policy_mapped']
        && $status['observability']['api_request_id_wired']
        ? 'ready'
        : 'pending'
    )
    . ' | db_cutover=' . ($status['db_cutover']['ready'] ? 'ready' : 'pending')
    . ' | db_pg_ready=' . ($status['db_cutover']['production_ready'] ? 'ready' : 'pending')
    . ' | db_pg_rehearsal=' . ($status['db_cutover']['pg_activation_rehearsal_ready'] ? 'ready' : 'pending')
    . ' | ci_cd=' . ($status['ci_cd']['enterprise_workflows_ready'] ? 'ready' : 'pending')
    . ' | sod=' . ($status['sod_compliance']['ready'] ? 'ready' : 'pending')
    . ' | gate_b=' . ($status['stage_gates']['gate_b_passed'] ? 'ready' : 'pending')
    . ' | gate_c=' . ($status['stage_gates']['gate_c_passed'] ? 'ready' : 'pending')
    . ' | gate_d=' . ($status['stage_gates']['gate_d_rehearsal_passed'] ? 'ready' : 'pending')
    . ' | gate_e=' . ($status['stage_gates']['gate_e_passed'] ? 'ready' : 'pending')
    . ' | history_hybrid=' . ($status['history_hybrid']['columns_present'] ? 'ready' : 'pending')
    . ' | matching_overrides=' . ($status['matching_overrides']['table_present'] ? 'ready' : 'pending')
    . ' | print_governance=' . ($status['print_governance']['table_present'] ? 'ready' : 'pending')
    . ' | config_governance=' . ($status['config_governance']['table_present'] ? 'ready' : 'pending')
    . ' | security_wave1=' . (
        $status['security_baseline']['session_security_service_present']
        && $status['security_baseline']['security_headers_service_present']
        && $status['security_baseline']['csrf_guard_service_present']
        && $status['security_baseline']['autoload_session_hardening_wired']
        && $status['security_baseline']['autoload_security_headers_wired']
        && $status['security_baseline']['autoload_non_api_csrf_guard_wired']
        && $status['security_baseline']['api_bootstrap_csrf_guard_wired']
        && $status['security_baseline']['login_endpoint_csrf_guard_wired']
        && $status['security_baseline']['frontend_security_runtime_present']
        && $status['security_baseline']['frontend_security_runtime_wired']
        && $status['security_baseline']['logout_hard_destroy_wired']
        && $status['security_baseline']['rate_limit_user_agent_fingerprint_wired']
        ? 'ready'
        : 'pending'
    )
    . ' | reopen_governance=' . (
        $status['reopen_governance']['batch_audit_table_present']
        && $status['reopen_governance']['break_glass_table_present']
        && $status['reopen_governance']['batch_permission_gate_wired']
        && $status['reopen_governance']['batch_reason_enforced']
        && $status['reopen_governance']['guarantee_permission_gate_wired']
        && $status['reopen_governance']['break_glass_auth_wired']
        ? 'ready'
        : 'pending'
    )
    . ' | released_read_only=' . (
        $status['released_read_only']['policy_service_present']
        && $status['released_read_only']['extend_guarded']
        && $status['released_read_only']['reduce_guarded']
        && $status['released_read_only']['update_guarded']
        && $status['released_read_only']['save_and_next_guarded']
        && $status['released_read_only']['attachment_guarded']
        ? 'ready'
        : 'pending'
    )
    . ' | token_auth=' . (
        $status['token_auth']['service_present']
        && $status['token_auth']['table_present']
        && $status['token_auth']['bootstrap_integration_present']
        && $status['token_auth']['login_issue_support']
        && $status['token_auth']['logout_revoke_support']
        ? 'ready'
        : 'pending'
    )
    . ' | i18n_dir=' . (
        $status['ui_i18n']['runtime_present']
        && $status['ui_i18n']['dynamic_direction_present']
        && $status['ui_i18n']['header_wired']
        && $status['ui_i18n']['login_wired']
        ? 'ready'
        : 'pending'
    )
    . ' | shortcuts=' . (
        $status['global_shortcuts']['runtime_present']
        && $status['global_shortcuts']['help_modal_present']
        && $status['global_shortcuts']['header_wired']
        ? 'ready'
        : 'pending'
    )
    . ' | ux_a11y=' . (
        $status['ux_a11y']['a11y_css_present']
        && $status['ux_a11y']['index_linked']
        && $status['ux_a11y']['batch_detail_linked']
        && $status['ux_a11y']['settings_linked']
        ? 'ready'
        : 'pending'
    )
    . ' | integration=' . (
        $status['tests']['integration_files'] > 0
        && $status['tests']['integration_flow_suite_present']
        ? 'ready'
        : 'pending'
    )
    . ' | ui_arch=' . (
        $status['ui_architecture']['readiness']['translation_target_pass']
        && $status['ui_architecture']['readiness']['rtl_target_pass']
        && $status['ui_architecture']['readiness']['theme_token_target_pass']
        && $status['ui_architecture']['readiness']['view_guard_target_pass']
        && $status['ui_architecture']['readiness']['api_matrix_parity_pass']
        ? 'ready'
        : 'pending'
    )
    . ' | playwright=' . ($status['playwright']['ready'] ? 'ready' : 'pending')
    . ' | pending_migrations=' . $status['migrations']['pending']
    . PHP_EOL;

if (!is_file($logPath)) {
    $seed = "# WBGL Execution Loop Log\n\n";
    file_put_contents($logPath, $seed);
}
file_put_contents($logPath, $logLine, FILE_APPEND);

if (is_dir($repoDocsRoot)) {
    $repoStatusJsonPath = $repoDocsRoot . DIRECTORY_SEPARATOR . 'WBGL_EXECUTION_LOOP_STATUS.json';
    $repoStatusMdPath = $repoDocsRoot . DIRECTORY_SEPARATOR . 'WBGL_EXECUTION_LOOP_STATUS.md';
    $repoLogPath = $repoDocsRoot . DIRECTORY_SEPARATOR . 'WBGL_EXECUTION_LOOP_LOG.md';

    file_put_contents(
        $repoStatusJsonPath,
        json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL
    );
    file_put_contents($repoStatusMdPath, wbglLoopBuildMarkdown($status));

    if (!is_file($repoLogPath)) {
        $seed = "# WBGL Execution Loop Log\n\n";
        file_put_contents($repoLogPath, $seed);
    }
    file_put_contents($repoLogPath, $logLine, FILE_APPEND);
}

echo 'WBGL execution loop completed.' . PHP_EOL;
echo 'Status JSON: ' . $statusJsonPath . PHP_EOL;
echo 'Status MD:   ' . $statusMdPath . PHP_EOL;
echo 'Guarded: ' . $status['api_guard']['guarded'] . '/' . $status['api_guard']['total'] . PHP_EOL;
echo 'Sensitive unguarded: ' . $status['api_guard']['sensitive_unguarded'] . PHP_EOL;
