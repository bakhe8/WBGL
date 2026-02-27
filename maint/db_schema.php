<?php
require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;

try {
    $db = Database::connect();
    $stmt = $db->query("
        SELECT column_name, data_type
        FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'guarantee_decisions'
        ORDER BY ordinal_position ASC
    ");
    $cols = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    echo "Schema for guarantee_decisions:\n";
    foreach ($cols as $col) {
        echo "- {$col['column_name']} ({$col['data_type']})\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
