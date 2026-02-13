<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use Exception;
use App\Support\BankNormalizer;

/**
 * BankManagementService
 * 
 * Unified service for bank creation and management
 * Combines features from both add-bank.php and create_bank.php:
 * - Aliases support (from add-bank)
 * - Contact details support (from create_bank)
 * 
 * @version 1.0
 */
class BankManagementService
{
    /**
     * Create a new bank with all features
     * 
     * @param PDO $db Database connection
     * @param array $data Bank data
     * @return array Result with bank_id and aliases_count
     * @throws Exception on validation or database errors
     */
    public static function create(PDO $db, array $data): array
    {
        // Extract and validate required fields
        $arabicName = trim($data['arabic_name'] ?? '');
        $englishName = trim($data['english_name'] ?? '');
        $shortName = strtoupper(trim($data['short_name'] ?? ''));
        
        // Optional fields
        $aliases = $data['aliases'] ?? [];
        $department = trim($data['department'] ?? '');
        $addressLine1 = trim($data['address_line1'] ?? '');
        $contactEmail = trim($data['contact_email'] ?? '');
        
        // Validation
        if (!$arabicName || !$englishName || ! $shortName) {
            throw new Exception('جميع الحقول الأساسية مطلوبة (الاسم العربي، الاسم الإنجليزي، الاسم المختصر)');
        }
        
        // Check for duplicates
        $stmt = $db->prepare("SELECT id FROM banks WHERE arabic_name = ? OR short_name = ?");
        $stmt->execute([$arabicName, $shortName]);
        $exists = $stmt->fetch();
        
        if ($exists) {
            throw new Exception('بنك بنفس الاسم موجود بالفعل');
        }
        
        // Begin transaction
        $db->beginTransaction();
        
        try {
            // 1. Insert bank with all fields
            $stmt = $db->prepare("
                INSERT INTO banks (
                    arabic_name, english_name, short_name, 
                    department, address_line1, contact_email,
                    created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))
            ");
            
            $stmt->execute([
                $arabicName,
                $englishName,
                $shortName,
                $department ?: null,
                $addressLine1 ?: null,
                $contactEmail ?: null
            ]);
            
            $bankId = (int)$db->lastInsertId();
            
            // 2. Insert alternative names (aliases)
            $stmt = $db->prepare("
                INSERT INTO bank_alternative_names (bank_id, alternative_name, normalized_name)
                VALUES (?, ?, ?)
            ");
            
            $aliasCount = 0;
            
            // Add English name as alias
            $stmt->execute([
                $bankId,
                $englishName,
                BankNormalizer::normalize($englishName)
            ]);
            $aliasCount++;
            
            // Add user-provided aliases
            if (is_array($aliases)) {
                foreach ($aliases as $alias) {
                    $alias = trim($alias);
                    if (empty($alias)) continue;
                    
                    $normalized = BankNormalizer::normalize($alias);
                    $stmt->execute([$bankId, $alias, $normalized]);
                    $aliasCount++;
                }
            }
            
            // Commit transaction
            $db->commit();
            
            return [
                'success' => true,
                'message' => 'تمت الإضافة بنجاح',
                'bank_id' => $bankId,
                'aliases_count' => $aliasCount
            ];
            
        } catch (Exception $e) {
            // Rollback on any error
            $db->rollBack();
            throw $e;
        }
    }
}
