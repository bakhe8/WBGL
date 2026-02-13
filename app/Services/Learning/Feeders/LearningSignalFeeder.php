<?php

namespace App\Services\Learning\Feeders;

use App\Contracts\SignalFeederInterface;
use App\DTO\SignalDTO;
use App\Repositories\LearningRepository;

/**
 * Learning Signal Feeder
 * 
 * Provides signals from user confirmations and rejections.
 * Aggregates learning_confirmations data BY SUPPLIER.
 * 
 * Signal Types:
 * - 'learning_confirmation' (user confirmed this supplier)
 * - 'learning_rejection' (user rejected this supplier)
 * 
 * Note: Uses normalized_supplier_name for consistent matching.
 * 
 * Reference: Query Pattern Audit, Query #2 (known fragmentation)
 */
class LearningSignalFeeder implements SignalFeederInterface
{
    public function __construct(
        private LearningRepository $learningRepo
    ) {}

    /**
     * Get learning signals (confirmations/rejections)
     * 
     * @param string $normalizedInput
     * @return array<SignalDTO>
     */
    public function getSignals(string $normalizedInput): array
    {
        // Get user feedback aggregated by supplier
        // Note: Uses normalized_supplier_name via LearningRepository
        $feedback = $this->learningRepo->getUserFeedback($normalizedInput);

        $signals = [];

        foreach ($feedback as $item) {
            $supplierId = $item['supplier_id'];
            $action = $item['action']; // 'confirm' | 'reject'
            $count = $item['count'];

            if ($action === 'confirm') {
                $signals[] = new SignalDTO(
                    supplier_id: $supplierId,
                    signal_type: 'learning_confirmation',
                    raw_strength: min(1.0, $count / 10), // Normalized strength (10+ confirmations = 1.0)
                    metadata: [
                        'source' => 'learning',
                        'confirmation_count' => $count,
                        'action' => 'confirm',
                    ]
                );
            } elseif ($action === 'reject') {
                $signals[] = new SignalDTO(
                    supplier_id: $supplierId,
                    signal_type: 'learning_rejection',
                    raw_strength: min(1.0, $count / 5), // Rejections accumulate faster
                    metadata: [
                        'source' => 'learning',
                        'rejection_count' => $count,
                        'action' => 'reject',
                    ]
                );
            }
        }

        return $signals;
    }
}
