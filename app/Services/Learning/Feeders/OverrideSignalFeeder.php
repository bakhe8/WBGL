<?php

namespace App\Services\Learning\Feeders;

use App\Contracts\SignalFeederInterface;
use App\DTO\SignalDTO;
use App\Repositories\SupplierOverrideRepository;

/**
 * Override Signal Feeder
 *
 * Provides deterministic signals from supplier_overrides table.
 */
class OverrideSignalFeeder implements SignalFeederInterface
{
    public function __construct(
        private SupplierOverrideRepository $overrideRepo
    ) {}

    /**
     * @param string $normalizedInput
     * @return array<SignalDTO>
     */
    public function getSignals(string $normalizedInput): array
    {
        $override = $this->overrideRepo->findByNormalized($normalizedInput, true);
        if (!$override) {
            return [];
        }

        return [
            new SignalDTO(
                supplier_id: (int)$override['supplier_id'],
                signal_type: 'override_exact',
                raw_strength: 1.0,
                metadata: [
                    'source' => 'override',
                    'raw_name' => $override['raw_name'] ?? '',
                    'normalized_name' => $override['normalized_name'] ?? '',
                    'reason' => $override['reason'] ?? null,
                    'override_id' => (int)$override['id'],
                    'created_by' => $override['created_by'] ?? null,
                ]
            ),
        ];
    }
}

