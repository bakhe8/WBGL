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
    $index = isset($_GET['index']) ? (int)$_GET['index'] : 1;
    $statusFilter = isset($_GET['filter']) ? trim((string)$_GET['filter']) : (isset($_GET['status_filter']) ? trim((string)$_GET['status_filter']) : 'all');
    $searchTerm = isset($_GET['search']) ? trim((string)$_GET['search']) : null;
    if ($searchTerm === '') {
        $searchTerm = null;
    }

    if ($index < 1) {
        throw new \RuntimeException('Invalid index');
    }

    $db = Database::connect();

    $guaranteeId = \App\Services\NavigationService::getIdByIndex(
        $db,
        $index,
        $statusFilter,
        $searchTerm
    );

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
