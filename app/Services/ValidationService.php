<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\GuaranteeDecisionRepository;
use App\Repositories\GuaranteeRepository;

/**
 * ValidationService (V3)
 * 
 * Validates business rules before actions
 */
class ValidationService
{
    public function __construct(
        private GuaranteeDecisionRepository $decisions,
        private GuaranteeRepository $guarantees,
    ) {}
    
    /**
     * Check if guarantee can be extended
     */
    public function canExtend(int $guaranteeId): array
    {
        $decision = $this->decisions->findByGuarantee($guaranteeId);
        
        if ($decision && $decision->isLocked) {
            return [
                'allowed' => false,
                'reason' => 'لا يمكن التمديد: ' . $decision->lockedReason
            ];
        }
        
        return ['allowed' => true];
    }
    
    /**
     * Check if guarantee can be released
     */
    public function canRelease(int $guaranteeId): array
    {
        $decision = $this->decisions->findByGuarantee($guaranteeId);
        
        if ($decision && $decision->isLocked) {
            return [
                'allowed' => false,
                'reason' => 'الضمان مقفل بالفعل: ' . $decision->lockedReason
            ];
        }
        
        return ['allowed' => true];
    }
    
    /**
     * Check if auto-matching is allowed
     */
    public function canAutoMatch(int $guaranteeId): bool
    {
        $decision = $this->decisions->findByGuarantee($guaranteeId);
        
        // Prevent auto-matching after manual override
        return !($decision && $decision->manualOverride);
    }
}
