<?php

namespace App\DTO;

/**
 * Suggestion Data Transfer Object
 * 
 * CANONICAL format for ALL supplier suggestions.
 * This is the ONLY format returned by UnifiedLearningAuthority.
 * 
 * Reference: Charter Part 3, Section 6 (UI Unification Contract)
 * Reference: Authority Intent Declaration, Section 2.3 (Output Schema)
 */
class SuggestionDTO
{
    /**
     * Constructor with required parameters BEFORE optional parameters
     * PHP 8.0+ requires this order
     * 
     * @param int $supplier_id Supplier ID
     * @param string $official_name Official supplier name (Arabic)
     * @param int $confidence Confidence score (0-100 integer)
     * @param string $level Suggestion level ('B', 'C', or 'D')
     * @param string $reason_ar Arabic explanation (REQUIRED, never empty)
     * @param string|null $english_name English name (if available)
     * @param int $confirmation_count Number of user confirmations
     * @param int $rejection_count Number of user rejections
     * @param int $usage_count Usage count (for context)
     * @param string|null $primary_source Primary signal source (for debugging)
     * @param int|null $signal_count Number of signals that contributed
     * @param bool $is_ambiguous Whether multiple conflicting signals exist
     * @param bool $requires_confirmation Whether this should require user confirmation
     */
    public function __construct(
        public int $supplier_id,
        public string $official_name,
        public int $confidence,
        public string $level,
        public string $reason_ar,
        public ?string $english_name = null,
        public int $confirmation_count = 0,
        public int $rejection_count = 0,
        public int $usage_count = 0,
        public ?string $primary_source = null,
        public ?int $signal_count = null,
        public bool $is_ambiguous = false,
        public bool $requires_confirmation = false
    ) {
        $this->validate();
    }

    /**
     * Validate DTO fields
     */
    private function validate(): void
    {
        // Confidence must be 0-100
        if ($this->confidence < 0 || $this->confidence > 100) {
            throw new \InvalidArgumentException("Confidence must be 0-100, got: {$this->confidence}");
        }

        // Level must be B, C, or D
        if (!in_array($this->level, ['B', 'C', 'D'])) {
            throw new \InvalidArgumentException("Level must be B, C, or D, got: {$this->level}");
        }

        // Reason must not be empty
        if (empty($this->reason_ar)) {
            throw new \InvalidArgumentException("reason_ar cannot be empty");
        }

        // Confidence-Level consistency
        if ($this->confidence >= 85 && $this->level !== 'B') {
            throw new \InvalidArgumentException("Confidence >= 85 must have level B");
        }
        if ($this->confidence >= 65 && $this->confidence < 85 && $this->level !== 'C') {
            throw new \InvalidArgumentException("Confidence 65-84 must have level C");
        }
        if ($this->confidence >= 40 && $this->confidence < 65 && $this->level !== 'D') {
            throw new \InvalidArgumentException("Confidence 40-64 must have level D");
        }
    }

    /**
     * Convert to array for JSON response
     */
    public function toArray(): array
    {
        return [
            'supplier_id' => $this->supplier_id,
            'official_name' => $this->official_name,
            'english_name' => $this->english_name,
            'confidence' => $this->confidence,
            'level' => $this->level,
            'reason_ar' => $this->reason_ar,
            'confirmation_count' => $this->confirmation_count,
            'rejection_count' => $this->rejection_count,
            'usage_count' => $this->usage_count,
            // Debug fields (optional, may be filtered in production)
            'primary_source' => $this->primary_source,
            'signal_count' => $this->signal_count,
            'is_ambiguous' => $this->is_ambiguous,
            'requires_confirmation' => $this->requires_confirmation,
        ];
    }
}
