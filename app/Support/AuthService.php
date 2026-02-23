<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use App\Repositories\UserRepository;
use App\Repositories\RoleRepository;

/**
 * AuthService
 * Handles user authentication and session management
 */
class AuthService
{
    private static ?User $currentUser = null;

    /**
     * Attempt to login a user
     */
    public static function login(string $username, string $password): bool
    {
        $db = Database::connect();
        $repo = new UserRepository($db);
        $user = $repo->findByUsername($username);

        if ($user && password_verify($password, $user->passwordHash)) {
            $_SESSION['user_id'] = $user->id;
            $_SESSION['username'] = $user->username;
            self::$currentUser = $user;
            $repo->updateLastLogin($user->id);
            return true;
        }

        return false;
    }

    /**
     * Logout current user
     */
    public static function logout(): void
    {
        unset($_SESSION['user_id']);
        unset($_SESSION['username']);
        self::$currentUser = null;
    }

    /**
     * Get currently logged in user
     */
    public static function getCurrentUser(): ?User
    {
        if (self::$currentUser !== null) {
            return self::$currentUser;
        }

        if (isset($_SESSION['user_id'])) {
            $db = Database::connect();
            $repo = new UserRepository($db);
            self::$currentUser = $repo->find((int)$_SESSION['user_id']);
            return self::$currentUser;
        }

        return null;
    }

    /**
     * Check if a user is logged in
     */
    public static function isLoggedIn(): bool
    {
        return isset($_SESSION['user_id']);
    }
}
