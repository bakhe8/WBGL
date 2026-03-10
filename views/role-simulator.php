<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Repositories\RoleRepository;
use App\Services\ActionabilityPolicyService;
use App\Services\UiSurfacePolicyService;
use App\Support\AuthService;
use App\Support\AssetVersion;
use App\Support\Database;
use App\Support\DirectionResolver;
use App\Support\LocaleResolver;
use App\Support\Settings;
use App\Support\UiPolicy;
use App\Support\ViewPolicy;

ViewPolicy::guardView('role-simulator.php');

$db = Database::connect();
$assetVersion = static fn(string $path): string => rawurlencode(AssetVersion::forPath($path));
$settings = Settings::getInstance();
$currentUser = AuthService::getCurrentUser();
$localeInfo = LocaleResolver::resolve(
    $currentUser,
    $settings,
    $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null
);
$pageLocale = (string)($localeInfo['locale'] ?? 'ar');
$directionInfo = DirectionResolver::resolve(
    $pageLocale,
    $currentUser?->preferredDirection ?? 'auto',
    (string)$settings->get('DEFAULT_DIRECTION', 'auto')
);
$pageDirection = (string)($directionInfo['direction'] ?? ($pageLocale === 'ar' ? 'rtl' : 'ltr'));

$roleSimulatorLocalePrimary = [];
$roleSimulatorLocaleFallback = [];
$roleSimulatorPrimaryPath = __DIR__ . '/../public/locales/' . $pageLocale . '/role_simulator.json';
$roleSimulatorFallbackPath = __DIR__ . '/../public/locales/ar/role_simulator.json';
if (is_file($roleSimulatorPrimaryPath)) {
    $decodedLocale = json_decode((string)file_get_contents($roleSimulatorPrimaryPath), true);
    if (is_array($decodedLocale)) {
        $roleSimulatorLocalePrimary = $decodedLocale;
    }
}
if (is_file($roleSimulatorFallbackPath)) {
    $decodedLocale = json_decode((string)file_get_contents($roleSimulatorFallbackPath), true);
    if (is_array($decodedLocale)) {
        $roleSimulatorLocaleFallback = $decodedLocale;
    }
}
$roleSimulatorT = static function (string $key, ?string $fallback = null) use (
    $roleSimulatorLocalePrimary,
    $roleSimulatorLocaleFallback
): string {
    $value = $roleSimulatorLocalePrimary[$key] ?? $roleSimulatorLocaleFallback[$key] ?? null;
    if (!is_string($value) || trim($value) === '') {
        return $fallback ?? $key;
    }
    return $value;
};

$roleRepo = new RoleRepository($db);
$roles = $roleRepo->all();

$selectedRoleSlug = trim((string)($_GET['role'] ?? ''));
if ($selectedRoleSlug === '' && !empty($roles)) {
    $selectedRoleSlug = (string)$roles[0]->slug;
}

$selectedRole = null;
foreach ($roles as $roleItem) {
    if ((string)$roleItem->slug === $selectedRoleSlug) {
        $selectedRole = $roleItem;
        break;
    }
}

$permissions = [];
if ($selectedRole !== null && $selectedRole->id !== null) {
    $permissions = $roleRepo->getPermissions((int)$selectedRole->id);
}
$permissions = array_values(array_unique(array_filter(array_map(
    static fn($permission): string => trim((string)$permission),
    $permissions
), static fn(string $permission): bool => $permission !== '')));

$stagePermissions = ActionabilityPolicyService::STAGE_PERMISSION_MAP;
$allowedStages = [];
foreach ($stagePermissions as $stage => $permissionSlug) {
    if (in_array($permissionSlug, $permissions, true) || in_array('*', $permissions, true)) {
        $allowedStages[] = $stage;
    }
}

$capabilities = UiPolicy::capabilityMap();
ksort($capabilities);

