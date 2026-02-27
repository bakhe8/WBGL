<?php
require_once __DIR__ . '/../app/Support/autoload.php';
$db = \App\Support\Database::connect();
$stmt = $db->query("SELECT u.id, u.username, u.full_name, r.slug as role FROM users u JOIN roles r ON u.role_id = r.id");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($users, JSON_PRETTY_PRINT);
