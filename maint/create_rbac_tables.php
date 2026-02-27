<?php

/**
 * RBAC Database Initialization Script
 * Creates tables for Users, Roles, and Permissions
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;

try {
    $db = Database::connect();

    echo "Starting RBAC table initialization...\n";

    // Start transaction
    $db->beginTransaction();

    // 1. Roles table
    $db->exec("CREATE TABLE IF NOT EXISTS roles (
        id BIGSERIAL PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        slug VARCHAR(255) NOT NULL UNIQUE,
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    echo "- Table 'roles' created.\n";

    // 2. Permissions table
    $db->exec("CREATE TABLE IF NOT EXISTS permissions (
        id BIGSERIAL PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        slug VARCHAR(255) NOT NULL UNIQUE,
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    echo "- Table 'permissions' created.\n";

    // 3. Users table (updated with role_id)
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id BIGSERIAL PRIMARY KEY,
        username VARCHAR(255) NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        full_name VARCHAR(255) NOT NULL,
        email VARCHAR(255) UNIQUE,
        role_id INTEGER,
        last_login TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (role_id) REFERENCES roles(id)
    )");
    echo "- Table 'users' created.\n";

    // 4. Role-Permissions mapping
    $db->exec("CREATE TABLE IF NOT EXISTS role_permissions (
        role_id INTEGER,
        permission_id INTEGER,
        PRIMARY KEY (role_id, permission_id),
        FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
        FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
    )");
    echo "- Table 'role_permissions' created.\n";

    // 5. Seed initial roles (The 7 levels)
    $roles = [
        ['name' => 'مطور النظام', 'slug' => 'developer'],
        ['name' => 'مدخل بيانات', 'slug' => 'data_entry'],
        ['name' => 'مدقق بيانات', 'slug' => 'data_auditor'],
        ['name' => 'محلل ضمانات', 'slug' => 'analyst'],
        ['name' => 'مشرف ضمانات', 'slug' => 'supervisor'],
        ['name' => 'مدير معتمد', 'slug' => 'approver'],
        ['name' => 'المفوض بالتوقيع', 'slug' => 'signatory'],
    ];

    $stmt = $db->prepare("INSERT INTO roles (name, slug) VALUES (?, ?) ON CONFLICT (slug) DO NOTHING");
    foreach ($roles as $role) {
        $stmt->execute([$role['name'], $role['slug']]);
    }
    echo "- Seeded 7 roles levels.\n";

    // 6. Seed initial permissions
    $permissions = [
        ['name' => 'استيراد إكسل', 'slug' => 'import_excel'],
        ['name' => 'إدراج يدوي', 'slug' => 'manual_entry'],
        ['name' => 'تصحيح بيانات', 'slug' => 'manage_data'],
        ['name' => 'تدقيق البيانات', 'slug' => 'audit_data'],
        ['name' => 'تحليل الضمانات', 'slug' => 'analyze_guarantee'],
        ['name' => 'الإشراف على التحليل', 'slug' => 'supervise_analysis'],
        ['name' => 'اعتماد القرار المالي', 'slug' => 'approve_decision'],
        ['name' => 'توقيع الخطابات', 'slug' => 'sign_letters'],
        ['name' => 'إدارة المستخدمين', 'slug' => 'manage_users'],
        ['name' => 'إعادة فتح الدفعات', 'slug' => 'reopen_batch'],
        ['name' => 'إعادة فتح الضمانات', 'slug' => 'reopen_guarantee'],
        ['name' => 'تجاوز الطوارئ', 'slug' => 'break_glass_override'],
    ];

    $stmt = $db->prepare("INSERT INTO permissions (name, slug) VALUES (?, ?) ON CONFLICT (slug) DO NOTHING");
    foreach ($permissions as $perm) {
        $stmt->execute([$perm['name'], $perm['slug']]);
    }
    echo "- Seeded base permissions.\n";

    $db->commit();
    echo "\nSuccess: RBAC infrastructure is ready.\n";
} catch (\Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    echo "Error: " . $e->getMessage() . "\n";
}
