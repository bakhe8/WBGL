<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Models\GuaranteeDecision;
use App\Support\Database;
use PDO;

/**
 * GuaranteeDecisionRepository (V3)
 * 
 * Manages guarantee_decisions table - current state & decisions
 */
class GuaranteeDecisionRepository
{
    private PDO $db;
    
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connect();
    }
    
    /**
     * Find by guarantee ID
     */
    public function findByGuarantee(int $guaranteeId): ?GuaranteeDecision
    {
        $stmt = $this->db->prepare("
            SELECT * FROM guarantee_decisions WHERE guarantee_id = ?
        ");
        $stmt->execute([$guaranteeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $row ? $this->hydrate($row) : null;
    }
    
    /**
     * Create or update decision
     */
    public function createOrUpdate(GuaranteeDecision $decision): GuaranteeDecision
    {
        $existing = $this->findByGuarantee($decision->guaranteeId);
        
        if ($existing) {
            $this->update($decision);
            $decision->id = $existing->id;
        } else {
            $this->create($decision);
        }
        
        return $decision;
    }
    
    /**
     * Create new decision
     * ✅ TYPE SAFETY: Ensure IDs are integers or NULL (not empty strings)
     */
    private function create(GuaranteeDecision $decision): void
    {
        $supplierIdSafe = $decision->supplierId ? (int)$decision->supplierId : null;
        $bankIdSafe = $decision->bankId ? (int)$decision->bankId : null;
        
        $stmt = $this->db->prepare("
            INSERT INTO guarantee_decisions (
                guarantee_id, status, supplier_id, bank_id,
                decision_source, confidence_score, decided_at, decided_by,
                manual_override, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ");
        
        $stmt->execute([
            $decision->guaranteeId,
            $decision->status,
            $supplierIdSafe,
            $bankIdSafe,
            $decision->decisionSource,
            $decision->confidenceScore,
            $decision->decidedAt ?? date('Y-m-d H:i:s'),
            $decision->decidedBy,
            $decision->manualOverride ? 1 : 0
        ]);
        
        $decision->id = (int)$this->db->lastInsertId();
    }
    
    /**
     * Update existing decision
     */
    private function update(GuaranteeDecision $decision): void
    {
        $stmt = $this->db->prepare("
            UPDATE guarantee_decisions
            SET status = ?,
                supplier_id = ?,
                bank_id = ?,
                decision_source = ?,
                confidence_score = ?,
                decided_at = ?,
                decided_by = ?,
                last_modified_at = CURRENT_TIMESTAMP,
                last_modified_by = ?,
                manual_override = ?
            WHERE guarantee_id = ?
        ");
        
        $stmt->execute([
            $decision->status,
            $decision->supplierId,
            $decision->bankId,
            $decision->decisionSource,
            $decision->confidenceScore,
            $decision->decidedAt,
            $decision->decidedBy,
            $decision->lastModifiedBy,
            $decision->manualOverride ? 1 : 0,
            $decision->guaranteeId
        ]);
    }
    
    /**
     * Lock a decision
     */
    public function lock(int $guaranteeId, string $reason): void
    {
        $stmt = $this->db->prepare("
            UPDATE guarantee_decisions
            SET is_locked = 1, locked_reason = ?
            WHERE guarantee_id = ?
        ");
        
        $stmt->execute([$reason, $guaranteeId]);
    }
    
    /**
     * Set active action for guarantee
     * Phase 3: Active Action State
     * 
     * @param int $guaranteeId
     * @param string|null $action One of: 'extension', 'reduction', 'release', or NULL
     * @throws \InvalidArgumentException if action is invalid
     */
    public function setActiveAction(int $guaranteeId, ?string $action): void
    {
        // Validate action value
        $allowedActions = ['extension', 'reduction', 'release', null];
        
        if (!in_array($action, $allowedActions, true)) {
            throw new \InvalidArgumentException(
                "Invalid action: {$action}. Allowed: extension, reduction, release, or NULL"
            );
        }
        
        $stmt = $this->db->prepare("
            UPDATE guarantee_decisions
            SET active_action = ?,
                active_action_set_at = CURRENT_TIMESTAMP
            WHERE guarantee_id = ?
        ");
        
        $stmt->execute([$action, $guaranteeId]);
    }
    
    /**
     * Get active action for guarantee
     * Phase 3: Active Action State
     * 
     * @param int $guaranteeId
     * @return string|null 'extension', 'reduction', 'release', or NULL
     */
    public function getActiveAction(int $guaranteeId): ?string
    {
        $stmt = $this->db->prepare("
            SELECT active_action 
            FROM guarantee_decisions 
            WHERE guarantee_id = ?
        ");
        
        $stmt->execute([$guaranteeId]);
        $result = $stmt->fetchColumn();
        
        return $result !== false ? $result : null;
    }
    
    /**
     * Clear active action (set to NULL)
     * Useful for "cancel action" feature
     * 
     * @param int $guaranteeId
     */
    public function clearActiveAction(int $guaranteeId): void
    {
        $this->setActiveAction($guaranteeId, null);
    }
    
    /**
     * Get historical supplier selections aggregated by supplier
     * Used by HistoricalSignalFeeder
     * 
     * @param string $normalizedInput Normalized supplier name
     * @return array<int, array{supplier_id:int, count:int}>
     */
    public function getHistoricalSelections(string $normalizedInput): array
    {
        // Query guarantee_decisions joined with guarantees
        // Using indexed normalized_supplier_name column for fast lookup
        // REPLACED: Fragile JSON LIKE query (Learning Merge 2026-01-04)
        
        $stmt = $this->db->prepare("
            SELECT gd.supplier_id, COUNT(*) as count
            FROM guarantees g
            JOIN guarantee_decisions gd ON g.id = gd.guarantee_id
            WHERE g.normalized_supplier_name = ?
            GROUP BY gd.supplier_id
        ");
        
        $stmt->execute([$normalizedInput]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get all decisions with filters
     */
    public function getAll(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = [];
        $params = [];
        
        if (!empty($filters['status'])) {
            if ($filters['status'] === 'pending') {
                $where[] = '(status IS NULL OR status = "pending")';
            } else {
                $where[] = 'status = ?';
                $params[] = $filters['status'];
            }
        }
        
        $whereClause = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);
        
        $sql = "
            SELECT * FROM guarantee_decisions
            {$whereClause}
            ORDER BY decided_at DESC
            LIMIT ? OFFSET ?
        ";
        
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        $decisions = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $decisions[] = $this->hydrate($row);
        }
        
        return $decisions;
    }
    
    /**
     * Hydrate from DB row
     */
    private function hydrate(array $row): GuaranteeDecision
    {
        return new GuaranteeDecision(
            id: $row['id'],
            guaranteeId: $row['guarantee_id'],
            status: $row['status'],
            isLocked: (bool)$row['is_locked'],
            lockedReason: $row['locked_reason'],
            supplierId: $row['supplier_id'] ? (int)$row['supplier_id'] : null,
            bankId: $row['bank_id'] ? (int)$row['bank_id'] : null,
            decisionSource: $row['decision_source'] ?? 'manual',
            confidenceScore: $row['confidence_score'] ? (float)$row['confidence_score'] : null,
            decidedAt: $row['decided_at'],
            decidedBy: $row['decided_by'],
            lastModifiedAt: $row['last_modified_at'],
            lastModifiedBy: $row['last_modified_by'],
            manualOverride: (bool)$row['manual_override'],
            createdAt: $row['created_at'],
            updatedAt: $row['updated_at'],
            // Phase 3: Active Action State
            activeAction: $row['active_action'] ?? null,
            activeActionSetAt: $row['active_action_set_at'] ?? null,
        );
    }
}
