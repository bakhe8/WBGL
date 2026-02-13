<?php
/**
 * V3 API - Smart Paste Parse (v2 - With Confidence Scores)
 * 
 * Enhanced version that includes confidence scores for extracted data
 * Uses ConfidenceCalculator to assess reliability of each field
 * 
 * @version 2.0
 */

require_once __DIR__ . '/../app/Support/Database.php';
require_once __DIR__ . '/../app/Models/Guarantee.php';
require_once __DIR__ . '/../app/Repositories/GuaranteeRepository.php';
require_once __DIR__ . '/../app/Services/TimelineRecorder.php';
require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;
use App\Support\Input;
use App\Services\ParseCoordinatorService;
use App\Services\SmartPaste\ConfidenceCalculator;

header('Content-Type: application/json; charset=utf-8');

try {
    // Get input
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }
    $text = Input::string($input, 'text', '');

    if (empty($text)) {
        throw new \RuntimeException("لم يتم إدخال أي نص للتحليل");
    }

    // Connect to database
    $db = Database::connect();
    
    // ✅ NEW: Extract test data parameters (Phase 1)
    $isTestData = !empty($input['is_test_data']);
    $testBatchId = Input::string($input, 'test_batch_id', null);
    $testNote = Input::string($input, 'test_note', null);
    
    // Parse text using ParseCoordinatorService
    $result = ParseCoordinatorService::parseText($text, $db);
    
    // ✅ NEW: Calculate confidence scores for extracted fields
    if ($result['success'] && !empty($result['extracted'])) {
        $calculator = new ConfidenceCalculator();
        $confidence = [];
        $extracted = $result['extracted'];
        
        // Calculate confidence for supplier
        if (!empty($extracted['supplier'])) {
            $confidence['supplier'] = $calculator->calculateSupplierConfidence(
                $text,
                $extracted['supplier'],
                'fuzzy', // We don't have match type from ParseCoordinator, assume fuzzy
                85, // Assume decent similarity
                0 // No historical count available
            );
        }
        
        // Calculate confidence for bank
        if (!empty($extracted['bank'])) {
            $confidence['bank'] = $calculator->calculateBankConfidence(
                $text,
                $extracted['bank'],
                'fuzzy',
                85
            );
        }
        
        // Calculate confidence for amount
        if (!empty($extracted['amount'])) {
            $confidence['amount'] = $calculator->calculateAmountConfidence(
                $text,
                floatval($extracted['amount'])
            );
        }
        
        // Calculate confidence for dates
        if (!empty($extracted['expiry_date'])) {
            $confidence['expiry_date'] = $calculator->calculateDateConfidence(
                $text,
                $extracted['expiry_date']
            );
        }
        
        if (!empty($extracted['issue_date'])) {
            $confidence['issue_date'] = $calculator->calculateDateConfidence(
                $text,
                $extracted['issue_date']
            );
        }
        
        // Add confidence to result
        $result['confidence'] = $confidence;
        
        // Calculate overall confidence (average of all field confidences)
        $scores = array_column($confidence, 'confidence');
        $result['overall_confidence'] = !empty($scores) ? round(array_sum($scores) / count($scores)) : 0;
    }
    
    // ✅ NEW: If successful and marked as test data, mark the guarantee
    if ($result['success'] && $isTestData && !empty($result['id'])) {
        $repo = new \App\Repositories\GuaranteeRepository($db);
        $repo->markAsTestData($result['id'], $testBatchId, $testNote);
    }
    
    // ✅ NEW (Phase 2): Log confidence scores in timeline metadata
    if ($result['success'] && !empty($result['id']) && !empty($result['confidence'])) {
        try {
            // Store confidence metadata for future analysis
            $confidenceSummary = [
                'overall' => $result['overall_confidence'] ?? 0,
                'fields' => []
            ];
            
            foreach ($result['confidence'] as $field => $data) {
                $confidenceSummary['fields'][$field] = [
                    'score' => $data['confidence'] ?? 0,
                    'level' => \App\Services\SmartPaste\ConfidenceCalculator::getConfidenceLevel($data['confidence'] ?? 0)
                ];
            }
            
            // Update guarantee metadata with confidence info
            $stmt = $db->prepare("
                INSERT INTO guarantee_metadata (guarantee_id, meta_key, meta_value, created_at)
                VALUES (?, 'smart_paste_confidence', ?, ?)
            ");
            $stmt->execute([
                $result['id'],
                json_encode($confidenceSummary),
                date('Y-m-d H:i:s')
            ]);
            
        } catch (\Exception $e) {
            // Log error but don't fail the request
            error_log("Failed to store confidence metadata: " . $e->getMessage());
        }
    }
    
    // Return result with confidence scores
    echo json_encode($result);
    
    // Set appropriate HTTP status
    if (!$result['success']) {
        http_response_code(400);
    }

} catch (\Throwable $e) {
    // Error handling
    error_log("Parse-paste-v2 error: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'extracted' => [],
        'field_status' => [],
        'confidence' => []
    ]);
}
