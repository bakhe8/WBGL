<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use Exception;
use App\Support\Normalizer;
use App\Support\TransactionBoundary;

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

        return TransactionBoundary::run($db, static function () use ($db, $officialName, $englishName, $isConfirmed): array {
            $normalizer = new Normalizer();
            $normalizedName = $normalizer->normalizeSupplierName($officialName);
            if ($normalizedName === '') {
                $normalizedName = mb_strtolower($officialName);
            }

            // Serialize concurrent creates for the same normalized supplier name.
            $lockStmt = $db->prepare('SELECT pg_advisory_xact_lock(hashtext(?))');
            $lockStmt->execute(['supplier:create:' . $normalizedName]);

            $dupStmt = $db->prepare('
                SELECT id
                FROM suppliers
                WHERE official_name = ?
                   OR normalized_name = ?
                LIMIT 1
            ');
            $dupStmt->execute([$officialName, $normalizedName]);
            if ($dupStmt->fetchColumn()) {
                throw new Exception('المورد موجود بالفعل');
            }

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
        });
    }
}
