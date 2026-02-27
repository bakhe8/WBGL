<?php
declare(strict_types=1);

namespace App\Support;

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

    public static function guardView(
        string $viewFile,
        string $loginRedirect = '/views/login.php',
        string $forbiddenRedirect = '/index.php'
    ): void {
        self::requireLogin($loginRedirect);
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
