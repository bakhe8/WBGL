<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use Exception;
use App\Support\Normalizer;

/**
 * SupplierManagementService
 * 
 * Unified service for supplier creation and management
 * Combines features from both create-supplier.php and create_supplier.php
 * 
 * @version 1.0
 */
class SupplierManagementService
{
    /**
     * Create a new supplier
     * 
     * @param PDO $db Database connection
     * @param array $data Supplier data
     * @return array Result with supplier_id and official_name
     * @throws Exception on validation or database errors
     */
    public static function create(PDO $db, array $data): array
    {
        // Extract and validate required field
        $officialName = trim($data['official_name'] ?? '');
        
        if (!$officialName) {
            throw new Exception('الاسم الرسمي مطلوب');
        }
        
        // Optional fields
        $englishName = trim($data['english_name'] ?? '');
        $isConfirmed = isset($data['is_confirmed']) ? (int)$data['is_confirmed'] : 0;
        
        // Check for duplicates
        $stmt = $db->prepare('SELECT id FROM suppliers WHERE official_name = ?');
        $stmt->execute([$officialName]);
        
        if ($stmt->fetchColumn()) {
            throw new Exception('المورد موجود بالفعل');
        }
        
        // Normalize name using Normalizer class
        $normalizer = new Normalizer();
        $normalizedName = $normalizer->normalizeSupplierName($officialName);
        
        // Insert supplier with all fields
        $stmt = $db->prepare("
            INSERT INTO suppliers (
                official_name, 
                english_name, 
                normalized_name, 
                is_confirmed, 
                created_at
            ) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
        ");
        
        $stmt->execute([
            $officialName,
            $englishName ?: null,
            $normalizedName,
            $isConfirmed
        ]);
        
        $supplierId = (int)$db->lastInsertId();
        
        return [
            'supplier_id' => $supplierId,
            'official_name' => $officialName,
            'english_name' => $englishName,
            'normalized_name' => $normalizedName,
            'is_confirmed' => $isConfirmed
        ];
    }
}
