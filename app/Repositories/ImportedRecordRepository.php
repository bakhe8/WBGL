<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Models\Guarantee;
use App\Support\Database;
use PDO;

/**
 * ImportedRecordRepository
 * 
 * Helper repository for TimelineEventService to fetch record details
 * in a standardized structure (DTO-like).
 */
class ImportedRecordRepository
{
    private PDO $db;
    
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * Find record and return as object expected by TimelineEventService
     */
    public function find(int $id): ?object
    {
        $stmt = $this->db->prepare("SELECT * FROM guarantees WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $rawData = json_decode($row['raw_data'] ?? '{}', true);

        // Map to anonymous object matching TimelineEventService expectations
        return (object) [
            'id' => (int)$row['id'],
            'guaranteeNumber' => $row['guarantee_number'],
            'recordType' => 'import', // Default to import for now
            'matchStatus' => 'pending', // Default
            
            // Raw Fields
            'rawSupplierName' => $rawData['supplier'] ?? null,
            'rawBankName' => $rawData['bank'] ?? null,
            
            // Display Fields (For now same as raw, until we join with actual suppliers table)
            'supplierDisplayName' => $rawData['supplier'] ?? null,
            'bankDisplay' => $rawData['bank'] ?? null,
            
            // Details
            'amount' => $rawData['amount'] ?? null,
            'expiryDate' => $rawData['expiry_date'] ?? null,
            'issueDate' => $rawData['issue_date'] ?? null,
            'contractNumber' => $rawData['contract_number'] ?? null,
            'type' => $rawData['type'] ?? null,
            'relatedTo' => $rawData['related_to'] ?? null
        ];
    }
}
