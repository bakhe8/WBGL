<?php

namespace App\Services\Learning\Feeders;

use App\Contracts\SignalFeederInterface;
use App\DTO\SignalDTO;
use App\Repositories\SupplierAlternativeNameRepository;

/**
 * Alias Signal Feeder
 * 
 * Provides signals from supplier_alternative_names table.
 * Returns ALL aliases (no filtering), Authority decides which to use.
 * 
 * Signal Type: 'alias_exact' (exact normalized match)
 * Raw Strength: Always 1.0 (exact match is strongest)
 * 
 * Reference: Query Pattern Audit, Query #9 (compliant pattern)
 */
class AliasSignalFeeder implements SignalFeederInterface
{
    public function __construct(
        private SupplierAlternativeNameRepository $aliasRepo
    ) {}

    /**
     * Get alias signals for normalized input
     * 
     * @param string $normalizedInput
     * @return array<SignalDTO>
     */
    public function getSignals(string $normalizedInput): array
    {
        // Query ALL aliases (no usage_count filter, no LIMIT)
        // This uses the COMPLIANT query pattern from Query Pattern Audit #9
        $aliases = $this->aliasRepo->findAllByNormalizedName($normalizedInput);

        $signals = [];

        foreach ($aliases as $alias) {
            $signals[] = new SignalDTO(
                supplier_id: $alias['supplier_id'],
                signal_type: 'alias_exact',
                raw_strength: 1.0, // Exact match = full strength
                metadata: [
                    'source' => 'alias',
                    'alternative_name' => $alias['alternative_name'],
                    'alias_source' => $alias['source'], // 'learning' | 'manual' | 'import'
                    'created_at' => $alias['created_at'] ?? null,
                    'usage_count' => $alias['usage_count'] ?? 0, // For context, NOT filtering
                ]
            );
        }

        return $signals;
    }
}
