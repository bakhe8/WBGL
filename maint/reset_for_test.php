<?php
require_once __DIR__ . '/../app/Support/autoload.php';
$db = \App\Support\Database::connect();
$stmt = $db->query("SELECT id FROM guarantees LIMIT 1");
$id = $stmt->fetchColumn();
if ($id) {
    $db->exec("UPDATE guarantee_decisions SET workflow_step = 'draft', signatures_received = 0 WHERE guarantee_id = $id");
    echo "Reset guarantee ID $id to draft\n";
} else {
    echo "No guarantees found\n";
}
