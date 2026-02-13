<?php
/**
 * V3 API - Create Supplier (AJAX)
 * Adds a new supplier to the master list
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;
use App\Support\Input;

header('Content-Type: application/json; charset=utf-8');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }
    $fallbackName = Input::string($input, 'name', '');
    $officialName = Input::string($input, 'official_name', $fallbackName);
    $englishName = Input::string($input, 'english_name', '');
    $isConfirmed = Input::int($input, 'is_confirmed');
    
    if (!$officialName) {
        throw new \RuntimeException('اسم المورد مطلوب');
    }
    
    $db = Database::connect();
    
    // Smart Detection: Check if name contains Arabic characters
    // Regex: \p{Arabic} detects any Arabic script character
    $hasArabic = preg_match('/\p{Arabic}/u', $officialName);
    
    // Detailed Logic:
    // 1. If Arabic: Official = Name, English = NULL (Avoid Repetition)
    // 2. If English: Official = Name, English = Name (Common practice for foreign companies)
    if ($englishName === '') {
        $englishName = null;
    }
    if ($englishName === null) {
        $englishName = $hasArabic ? null : $officialName;
    }

    // Use unified service
    $data = [
        'official_name' => $officialName,
        'english_name' => $englishName
    ];
    if ($isConfirmed !== null) {
        $data['is_confirmed'] = $isConfirmed;
    }
    $result = \App\Services\SupplierManagementService::create($db, $data);
    
    // Return response in expected format for Decision Flow
    echo json_encode([
        'success' => true,
        'supplier_id' => $result['supplier_id'],
        'official_name' => $result['official_name'],
        'supplier' => [
            'id' => $result['supplier_id'],
            'name' => $result['official_name']
        ]
    ]);
    
} catch (\Throwable $e) {
    http_response_code(400); // Bad Request
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
