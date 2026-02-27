<?php

/**
 * RBAC Permission Seeding Script
 * Maps permissions to roles based on the Phase 3 implementation plan
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;

try {
    $db = Database::connect();
    echo "Starting permission seeding...\n";

    $db->beginTransaction();

    // 1. Get all roles indexed by slug
    $rolesStmt = $db->query("SELECT id, slug FROM roles");
    $roles = [];
    while ($row = $rolesStmt->fetch(PDO::FETCH_ASSOC)) {
        $roles[$row['slug']] = (int)$row['id'];
    }

    // 2. Get all permissions indexed by slug
    $permsStmt = $db->query("SELECT id, slug FROM permissions");
    $perms = [];
    while ($row = $permsStmt->fetch(PDO::FETCH_ASSOC)) {
        $perms[$row['slug']] = (int)$row['id'];
    }

    // 3. Define mapping
    $mapping = [
        'data_entry' => [
            'import_excel',
            'manual_entry',
            'manage_data'
        ],
        'data_auditor' => [
            'audit_data'
        ],
        'analyst' => [
            'analyze_guarantee'
        ],
        'supervisor' => [
            'supervise_analysis',
            'reopen_batch',
            'reopen_guarantee'
        ],
        'approver' => [
            'approve_decision',
            'reopen_batch',
            'reopen_guarantee',
            'break_glass_override'
        ],
        'signatory' => [
            'sign_letters'
        ],
        'developer' => [
            'reopen_batch',
            'reopen_guarantee',
            'break_glass_override'
        ]
    ];

    // Clear existing mappings to avoid duplicates if re-run
    $db->exec("DELETE FROM role_permissions");

    $insertStmt = $db->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");

    foreach ($mapping as $roleSlug => $permSlugs) {
        if (!isset($roles[$roleSlug])) {
            echo "Skipping missing role: $roleSlug\n";
            continue;
        }

        $roleId = $roles[$roleSlug];
        foreach ($permSlugs as $permSlug) {
            if (!isset($perms[$permSlug])) {
                echo "Skipping missing permission: $permSlug\n";
                continue;
            }
            $permId = $perms[$permSlug];
            $insertStmt->execute([$roleId, $permId]);
        }
        echo "Mapped permissions for role: $roleSlug\n";
    }

    // Special Case: Developer gets ALL permissions
    if (isset($roles['developer'])) {
        $devId = $roles['developer'];
        foreach ($perms as $permId) {
            $insertStmt->execute([$devId, $permId]);
        }
        echo "Mapped all permissions for role: developer\n";
    }

    $db->commit();
    echo "\nSuccess: Role permissions seeded successfully.\n";
} catch (\Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    echo "Error: " . $e->getMessage() . "\n";
}
