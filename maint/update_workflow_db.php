<?php
require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;

try {
    $db = Database::connect();
    echo "Updating guarantee_decisions schema...\n";

    $stmt = $db->query("
        SELECT column_name
        FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'guarantee_decisions'
    ");
    $cols = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];

    if (!in_array('workflow_step', $cols)) {
        $db->exec("ALTER TABLE guarantee_decisions ADD COLUMN workflow_step VARCHAR(50) DEFAULT 'draft'");
        echo "- Column 'workflow_step' added.\n";
    } else {
        echo "- Column 'workflow_step' already exists.\n";
    }

    if (!in_array('signatures_received', $cols)) {
        $db->exec("ALTER TABLE guarantee_decisions ADD COLUMN signatures_received INTEGER DEFAULT 0");
        echo "- Column 'signatures_received' added.\n";
    }

    echo "Success: Database schema updated for RBAC Workflow.\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
