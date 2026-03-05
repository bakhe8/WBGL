<?php
declare(strict_types=1);

namespace App\Support;

use App\Services\BatchAccessPolicyService;
use App\Repositories\RoleRepository;

class ViewPolicy
{
    /**
     * View file => required permission.
     *
     * Views not listed here require login only.
     *
     * @var array<string,string>
     */
    private const VIEW_PERMISSION_MAP = [
        'users.php' => 'manage_users',
        'settings.php' => 'manage_users',
        'maintenance.php' => 'manage_users',
    ];

    /**
     * Views that are restricted to developer role slug only.
     *
     * @var string[]
     */
    private const DEVELOPER_ONLY_VIEWS = [
        'confidence-demo.php',
    ];

    /**
     * Batch operation views are role-scoped by policy:
     * default (data_entry/developer) + explicit override permission.
     *
     * @var string[]
     */
    private const BATCH_OPERATION_VIEWS = [
        'batches.php',
        'batch-detail.php',
        'batch-print.php',
    ];

    public static function requireLogin(string $redirect = '/views/login.php'): void
    {
        if (AuthService::isLoggedIn()) {
            return;
        }

        header('Location: ' . $redirect);
        exit;
    }

    public static function requirePermission(string $permission, string $redirect = '/index.php'): void
    {
        self::requireLogin();

        if (Guard::has($permission)) {
            return;
        }

        header('Location: ' . $redirect);
        exit;
    }

    public static function requiredPermissionForView(string $viewFile): ?string
    {
        $normalized = strtolower(trim(basename($viewFile)));
        return self::VIEW_PERMISSION_MAP[$normalized] ?? null;
    }

    public static function isDeveloperOnlyView(string $viewFile): bool
    {
        $normalized = strtolower(trim(basename($viewFile)));
        return in_array($normalized, self::DEVELOPER_ONLY_VIEWS, true);
    }

    public static function isCurrentUserDeveloper(): bool
    {
        $user = AuthService::getCurrentUser();
        if (!$user || $user->roleId === null) {
            return false;
        }

        try {
            $db = Database::connect();
            $roleRepo = new RoleRepository($db);
            $role = $roleRepo->find($user->roleId);
            return strtolower(trim((string)($role->slug ?? ''))) === 'developer';
        } catch (\Throwable) {
            return false;
        }
    }

    public static function guardView(
        string $viewFile,
        string $loginRedirect = '/views/login.php',
        string $forbiddenRedirect = '/index.php'
    ): void {
        self::requireLogin($loginRedirect);

        $normalized = strtolower(trim(basename($viewFile)));
        if (in_array($normalized, self::BATCH_OPERATION_VIEWS, true) && !BatchAccessPolicyService::canAccessBatchSurfaces()) {
            header('Location: ' . $forbiddenRedirect);
            exit;
        }

        if (self::isDeveloperOnlyView($viewFile) && !self::isCurrentUserDeveloper()) {
            header('Location: ' . $forbiddenRedirect);
            exit;
        }

        $permission = self::requiredPermissionForView($viewFile);
        if ($permission === null) {
            return;
        }

        if (!Guard::has($permission)) {
            header('Location: ' . $forbiddenRedirect);
            exit;
        }
    }
}