$sampleStates = [
    [
        'label_key' => 'role_simulator.state.ready_without_action',
        'status' => 'ready',
        'workflow_step' => 'draft',
        'active_action' => '',
        'is_locked' => false,
    ],
    [
        'label_key' => 'role_simulator.state.ready_release_waiting_audit',
        'status' => 'ready',
        'workflow_step' => 'draft',
        'active_action' => 'release',
        'is_locked' => false,
    ],
    [
        'label_key' => 'role_simulator.state.in_workflow_analysis',
        'status' => 'ready',
        'workflow_step' => 'analyzed',
        'active_action' => 'release',
        'is_locked' => false,
    ],
    [
        'label_key' => 'role_simulator.state.signed_before_lock',
        'status' => 'ready',
        'workflow_step' => 'signed',
        'active_action' => 'extension',
        'is_locked' => false,
    ],
    [
        'label_key' => 'role_simulator.state.released_finalized',
        'status' => 'released',
        'workflow_step' => 'signed',
        'active_action' => 'release',
        'is_locked' => true,
    ],
];

$stateEvaluations = [];
foreach ($sampleStates as $state) {
    $policy = ActionabilityPolicyService::evaluate($state, true, $permissions)->toArray();
    $surface = UiSurfacePolicyService::forGuarantee($policy, $permissions, $state['status']);
    $stateEvaluations[] = [
        'state' => $state,
        'policy' => $policy,
        'surface' => $surface,
    ];
}

