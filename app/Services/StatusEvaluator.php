<?php
declare(strict_types=1);

namespace App\Services;

/**
 * StatusEvaluator
 * 
 * Single source of truth for guarantee status determination.
 * Replaces duplicate status calculation logic across the codebase.
 * 
 * Status Authority Rules:
 * - READY: Both supplier_id AND bank_id are present
 * - NEEDS_DECISION (pending): Either supplier_id OR bank_id is missing
 * 
 * @version 3.0
 */
class StatusEvaluator
{
    /**
     * Evaluate guarantee status based on supplier and bank presence
     * 
     * @param int|null $supplierId Supplier ID (nullable)
     * @param int|null $bankId Bank ID (nullable)
     * @return string Status: 'ready' if both exist, 'pending' otherwise
     */
    public static function evaluate(?int $supplierId, ?int $bankId): string
    {
        // Status authority: Both supplier AND bank must exist for approval
        if ($supplierId && $bankId) {
            return 'ready';
        }
        
        return 'pending';
    }
    
    /**
     * Evaluate status from database decision record
     * 
     * @param int $guaranteeId Guarantee ID
     * @return string Status: 'ready' or 'pending'
     */
    public static function evaluateFromDatabase(int $guaranteeId): string
    {
        global $db;
        
        $stmt = $db->prepare("
            SELECT supplier_id, bank_id 
            FROM guarantee_decisions 
            WHERE guarantee_id = ?
        ");
        $stmt->execute([$guaranteeId]);
        $decision = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$decision) {
            return 'pending';
        }
        
        return self::evaluate(
            $decision['supplier_id'] ? (int)$decision['supplier_id'] : null,
            $decision['bank_id'] ? (int)$decision['bank_id'] : null
        );
    }
    
    /**
     * Get reasons WHY status is what it is (UI Logic Projection)
     * 
     * Returns structured array explaining status for user visibility
     * 
     * @param int|null $supplierId Supplier ID
     * @param int|null $bankId Bank ID
     * @param array $conflicts Conflicts array from ConflictDetector (optional)
     * @return array Reasons array for UI display
     */
    public static function getReasons(?int $supplierId, ?int $bankId, array $conflicts = []): array
    {
        $reasons = [];
        
        // If ready, explain why complete
        if ($supplierId && $bankId) {
            $reasons[] = [
                'type' => 'complete',
                'severity' => 'success',
                'message_ar' => 'كامل - المورد والبنك محددان',
                'message_en' => 'Complete - both supplier and bank selected'
            ];
            return $reasons;
        }
        
        // If pending, explain what's missing
        if (!$supplierId) {
            $reasons[] = [
                'type' => 'missing_supplier',
                'severity' => 'error',
                'message_ar' => 'المورد غير محدد',
                'message_en' => 'Supplier not selected'
            ];
        }
        
        if (!$bankId) {
            $reasons[] = [
                'type' => 'missing_bank',
                'severity' => 'error',
                'message_ar' => 'البنك غير محدد',
                'message_en' => 'Bank not selected'
            ];
        }
        
        // Add conflict reasons if provided
        if (!empty($conflicts)) {
            $reasons[] = [
                'type' => 'conflict',
                'severity' => 'warning',
                'message_ar' => 'تضارب في المطابقة - راجع يدوياً',
                'message_en' => 'Matching conflict - manual review required',
                'details' => $conflicts
            ];
        }
        
        return $reasons;
    }
}
