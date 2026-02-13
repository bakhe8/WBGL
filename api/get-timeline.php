<?php
/**
 * V3 API - Get Timeline (Server-Driven Partial HTML)
 * Returns the HTML for the timeline sidebar
 */

require_once __DIR__ . '/../app/Support/autoload.php';
require_once __DIR__ . '/../app/Services/TimelineRecorder.php';

use App\Support\Database;
use App\Services\TimelineRecorder;

header('Content-Type: text/html; charset=utf-8');

try {
    $index = $_GET['index'] ?? 1;
    $db = Database::connect();
    
    // Get guarantee ID for this index
    // Note: We use the same ordering as get-record.php to match the record
    $stmtIds = $db->query('SELECT id FROM guarantees ORDER BY imported_at DESC LIMIT 100');
    $ids = $stmtIds->fetchAll(PDO::FETCH_COLUMN);
    
    $guaranteeId = $ids[$index - 1] ?? null;
    $timeline = [];

    if ($guaranteeId) {
        $timeline = TimelineRecorder::getTimeline($guaranteeId);
    }
    
    // Use the comprehensive partial for rendering
    include __DIR__ . '/../partials/timeline-section.php';

} catch (\Throwable $e) {
    http_response_code(500);
    echo '<div style="color:red">Error loading timeline: ' . $e->getMessage() . '</div>';
}