if (!function_exists('wbgl_role_simulator_stage_label')) {
    function wbgl_role_simulator_stage_label(string $stage, callable $t): string
    {
        return match ($stage) {
            'draft' => $t('role_simulator.stage.draft', 'draft'),
            'audited' => $t('role_simulator.stage.audited', 'audited'),
            'analyzed' => $t('role_simulator.stage.analyzed', 'analyzed'),
            'supervised' => $t('role_simulator.stage.supervised', 'supervised'),
            'approved' => $t('role_simulator.stage.approved', 'approved'),
            'signed' => $t('role_simulator.stage.signed', 'signed'),
            default => $stage,
        };
    }
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($pageLocale, ENT_QUOTES, 'UTF-8') ?>" dir="<?= htmlspecialchars($pageDirection, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-i18n="role_simulator.page_title"><?= htmlspecialchars($roleSimulatorT('role_simulator.page_title', 'Role Simulator | WBGL'), ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="../public/css/design-system.css?v=<?= $assetVersion('public/css/design-system.css') ?>">
    <link rel="stylesheet" href="../public/css/themes.css?v=<?= $assetVersion('public/css/themes.css') ?>">
    <link rel="stylesheet" href="../public/css/components.css?v=<?= $assetVersion('public/css/components.css') ?>">
    <link rel="stylesheet" href="../public/css/layout.css?v=<?= $assetVersion('public/css/layout.css') ?>">
    <style>
        .sim-shell {
            max-width: 1500px;
            margin: 0 auto;
            padding: 20px;
        }
        .sim-card {
            background: var(--bg-card);
            border: 1px solid var(--border-primary);
            border-radius: 14px;
            padding: 16px;
            margin-bottom: 16px;
        }
        .sim-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 12px;
        }
        .sim-table {
            width: 100%;
            border-collapse: collapse;
        }
        .sim-table th,
        .sim-table td {
            border-bottom: 1px solid var(--border-primary);
            padding: 10px;
            font-size: 13px;
            text-align: right;
            vertical-align: top;
        }
        .sim-table th {
            background: var(--bg-hover);
            font-weight: 700;
        }
        .chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border-radius: 999px;
            border: 1px solid var(--border-primary);
            padding: 4px 8px;
            font-size: 12px;
            margin: 2px;
            background: var(--bg-hover);
        }
        .chip.ok {
            background: var(--accent-success-light, #ecfdf5);
            border-color: var(--accent-success, #86efac);
            color: var(--accent-success, #166534);
        }
        .chip.no {
            background: var(--accent-danger-light, #fef2f2);
            border-color: var(--accent-danger, #fca5a5);
            color: var(--accent-danger, #991b1b);
        }
        .mono {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
        }
        .hint {
            color: var(--text-muted);
            font-size: 12px;
        }
    </style>
</head>
<body data-i18n-namespaces="common,role_simulator">
<?php include __DIR__ . '/../partials/unified-header.php'; ?>

<main class="sim-shell">
    <section class="sim-card">
        <h1 style="margin-top:0;" data-i18n="role_simulator.heading"><?= htmlspecialchars($roleSimulatorT('role_simulator.heading', 'Role Simulator (QA)'), ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="hint" data-i18n="role_simulator.description"><?= htmlspecialchars($roleSimulatorT('role_simulator.description', 'محاكاة نتائج الصلاحيات على الحالة التشغيلية والسطح المرئي بدون تعديل أي بيانات.'), ENT_QUOTES, 'UTF-8') ?></p>
        <form method="GET" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
            <label>
                <span class="hint" data-i18n="role_simulator.filters.role"><?= htmlspecialchars($roleSimulatorT('role_simulator.filters.role', 'الدور'), ENT_QUOTES, 'UTF-8') ?></span>
                <select name="role" class="form-select">
                    <?php foreach ($roles as $role): ?>
                        <option value="<?= htmlspecialchars((string)$role->slug, ENT_QUOTES, 'UTF-8') ?>" <?= (string)$role->slug === $selectedRoleSlug ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string)$role->name, ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars((string)$role->slug, ENT_QUOTES, 'UTF-8') ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="btn btn-primary" data-i18n="role_simulator.actions.update"><?= htmlspecialchars($roleSimulatorT('role_simulator.actions.update', 'تحديث المحاكاة'), ENT_QUOTES, 'UTF-8') ?></button>
        </form>
    </section>

    <section class="sim-card sim-grid">
        <div>
            <h3 data-i18n="role_simulator.sections.allowed_stages"><?= htmlspecialchars($roleSimulatorT('role_simulator.sections.allowed_stages', 'مراحل السير المسموحة'), ENT_QUOTES, 'UTF-8') ?></h3>
            <?php if (empty($allowedStages)): ?>
                <span class="chip no" data-i18n="role_simulator.messages.no_allowed_stages"><?= htmlspecialchars($roleSimulatorT('role_simulator.messages.no_allowed_stages', 'لا توجد مراحل مسموحة'), ENT_QUOTES, 'UTF-8') ?></span>
            <?php else: ?>
                <?php foreach ($allowedStages as $stage): ?>
                    <span class="chip ok mono"><?= htmlspecialchars(wbgl_role_simulator_stage_label((string)$stage, $roleSimulatorT), ENT_QUOTES, 'UTF-8') ?></span>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div>
            <h3><?= htmlspecialchars($roleSimulatorT('role_simulator.sections.role_permissions', 'صلاحيات الدور'), ENT_QUOTES, 'UTF-8') ?> (<?= count($permissions) ?>)</h3>
            <?php if (empty($permissions)): ?>
                <span class="chip no" data-i18n="role_simulator.messages.no_permissions"><?= htmlspecialchars($roleSimulatorT('role_simulator.messages.no_permissions', 'لا توجد صلاحيات'), ENT_QUOTES, 'UTF-8') ?></span>
            <?php else: ?>
                <?php foreach ($permissions as $permission): ?>
                    <span class="chip mono"><?= htmlspecialchars((string)$permission, ENT_QUOTES, 'UTF-8') ?></span>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>

    <section class="sim-card">
        <h3 data-i18n="role_simulator.sections.surface_policy"><?= htmlspecialchars($roleSimulatorT('role_simulator.sections.surface_policy', 'سياسة السطح المرئي حسب سيناريو الحالة'), ENT_QUOTES, 'UTF-8') ?></h3>
        <table class="sim-table">
            <thead>
                <tr>
                    <th><?= htmlspecialchars($roleSimulatorT('role_simulator.table.scenario', 'السيناريو'), ENT_QUOTES, 'UTF-8') ?></th>
                    <th><?= htmlspecialchars($roleSimulatorT('role_simulator.table.inputs', 'المدخلات'), ENT_QUOTES, 'UTF-8') ?></th>
                    <th><?= htmlspecialchars($roleSimulatorT('role_simulator.table.policy_tuple', 'Policy (visible/actionable/executable)'), ENT_QUOTES, 'UTF-8') ?></th>
                    <th><?= htmlspecialchars($roleSimulatorT('role_simulator.table.surface_tuple', 'Surface (execute/preview/attachments)'), ENT_QUOTES, 'UTF-8') ?></th>
                    <th><?= htmlspecialchars($roleSimulatorT('role_simulator.table.reasons', 'Reasons'), ENT_QUOTES, 'UTF-8') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($stateEvaluations as $evaluation): ?>
                    <?php
                    $state = $evaluation['state'];
                    $policy = $evaluation['policy'];
                    $surface = $evaluation['surface'];
                    $policyTuple = implode(' / ', [
                        !empty($policy['visible']) ? '1' : '0',
                        !empty($policy['actionable']) ? '1' : '0',
                        !empty($policy['executable']) ? '1' : '0',
                    ]);
                    $surfaceTuple = implode(' / ', [
                        !empty($surface['can_execute_actions']) ? '1' : '0',
                        !empty($surface['can_view_preview']) ? '1' : '0',
                        !empty($surface['can_upload_attachments']) ? '1' : '0',
                    ]);
                    $reasons = is_array($policy['reasons'] ?? null) ? $policy['reasons'] : [];
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($roleSimulatorT((string)$state['label_key']), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="mono">
                            status=<?= htmlspecialchars((string)$state['status'], ENT_QUOTES, 'UTF-8') ?><br>
                            workflow=<?= htmlspecialchars((string)$state['workflow_step'], ENT_QUOTES, 'UTF-8') ?><br>
                            action=<?= htmlspecialchars((string)$state['active_action'], ENT_QUOTES, 'UTF-8') ?><br>
                            locked=<?= !empty($state['is_locked']) ? '1' : '0' ?>
                        </td>
                        <td class="mono"><?= htmlspecialchars($policyTuple, ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="mono"><?= htmlspecialchars($surfaceTuple, ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="mono">
                            <?php if (empty($reasons)): ?>
                                —
                            <?php else: ?>
                                <?= htmlspecialchars(implode(', ', array_map('strval', $reasons)), ENT_QUOTES, 'UTF-8') ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="sim-card">
        <h3 data-i18n="role_simulator.sections.capability_matrix"><?= htmlspecialchars($roleSimulatorT('role_simulator.sections.capability_matrix', 'Capability Matrix (from UiPolicy)'), ENT_QUOTES, 'UTF-8') ?></h3>
        <table class="sim-table">
            <thead>
                <tr>
                    <th><?= htmlspecialchars($roleSimulatorT('role_simulator.capability_table.capability', 'Capability'), ENT_QUOTES, 'UTF-8') ?></th>
                    <th><?= htmlspecialchars($roleSimulatorT('role_simulator.capability_table.required_permission', 'Required permission'), ENT_QUOTES, 'UTF-8') ?></th>
                    <th><?= htmlspecialchars($roleSimulatorT('role_simulator.capability_table.role_has_permission', 'Role has permission'), ENT_QUOTES, 'UTF-8') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($capabilities as $capability => $requiredPermission): ?>
                    <?php $hasPermission = in_array($requiredPermission, $permissions, true) || in_array('*', $permissions, true); ?>
                    <tr>
                        <td class="mono"><?= htmlspecialchars((string)$capability, ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="mono"><?= htmlspecialchars((string)$requiredPermission, ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <span class="chip <?= $hasPermission ? 'ok' : 'no' ?>">
                                <?= $hasPermission
                                    ? htmlspecialchars($roleSimulatorT('role_simulator.common.yes', 'Yes'), ENT_QUOTES, 'UTF-8')
                                    : htmlspecialchars($roleSimulatorT('role_simulator.common.no', 'No'), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</main>
</body>
</html>
