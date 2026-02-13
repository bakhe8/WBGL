<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\GuaranteeDecision;
use App\Repositories\GuaranteeDecisionRepository;
use App\Repositories\GuaranteeRepository;
use App\Repositories\GuaranteeHistoryRepository;
use App\Repositories\SupplierRepository;
use App\Repositories\BankRepository;

/**
 * DecisionService (V3)
 * 
 * Handles guarantee decision logic
 */
class DecisionService
{
    public function __construct(
        private GuaranteeDecisionRepository $decisions,
        private GuaranteeRepository $guarantees,
        private ?\App\Repositories\LearningRepository $learningRepo = null, // Inject Repo directly
        private ?GuaranteeHistoryRepository $history = null,
        private ?SupplierRepository $suppliers = null,
        private ?BankRepository $banks = null,
    ) {}
    
    /**
     * Save or update decision
     */
    public function save(int $guaranteeId, array $data): GuaranteeDecision
    {
        // Validate guarantee exists
        $guarantee = $this->guarantees->find($guaranteeId);
        if (!$guarantee) {
            throw new \RuntimeException("Guarantee not found: $guaranteeId");
        }
        
        // Check if locked
        $existing = $this->decisions->findByGuarantee($guaranteeId);
        if ($existing && $existing->isLocked) {
            throw new \RuntimeException("Cannot modify locked decision: {$existing->lockedReason}");
        }
        
        // Create decision object
        $decision = new GuaranteeDecision(
            id: $existing?->id,
            guaranteeId: $guaranteeId,
            status: $data['status'] ?? 'ready', // Use 'ready' as canonical term
            supplierId: $data['supplier_id'] ?? null,
            bankId: $data['bank_id'] ?? null,
            decisionSource: $data['decision_source'] ?? 'manual',
            confidenceScore: $data['confidence_score'] ?? null,
            decidedAt: date('Y-m-d H:i:s'),
            decidedBy: $data['decided_by'] ?? null,
            manualOverride: $data['manual_override'] ?? true,
            lastModifiedBy: $data['decided_by'] ?? null,
        );
        
        $saved = $this->decisions->createOrUpdate($decision);

        // Trigger Learning
        if ($this->learningRepo && isset($data['supplier_id'])) {
            // Log the manual decision
            $this->learningRepo->logDecision([
                'guarantee_id' => $guaranteeId,
                'raw_supplier_name' => $guarantee->rawData['supplier'] ?? '',
                'supplier_id' => $data['supplier_id'],
                'action' => 'confirm', 
                'confidence' => $data['confidence_score'] ?? 100,
                'decision_time_seconds' => 0
            ]);
        }

        // Snapshot Logic
        if ($this->history) {
            $supplierName = null;
            $bankName = null;
            
            if ($saved->supplierId && $this->suppliers) {
                $sup = $this->suppliers->find($saved->supplierId);
                $supplierName = $sup?->officialName;
            }
            
            if ($saved->bankId && $this->banks) {
                $bnk = $this->banks->find($saved->bankId);
                $bankName = $bnk?->officialName;
            }

            $snapshot = [
                'guarantee_number' => $guarantee->guaranteeNumber,
                'contract_number' => $guarantee->rawData['contract_number'] ?? '',
                'amount' => $guarantee->rawData['amount'] ?? 0,
                'expiry_date' => $guarantee->rawData['expiry_date'] ?? null,
                'type' => $guarantee->rawData['type'] ?? '',
                'supplier_name' => $supplierName ?? $guarantee->rawData['supplier'] ?? null,
                'bank_name' => $bankName ?? $guarantee->rawData['bank'] ?? null,
                'supplier_id' => $saved->supplierId,
                'bank_id' => $saved->bankId,
                'action_type' => 'update', // Default
            ];

            $this->history->log(
                $guaranteeId, 
                'decision_update', 
                $snapshot, 
                'Decision Saved', 
                $data['decided_by'] ?? 'system'
            );
        }

        return $saved;
    }
    
    /**
     * Lock a decision (after extension/release)
     */
    public function lock(int $guaranteeId, string $reason): void
    {
        $this->decisions->lock($guaranteeId, $reason);
    }
    
    /**
     * Check if decision can be modified
     */
    public function canModify(int $guaranteeId): array
    {
        $decision = $this->decisions->findByGuarantee($guaranteeId);
        
        if (!$decision) {
            return ['allowed' => true];
        }
        
        if ($decision->isLocked) {
            return [
                'allowed' => false,
                'reason' => $decision->lockedReason ?? 'Decision is locked'
            ];
        }
        
        return ['allowed' => true];
    }
}
