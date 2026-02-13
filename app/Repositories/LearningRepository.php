<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;
use PDO;

/**
 * LearningRepository
 * 
 * Access layer for:
 * 1. Confirmed/Rejected Pilot data (learning_confirmations)
 * 2. Historical selections (guarantees)
 */
class LearningRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function getUserFeedback(string $rawName): array
    {
        // Use normalized_supplier_name for consistent matching
        // UPDATED: Learning Merge 2026-01-04
        $stmt = $this->db->prepare("
            SELECT supplier_id, action, count(*) as count
            FROM learning_confirmations
            WHERE normalized_supplier_name = ?
            GROUP BY supplier_id, action
        ");
        $stmt->execute([$rawName]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getRejections(string $rawName): array
    {
        $stmt = $this->db->prepare("
            SELECT DISTINCT supplier_id
            FROM learning_confirmations
            WHERE raw_supplier_name = ? AND action = 'reject'
        ");
        $stmt->execute([$rawName]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function getHistoricalSelections(string $rawName): array
    {
        // Workaround for JSON query: LIKE match
        $jsonFragment = '"supplier":"' . str_replace('"', '\"', $rawName) . '"';
        
        $stmt = $this->db->prepare("
            SELECT d.supplier_id, COUNT(*) as frequency
            FROM guarantees g
            JOIN guarantee_decisions d ON g.id = d.guarantee_id
            WHERE g.raw_data LIKE ? 
            AND d.supplier_id IS NOT NULL
            GROUP BY d.supplier_id
        ");
        
        $stmt->execute(['%' . $jsonFragment . '%']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function logDecision(array $data): void
    {
        // Normalize supplier name for consistent querying
        // UPDATED: Learning Merge 2026-01-04
        $normalized = \App\Support\ArabicNormalizer::normalize($data['raw_supplier_name']);
        
        $stmt = $this->db->prepare("
            INSERT INTO learning_confirmations (
                raw_supplier_name, normalized_supplier_name, supplier_id, confidence, matched_anchor, 
                anchor_type, action, decision_time_seconds, guarantee_id,
                created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))
        ");
        
        $stmt->execute([
            $data['raw_supplier_name'],
            $normalized,
            $data['supplier_id'],
            $data['confidence'],
            $data['matched_anchor'] ?? null,
            $data['anchor_type'] ?? 'learned',
            $data['action'],
            $data['decision_time_seconds'] ?? 0,
            $data['guarantee_id'] ?? null
        ]);
    }
}
