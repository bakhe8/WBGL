<?php

/**
 * Admin User Seeding Script
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Repositories\RoleRepository;

try {
    $db = Database::connect();
    $userRepo = new UserRepository($db);
    $roleRepo = new RoleRepository($db);

    $username = 'admin';
    $password = 'admin123';
    $fullName = 'مدير النظام (مطور)';

    // Check if role exists
    $role = $roleRepo->findBySlug('developer');
    if (!$role) {
        throw new \Exception("Role 'developer' not found. Please run create_rbac_tables.php first.");
    }

    // Check if user already exists
    if ($userRepo->findByUsername($username)) {
        echo "User 'admin' already exists.\n";
        exit;
    }

    $user = new User(
        null,
        $username,
        password_hash($password, PASSWORD_DEFAULT),
        $fullName,
        'admin@example.com',
        $role->id
    );

    $createdUser = $userRepo->create($user);

    echo "Admin user created successfully!\n";
    echo "Username: " . $createdUser->username . "\n";
    echo "Password: [HIDDEN]\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
