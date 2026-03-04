<?php
declare(strict_types=1);

/**
 * WBGL Permissions Drift Report
 *
 * Usage:
 *   php app/Scripts/permissions-drift-report.php
 *   php app/Scripts/permissions-drift-report.php --output-json=storage/logs/permissions-drift-report.json --output-md=storage/logs/permissions-drift-report.md
 *   php app/Scripts/permissions-drift-report.php --strict
 */

require_once __DIR__ . '/../Support/autoload.php';

use App\Support\ApiPolicyMatrix;
use App\Support\Database;
use App\Support\PermissionCapabilityCatalog;
use App\Support\UiPolicy;

/**
 * @return array<int,array<string,mixed>>
 */
function wbgl_fetch_all(PDO $db, string $sql): array
{
    $stmt = $db->query($sql);
    if ($stmt === false) {
        return [];
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

function wbgl_fetch_int(PDO $db, string $sql): int
{
    $stmt = $db->query($sql);
    if ($stmt === false) {
        return 0;
    }
    return (int)$stmt->fetchColumn();
}

/**
 * @param array<string,mixed> $report
 */
function wbgl_render_markdown(array $report): string
{
    $status = strtoupper((string)($report['status'] ?? 'unknown'));
    $generatedAt = (string)($report['generated_at'] ?? '');
    $driver = (string)($report['driver'] ?? '');
    $counts = is_array($report['counts'] ?? null) ? $report['counts'] : [];
    $details = is_array($report['details'] ?? null) ? $report['details'] : [];

    $md = [];
    $md[] = '# WBGL Permissions Drift Report';
    $md[] = '';
    $md[] = '- Generated At: `' . $generatedAt . '`';
    $md[] = '- Driver: `' . $driver . '`';
    $md[] = '- Status: **' . $status . '**';
    $md[] = '';
    $md[] = '## Summary';
    $md[] = '';
    $md[] = '| Metric | Value |';
    $md[] = '|---|---:|';
    $md[] = '| DB permissions | ' . (int)($counts['db_permissions'] ?? 0) . ' |';
    $md[] = '| Expected permissions | ' . (int)($counts['expected_permissions'] ?? 0) . ' |';
    $md[] = '| Missing in DB | ' . (int)($counts['missing_in_db'] ?? 0) . ' |';
    $md[] = '| Unknown in code | ' . (int)($counts['unknown_in_code'] ?? 0) . ' |';
    $md[] = '| Duplicate permission slugs | ' . (int)($counts['duplicate_slugs'] ?? 0) . ' |';
    $md[] = '| Orphan role_permissions rows | ' . (int)($counts['orphan_role_permissions'] ?? 0) . ' |';
    $md[] = '| Roles without permissions | ' . (int)($counts['roles_without_permissions'] ?? 0) . ' |';
    $md[] = '';

    $missingInDb = is_array($details['missing_in_db'] ?? null) ? $details['missing_in_db'] : [];
    $unknownInCode = is_array($details['unknown_in_code'] ?? null) ? $details['unknown_in_code'] : [];
    $duplicateSlugs = is_array($details['duplicate_slugs'] ?? null) ? $details['duplicate_slugs'] : [];
    $rolesWithoutPermissions = is_array($details['roles_without_permissions'] ?? null) ? $details['roles_without_permissions'] : [];
    $roleMatrix = is_array($details['role_permission_matrix'] ?? null) ? $details['role_permission_matrix'] : [];

    $md[] = '## Missing In DB';
    $md[] = '';
    if ($missingInDb === []) {
        $md[] = '- None';
    } else {
        foreach ($missingInDb as $slug) {
            $md[] = '- `' . (string)$slug . '`';
        }
    }
    $md[] = '';

    $md[] = '## Unknown In Code';
    $md[] = '';
    if ($unknownInCode === []) {
        $md[] = '- None';
    } else {
        foreach ($unknownInCode as $slug) {
            $md[] = '- `' . (string)$slug . '`';
        }
    }
    $md[] = '';

    $md[] = '## Duplicate Permission Slugs';
    $md[] = '';
    if ($duplicateSlugs === []) {
        $md[] = '- None';
    } else {
        foreach ($duplicateSlugs as $row) {
            $slug = (string)($row['slug'] ?? '');
            $count = (int)($row['count'] ?? 0);
            $md[] = '- `' . $slug . '` => ' . $count;
        }
    }
    $md[] = '';

    $md[] = '## Roles Without Permissions';
    $md[] = '';
    if ($rolesWithoutPermissions === []) {
        $md[] = '- None';
    } else {
        foreach ($rolesWithoutPermissions as $row) {
            $role = (string)($row['role_name'] ?? ('role#' . (string)($row['role_id'] ?? 'unknown')));
            $md[] = '- `' . $role . '`';
        }
    }
    $md[] = '';

    $md[] = '## Role Permission Matrix';
    $md[] = '';
    if ($roleMatrix === []) {
        $md[] = '- No roles found.';
    } else {
        foreach ($roleMatrix as $role => $permissions) {
            $permissions = is_array($permissions) ? $permissions : [];
            $md[] = '- **' . (string)$role . '**: ' . ($permissions === [] ? '_none_' : '`' . implode('`, `', $permissions) . '`');
        }
    }
    $md[] = '';

    return implode(PHP_EOL, $md) . PHP_EOL;
}

try {
    /** @var array<string,string|false>|false $options */
    $options = getopt('', ['output-json::', 'output-md::', 'strict']);
    $defaultOutputDir = dirname(__DIR__, 2) . '/storage/logs';
    $outputJson = is_array($options) && isset($options['output-json']) && is_string($options['output-json'])
        ? $options['output-json']
        : $defaultOutputDir . '/permissions-drift-report.json';
    $outputMd = is_array($options) && isset($options['output-md']) && is_string($options['output-md'])
        ? $options['output-md']
        : $defaultOutputDir . '/permissions-drift-report.md';
    $strict = is_array($options) && array_key_exists('strict', $options);

    $db = Database::connect();
    $driver = Database::currentDriver();

    $dbPermissions = wbgl_fetch_all($db, "
        SELECT id, slug, name
        FROM permissions
        ORDER BY slug ASC
    ");
    $dbSlugs = [];
    foreach ($dbPermissions as $row) {
        $slug = trim((string)($row['slug'] ?? ''));
        if ($slug !== '') {
            $dbSlugs[$slug] = true;
        }
    }
    $dbSlugs = array_keys($dbSlugs);
    sort($dbSlugs);

    $duplicateSlugs = wbgl_fetch_all($db, "
        SELECT slug, COUNT(*) AS count
        FROM permissions
        GROUP BY slug
        HAVING COUNT(*) > 1
        ORDER BY slug ASC
    ");

    $orphanRolePermissions = wbgl_fetch_int($db, "
        SELECT COUNT(*)
        FROM role_permissions rp
        LEFT JOIN roles r ON r.id = rp.role_id
        LEFT JOIN permissions p ON p.id = rp.permission_id
        WHERE r.id IS NULL OR p.id IS NULL
    ");

    $rolesWithoutPermissions = wbgl_fetch_all($db, "
        SELECT r.id AS role_id, r.name AS role_name
        FROM roles r
        LEFT JOIN role_permissions rp ON rp.role_id = r.id
        GROUP BY r.id, r.name
        HAVING COUNT(rp.permission_id) = 0
        ORDER BY r.name ASC
    ");

    $rolePermissionRows = wbgl_fetch_all($db, "
        SELECT r.name AS role_name, p.slug AS permission_slug
        FROM roles r
        LEFT JOIN role_permissions rp ON rp.role_id = r.id
        LEFT JOIN permissions p ON p.id = rp.permission_id
        ORDER BY r.name ASC, p.slug ASC
    ");

    /** @var array<string,array<int,string>> $rolePermissionMatrix */
    $rolePermissionMatrix = [];
    foreach ($rolePermissionRows as $row) {
        $roleName = trim((string)($row['role_name'] ?? ''));
        $permissionSlug = trim((string)($row['permission_slug'] ?? ''));
        if ($roleName === '') {
            $roleName = 'role#unknown';
        }
        if (!array_key_exists($roleName, $rolePermissionMatrix)) {
            $rolePermissionMatrix[$roleName] = [];
        }
        if ($permissionSlug !== '') {
            $rolePermissionMatrix[$roleName][] = $permissionSlug;
        }
    }
    foreach ($rolePermissionMatrix as $roleName => $permissions) {
        $unique = array_values(array_unique($permissions));
        sort($unique);
        $rolePermissionMatrix[$roleName] = $unique;
    }
    ksort($rolePermissionMatrix);

    $expected = [];
    foreach (array_keys(PermissionCapabilityCatalog::all()) as $slug) {
        $expected[$slug] = true;
    }
    foreach (array_values(UiPolicy::capabilityMap()) as $slug) {
        $slug = trim((string)$slug);
        if ($slug !== '') {
            $expected[$slug] = true;
        }
    }
    foreach (ApiPolicyMatrix::all() as $policy) {
        $slug = trim((string)($policy['permission'] ?? ''));
        if ($slug !== '') {
            $expected[$slug] = true;
        }
    }
    $expectedSlugs = array_keys($expected);
    sort($expectedSlugs);

    $missingInDb = array_values(array_diff($expectedSlugs, $dbSlugs));
    $unknownInCode = array_values(array_diff($dbSlugs, $expectedSlugs));
    sort($missingInDb);
    sort($unknownInCode);

    $failures = [];
    $warnings = [];

    if ($missingInDb !== []) {
        $failures[] = 'Missing required permission slugs in DB: ' . implode(', ', $missingInDb);
    }
    if ($duplicateSlugs !== []) {
        $failures[] = 'Duplicate permission slugs exist in permissions table.';
    }
    if ($orphanRolePermissions > 0) {
        $failures[] = 'Orphan rows exist in role_permissions.';
    }

    if ($unknownInCode !== []) {
        $warnings[] = 'DB contains permission slugs not referenced by code metadata.';
    }
    if ($rolesWithoutPermissions !== []) {
        $warnings[] = 'One or more roles have zero permissions.';
    }

    if ($strict && $warnings !== []) {
        foreach ($warnings as $warning) {
            $failures[] = 'STRICT: ' . $warning;
        }
    }

    $status = $failures !== [] ? 'fail' : ($warnings !== [] ? 'warn' : 'pass');
    $report = [
        'generated_at' => gmdate('c'),
        'driver' => $driver,
        'status' => $status,
        'counts' => [
            'db_permissions' => count($dbSlugs),
            'expected_permissions' => count($expectedSlugs),
            'missing_in_db' => count($missingInDb),
            'unknown_in_code' => count($unknownInCode),
            'duplicate_slugs' => count($duplicateSlugs),
            'orphan_role_permissions' => $orphanRolePermissions,
            'roles_without_permissions' => count($rolesWithoutPermissions),
        ],
        'failures' => $failures,
        'warnings' => $warnings,
        'details' => [
            'missing_in_db' => $missingInDb,
            'unknown_in_code' => $unknownInCode,
            'duplicate_slugs' => $duplicateSlugs,
            'roles_without_permissions' => $rolesWithoutPermissions,
            'role_permission_matrix' => $rolePermissionMatrix,
        ],
    ];

    $outputJsonDir = dirname($outputJson);
    if (!is_dir($outputJsonDir) && !mkdir($outputJsonDir, 0777, true) && !is_dir($outputJsonDir)) {
        throw new RuntimeException('Failed to create output directory: ' . $outputJsonDir);
    }
    $outputMdDir = dirname($outputMd);
    if (!is_dir($outputMdDir) && !mkdir($outputMdDir, 0777, true) && !is_dir($outputMdDir)) {
        throw new RuntimeException('Failed to create output directory: ' . $outputMdDir);
    }

    file_put_contents(
        $outputJson,
        json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL
    );
    file_put_contents($outputMd, wbgl_render_markdown($report));

    echo 'WBGL Permissions Drift Report' . PHP_EOL;
    echo 'Driver: ' . $driver . PHP_EOL;
    echo 'Expected permissions: ' . count($expectedSlugs) . PHP_EOL;
    echo 'DB permissions: ' . count($dbSlugs) . PHP_EOL;
    echo 'Missing in DB: ' . count($missingInDb) . PHP_EOL;
    echo 'Unknown in code: ' . count($unknownInCode) . PHP_EOL;
    echo 'Duplicate slugs: ' . count($duplicateSlugs) . PHP_EOL;
    echo 'Orphan role_permissions: ' . $orphanRolePermissions . PHP_EOL;
    echo 'Roles without permissions: ' . count($rolesWithoutPermissions) . PHP_EOL;
    echo 'Status: ' . strtoupper($status) . PHP_EOL;
    echo 'JSON report: ' . $outputJson . PHP_EOL;
    echo 'Markdown report: ' . $outputMd . PHP_EOL;

    if ($failures !== []) {
        foreach ($failures as $failure) {
            fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
        }
        exit(1);
    }

    foreach ($warnings as $warning) {
        echo 'WARN: ' . $warning . PHP_EOL;
    }

    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'permissions-drift-report crashed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
