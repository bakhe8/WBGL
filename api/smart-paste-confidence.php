<?php
/**
 * Enhanced Smart Paste API with Confidence Scoring
 * 
 * This is a wrapper/enhancement over existing ParseCoordinatorService
 * that adds confidence scoring to all extracted fields.
 */

require_once __DIR__ . '/_bootstrap.php';

use App\Services\SmartPaste\ConfidenceCalculator;

header('Content-Type: application/json; charset=utf-8');
wbgl_api_require_login();

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    wbgl_api_compat_fail(405, 'Method not allowed');
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $text = $data['text'] ?? '';
    
    if (empty($text)) {
        wbgl_api_compat_fail(400, 'No text provided');
    }
    
    $calculator = new ConfidenceCalculator();
    
    // Example: Extract supplier with confidence
    // In real implementation, this would use existing parsers
    $extractedSupplier = extractSupplierFromText($text);
    
    if ($extractedSupplier) {
        $confidence = $calculator->calculateSupplierConfidence(
            $text,
            $extractedSupplier['value'],
            $extractedSupplier['type'], // 'exact', 'alternative', 'fuzzy'
            $extractedSupplier['similarity'] ?? 0,
            $extractedSupplier['usage_count'] ?? 0
        );
        
        // Only accept if confidence is acceptable
        if (!$confidence['accept']) {
            // Reject low confidence matches
            $extractedSupplier = null;
        } else {
            $extractedSupplier['confidence'] = $confidence['confidence'];
            $extractedSupplier['confidence_reason'] = $confidence['reason'];
            $extractedSupplier['confidence_level'] = ConfidenceCalculator::getConfidenceLevel($confidence['confidence']);
        }
    }
    
    // Return enhanced data
    wbgl_api_compat_success([
        'data' => [
            'supplier' => $extractedSupplier,
            'confidence_thresholds' => [
                'high' => ConfidenceCalculator::THRESHOLD_HIGH,
                'medium' => ConfidenceCalculator::THRESHOLD_MEDIUM,
                'low' => ConfidenceCalculator::THRESHOLD_LOW
            ]
        ],
    ]);
    
} catch (Throwable $e) {
    error_log("Smart paste confidence error: " . $e->getMessage());
    wbgl_api_compat_fail(500, $e->getMessage(), [], 'internal');
}

/**
 * Placeholder: Extract supplier from text
 * In real implementation, use existing FieldExtractionService
 */
function extractSupplierFromText(string $text): ?array {
    // This is a simplified example
    // Real implementation should use existing smart paste logic
    
    // Example: detect if text contains known supplier
    $suppliers = [
        'شركة النهضة' => ['type' => 'exact', 'similarity' => 100],
        'المقاولون العرب' => ['type' => 'exact', 'similarity' => 100],
    ];
    
    foreach ($suppliers as $supplier => $info) {
        if (stripos($text, $supplier) !== false) {
            return [
                'value' => $supplier,
                'type' => $info['type'],
                'similarity' => $info['similarity'],
                'usage_count' => 5 // Mock value
            ];
        }
    }
    
    // No match found
    return null;
}
