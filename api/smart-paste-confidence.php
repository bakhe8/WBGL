<?php
/**
 * Enhanced Smart Paste API with Confidence Scoring
 * 
 * This is a wrapper/enhancement over existing ParseCoordinatorService
 * that adds confidence scoring to all extracted fields.
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Services\SmartPaste\ConfidenceCalculator;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $text = $data['text'] ?? '';
    
    if (empty($text)) {
        echo json_encode(['success' => false, 'error' => 'No text provided']);
        exit;
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
    echo json_encode([
        'success' => true,
        'data' => [
            'supplier' => $extractedSupplier,
            'confidence_thresholds' => [
                'high' => ConfidenceCalculator::THRESHOLD_HIGH,
                'medium' => ConfidenceCalculator::THRESHOLD_MEDIUM,
                'low' => ConfidenceCalculator::THRESHOLD_LOW
            ]
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Smart paste confidence error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
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
