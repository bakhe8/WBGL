<?php
require_once __DIR__ . '/../app/Support/autoload.php';
$db = \App\Support\Database::connect();

$users = [
    ['username' => 'auditor', 'password' => 'pass123', 'name' => 'Data Auditor', 'role' => 'data_auditor', 'email' => 'auditor@example.com'],
    ['username' => 'analyst1', 'password' => 'pass123', 'name' => 'Guarantee Analyst', 'role' => 'analyst', 'email' => 'analyst@example.com'],
    ['username' => 'superv', 'password' => 'pass123', 'name' => 'Supervisor User', 'role' => 'supervisor', 'email' => 'super@example.com'],
    ['username' => 'manager', 'password' => 'pass123', 'name' => 'Approving Manager', 'role' => 'approver', 'email' => 'manager@example.com'],
    ['username' => 'sig_user', 'password' => 'pass123', 'name' => 'Signatory Master', 'role' => 'signatory', 'email' => 'sig@example.com'],
];

foreach ($users as $u) {
    // Get role ID
    $stmt = $db->prepare("SELECT id FROM roles WHERE slug = ?");
    $stmt->execute([$u['role']]);
    $roleId = $stmt->fetchColumn();

    if (!$roleId) {
        echo "Error: Role {$u['role']} not found\n";
        continue;
    }

    $hash = password_hash($u['password'], PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO users (username, password_hash, full_name, email, role_id) VALUES (?, ?, ?, ?, ?) ON CONFLICT (username) DO NOTHING");
    $stmt->execute([$u['username'], $hash, $u['name'], $u['email'], $roleId]);
    echo "Created user: {$u['username']}\n";
}
