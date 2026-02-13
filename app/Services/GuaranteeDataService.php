<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * GuaranteeDataService
 * 
 * Handles loading related data for guarantees (notes, attachments)
 * Centralizes guarantee-related data queries
 * 
 * @version 1.0
 */
class GuaranteeDataService
{
    /**
     * Get notes and attachments for a guarantee
     * 
     * @param PDO $db Database connection
     * @param int $guaranteeId Guarantee ID
     * @return array ['notes' => array, 'attachments' => array]
     */
    public static function getRelatedData(PDO $db, int $guaranteeId): array
    {
        $notes = [];
        $attachments = [];
        
        try {
            // Load notes
            $stmt = $db->prepare('SELECT * FROM guarantee_notes WHERE guarantee_id = ? ORDER BY created_at DESC');
            $stmt->execute([$guaranteeId]);
            $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Load attachments
            $stmt = $db->prepare('SELECT * FROM guarantee_attachments WHERE guarantee_id = ? ORDER BY created_at DESC');
            $stmt->execute([$guaranteeId]);
            $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            // If error, keep empty arrays
        }
        
        return [
            'notes' => $notes,
            'attachments' => $attachments
        ];
    }
}
