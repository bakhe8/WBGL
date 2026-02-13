<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Repository for managing Batch Metadata
 * Handles custom Arabic names, notes, and status for batches
 */
class BatchMetadataRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Ensure a batch has an Arabic name assigned
     * Upsert logic: Insert if new, Update if exists but name is different/missing
     */
    public function ensureBatchName(string $importSource, string $arabicName): void
    {
        // 1. Check if exists
        $stmt = $this->db->prepare("SELECT batch_name FROM batch_metadata WHERE import_source = ?");
        $stmt->execute([$importSource]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // Update only if name is missing or different (optional: enforce override?)
            // Policy: Always ensure the *Display Name* matches the Arabic convention for system batches
            // But respect user manual edits? 
            // For now, if the name is empty or looks like the raw ID, update it.
            // If the user manually renamed it to "My Custom Batch", we might overwrite it if we are not careful.
            // BUT: This method is called on Creation. So usually it's empty.
            // For Migration, we want to overwrite.
            
            // Let's unconditionally update for now to enforce uniformity, 
            // considering this is triggered on creation/migration.
            
            $update = $this->db->prepare("UPDATE batch_metadata SET batch_name = ? WHERE import_source = ?");
            $update->execute([$arabicName, $importSource]);
        } else {
            // Insert new
            $insert = $this->db->prepare("
                INSERT INTO batch_metadata (import_source, batch_name, status, created_at)
                VALUES (?, ?, 'active', datetime('now'))
            ");
            $insert->execute([$importSource, $arabicName]);
        }
    }
}
