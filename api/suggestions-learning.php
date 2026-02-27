<?php

/**
 * API Endpoint: Supplier Suggestions (Learning-based)
 * 
 * ✅ PHASE 4 COMPLETE: Using UnifiedLearningAuthority
 * 
 * This endpoint provides as-you-type supplier suggestions.
 * Returns canonical SuggestionDTO[] in JSON format.
 */

header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/_bootstrap.php';

use App\Services\Learning\AuthorityFactory;
wbgl_api_require_login();

// Get input
$rawInput = $_GET['raw'] ?? '';

if (empty($rawInput)) {
    echo json_encode(['success' => false, 'error' => 'raw parameter required']);
    exit;
}

try {
    // ✅ PHASE 4: Using UnifiedLearningAuthority
    $authority = AuthorityFactory::create();
    $suggestionDTOs = $authority->getSuggestions($rawInput);
    
    // 🔥 LIMIT: Show only top 10 highest confidence suggestions
    $suggestionDTOs = array_slice($suggestionDTOs, 0, 10);
    
    $suggestions = array_map(static fn($dto) => $dto->toArray(), $suggestionDTOs);
    
    // Return HTML Fragment
    include __DIR__ . '/../partials/suggestions.php';
    
} catch (\Exception $e) {
    http_response_code(500);
    echo '<div style="color:red">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
