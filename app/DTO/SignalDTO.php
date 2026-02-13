<?php

namespace App\DTO;

/**
 * Signal Data Transfer Object
 * 
 * Represents a RAW signal from a feeder.
 * Contains NO final decisions (confidence, ordering, filtering).
 * 
 * Reference: Database Role Declaration, Article 6.1 (Signal Requirements)
 */
class SignalDTO
{
    /**
     * @param int $supplier_id The supplier this signal refers to
     * @param string $signal_type Type of signal (e.g., 'alias_exact', 'entity_anchor_unique', 'fuzzy_official')
     * @param float $raw_strength Raw signal strength (0.0-1.0), NOT final confidence
     * @param array $metadata Additional context (source, match_method, etc.)
     */
    public function __construct(
        public int $supplier_id,
        public string $signal_type,
        public float $raw_strength,
        public array $metadata = []
    ) {
        // Validate signal strength
        if ($raw_strength < 0.0 || $raw_strength > 1.0) {
            throw new \InvalidArgumentException("Signal strength must be between 0 and 1, got: {$raw_strength}");
        }
    }

    /**
     * Convert to array for debugging/logging
     */
    public function toArray(): array
    {
        return [
            'supplier_id' => $this->supplier_id,
            'signal_type' => $this->signal_type,
            'raw_strength' => $this->raw_strength,
            'metadata' => $this->metadata,
        ];
    }
}
