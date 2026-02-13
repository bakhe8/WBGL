<?php
namespace App\Repositories;

use PDO;

class SupplierLearningRepository
{
    public PDO $db; // Changed from private to public for session tracking

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Get smart suggestions for a raw supplier name
     */
    public function findSuggestions(string $normalizedName, int $limit = 5): array
    {
        // 1. Check aliases first (Certainty: 100%)
        $stmt = $this->db->prepare("
            SELECT s.id, s.official_name, 'alias' as source, 100 as score
            FROM supplier_alternative_names a
            JOIN suppliers s ON a.supplier_id = s.id
            WHERE a.normalized_name = ? AND a.usage_count > 0
            LIMIT 1
        ");
        $stmt->execute([$normalizedName]);
        $alias = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($alias) {
            return [$alias];
        }

        // 2. Check learning cache (Fuzzy/History)
        // Here we can fetch from supplier_learning_cache if populated, 
        // OR standard fuzzy search on suppliers table combined with usage count
        
        // For now, let's do a smart search on suppliers with usage weight
        // (Assuming we might want to join with a usage stats table in future)
        
        $sql = "
            SELECT 
                id, 
                official_name, 
                'search' as source,
                CASE 
                    WHEN normalized_name = ? THEN 95 
                    WHEN normalized_name LIKE ? THEN 80
                    ELSE 60 
                END as score
            FROM suppliers 
            WHERE normalized_name LIKE ? 
            ORDER BY score DESC, id ASC -- Add usage_count logic here later
            LIMIT ?
        ";

        $likeParam = '%' . $normalizedName . '%';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$normalizedName, $likeParam, $likeParam, $limit]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Increment usage for a supplier (and update cache)
     */
    public function incrementUsage(int $supplierId, string $rawName): void
    {
        // 1. Update alias usage if exists
        $norm = $this->normalize($rawName);
        $stmt = $this->db->prepare("
            UPDATE supplier_alternative_names 
            SET usage_count = usage_count + 1
            WHERE supplier_id = ? AND normalized_name = ?
        ");
        $affected = $stmt->execute([$supplierId, $norm]);
        
        // SAFE LEARNING: Log when usage is incremented
        if ($stmt->rowCount() > 0) {
            error_log(sprintf(
                "[SAFE_LEARNING] Incremented usage_count for supplier_id=%d, alias='%s'",
                $supplierId,
                $rawName
            ));
        }
        
        // 2. We could update a general usage stats table here
    }

    /**
     * Decrement usage for a supplier (Negative Learning)
     * Used when a user explicitly ignores a suggestion
     */
    public function decrementUsage(int $supplierId, string $rawName): void
    {
        // 1. Update alias usage if exists
        $norm = $this->normalize($rawName);
        
        // Decrease usage_count but ensure it doesn't drop below -5 (hard ignore limit)
        $stmt = $this->db->prepare("
            UPDATE supplier_alternative_names 
            SET usage_count = CASE WHEN usage_count > -5 THEN usage_count - 1 ELSE -5 END
            WHERE supplier_id = ? AND normalized_name = ?
        ");
        $stmt->execute([$supplierId, $norm]);
        
        if ($stmt->rowCount() > 0) {
            error_log(sprintf(
                "[SAFE_LEARNING] Decremented usage_count for supplier_id=%d, alias='%s' (Penalty)",
                $supplierId,
                $rawName
            ));
        }
    }

    /**
     * Learn a new alias mapping
     */
    public function learnAlias(int $supplierId, string $rawName): void
    {
        $norm = $this->normalize($rawName);
        
        // Check if exists
        $stmt = $this->db->prepare("SELECT id FROM supplier_alternative_names WHERE normalized_name = ?");
        $stmt->execute([$norm]);
        if ($stmt->fetch()) {
            return; // Already exists
        }

        // Insert
        $stmt = $this->db->prepare("
            INSERT INTO supplier_alternative_names (supplier_id, alternative_name, normalized_name, source, usage_count)
            VALUES (?, ?, ?, 'learning', 1)
        ");
        $stmt->execute([$supplierId, $rawName, $norm]);
    }

    /**
     * Log the decision for auditing/analytics
     */
    public function logDecision(array $data): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO supplier_decisions_log 
            (guarantee_id, raw_input, normalized_input, chosen_supplier_id, chosen_supplier_name, decision_source, confidence_score, was_top_suggestion, decided_at)
            VALUES 
            (:gid, :raw, :norm, :sid, :sname, :src, :score, :top, :at)
        ");
        
        $stmt->execute([
            ':gid'   => $data['guarantee_id'],
            ':raw'   => $data['raw_input'],
            ':norm'  => $this->normalize($data['raw_input']),
            ':sid'   => $data['chosen_supplier_id'],
            ':sname' => $data['chosen_supplier_name'],
            ':src'   => $data['source'] ?? 'manual',
            ':score' => $data['score'] ?? 0,
            ':top'   => $data['was_top_suggestion'] ?? 0,
            ':at'    => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Find conflicting aliases for a supplier
     * 
     * Returns aliases that are registered for this supplier but differ
     * from the current input. Used to identify potential "culprits" that
     * block auto-matching despite high confidence.
     * 
     * @param int $supplierId The supplier ID
     * @param string $currentNormalized The current normalized input
     * @return array List of conflicting aliases, ordered by priority
     */
    public function findConflictingAliases(int $supplierId, string $currentNormalized): array
    {
        $sql = "
            SELECT 
                id,
                supplier_id,
                alternative_name,
                normalized_name,
                source,
                usage_count,
                created_at
            FROM supplier_alternative_names
            WHERE supplier_id = ?
            AND normalized_name != ?
            AND usage_count > 0
            ORDER BY 
                CASE 
                    WHEN source = 'learning' THEN 1
                    WHEN source = 'manual' THEN 2
                    ELSE 3
                END,
                usage_count DESC,
                created_at ASC
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$supplierId, $currentNormalized]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Normalize text for matching
     * 
     * Uses ArabicNormalizer for consistent normalization across the system
     */
    private function normalize(string $text): string
    {
        return \App\Support\ArabicNormalizer::normalize($text);
    }
}
