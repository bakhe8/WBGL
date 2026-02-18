<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use Exception;
use App\Support\Database;
use App\Support\Normalizer;
use App\Support\Logger;

/**
 * SupplierMergeService
 * 
 * Handles the merging of a source supplier into a target supplier.
 * Ensures no data loss (history, learning, aliases) during the process.
 */
class SupplierMergeService
{
    private PDO $db;
    private Normalizer $normalizer;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connect();
        $this->normalizer = new Normalizer();
    }

    /**
     * Merge source supplier into target supplier
     * 
     * @param int $sourceId ID of the supplier to be removed/merged
     * @param int $targetId ID of the supplier to remain/receive data
     * @return bool Success status
     * @throws Exception
     */
    public function merge(int $sourceId, int $targetId): bool
    {
        if ($sourceId === $targetId) {
            throw new Exception("لا يمكن دمج المورد مع نفسه");
        }

        $this->db->beginTransaction();

        try {
            // 1. Get Source and Target info
            $source = $this->getSupplierInfo($sourceId);
            $target = $this->getSupplierInfo($targetId);

            if (!$source || !$target) {
                throw new Exception("تعذر العثور على الموردين المطلوب دمجهم");
            }

            // 2. Transfer History (guarantee_decisions)
            $stmt = $this->db->prepare("UPDATE guarantee_decisions SET supplier_id = ? WHERE supplier_id = ?");
            $stmt->execute([$targetId, $sourceId]);
            $historyMoved = $stmt->rowCount();

            // 3. Transfer Learning (learning_confirmations)
            $stmt = $this->db->prepare("UPDATE learning_confirmations SET supplier_id = ? WHERE supplier_id = ?");
            $stmt->execute([$targetId, $sourceId]);
            $learningMoved = $stmt->rowCount();

            // 3b. Transfer Decision Logs
            $stmt = $this->db->prepare("UPDATE supplier_decisions_log SET chosen_supplier_id = ? WHERE chosen_supplier_id = ?");
            $stmt->execute([$targetId, $sourceId]);

            // 4. Enrich Target if needed
            // If target has no English name and source does, copy it
            if (empty($target['english_name']) && !empty($source['english_name'])) {
                $stmt = $this->db->prepare("UPDATE suppliers SET english_name = ? WHERE id = ?");
                $stmt->execute([$source['english_name'], $targetId]);
            }

            // 5. Transfer/Cleanup Aliases
            // Attach all source's aliases to target, or delete if they already exist for target
            $this->transferAliases($sourceId, $targetId);

            // Create Alias for the source name itself if it doesn't exist
            $this->addAlias($targetId, $source['official_name'], 'merge_cleanup');
            
            // If source had an english_name, add it as alias too
            if (!empty($source['english_name'])) {
                $this->addAlias($targetId, $source['english_name'], 'merge_cleanup');
            }

            // 6. Delete Source Supplier
            $stmt = $this->db->prepare("DELETE FROM suppliers WHERE id = ?");
            $stmt->execute([$sourceId]);

            $this->db->commit();
            
            Logger::info("Supplier Merge Success", [
                'source' => $source['official_name'],
                'target' => $target['official_name'],
                'history_count' => $historyMoved,
                'learning_count' => $learningMoved
            ]);

            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            Logger::error("Supplier Merge Failed", ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function getSupplierInfo(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT id, official_name, english_name FROM suppliers WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function transferAliases(int $sourceId, int $targetId): void
    {
        // Get all aliases of source
        $stmt = $this->db->prepare("SELECT alternative_name, normalized_name, source, usage_count FROM supplier_alternative_names WHERE supplier_id = ?");
        $stmt->execute([$sourceId]);
        $sourceAliases = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($sourceAliases as $alias) {
            // Check if this alias already exists for target
            $checkStmt = $this->db->prepare("SELECT id, usage_count FROM supplier_alternative_names WHERE supplier_id = ? AND normalized_name = ?");
            $checkStmt->execute([$targetId, $alias['normalized_name']]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                // Update usage count if it's higher or just add?
                // Let's sum them up
                $updStmt = $this->db->prepare("UPDATE supplier_alternative_names SET usage_count = usage_count + ? WHERE id = ?");
                $updStmt->execute([$alias['usage_count'], $existing['id']]);
            } else {
                // Re-parent the alias
                $this->addAlias($targetId, $alias['alternative_name'], $alias['source'], $alias['usage_count']);
            }
        }

        // Delete all aliases associated with source
        $stmt = $this->db->prepare("DELETE FROM supplier_alternative_names WHERE supplier_id = ?");
        $stmt->execute([$sourceId]);
    }

    private function addAlias(int $supplierId, string $name, string $source, int $usageCount = 1): void
    {
        $normalized = $this->normalizer->normalizeSupplierName($name);
        
        // Check if alias already exists for this supplier
        $stmt = $this->db->prepare("SELECT id FROM supplier_alternative_names WHERE supplier_id = ? AND normalized_name = ?");
        $stmt->execute([$supplierId, $normalized]);
        
        if ($stmt->fetch()) {
            return; // Already exists
        }

        $stmt = $this->db->prepare("
            INSERT INTO supplier_alternative_names (supplier_id, alternative_name, normalized_name, source, usage_count)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$supplierId, $name, $normalized, $source, $usageCount]);
    }
}
