<?php

namespace App\Services\Learning;

use App\DTO\SuggestionDTO;
use App\Repositories\SupplierRepository;

/**
 * Suggestion Formatter
 * 
 * Transforms internal candidate data to canonical SuggestionDTO.
 * Generates Arabic reason strings according to Charter UI contract.
 * 
 * Reference: Charter Part 3, Section 6 (UI Unification Contract)
 * Reference: Authority Intent Declaration, Section 2.3 (Output Schema)
 */
class SuggestionFormatter
{
    public function __construct(
        private SupplierRepository $supplierRepo
    ) {}

    /**
     * Convert internal candidate to SuggestionDTO
     * 
     * @param array $candidate Internal candidate array from Authority
     * @return SuggestionDTO|null Returns null if supplier no longer exists
     */
    public function toDTO(array $candidate): ?SuggestionDTO
    {
        // Fetch supplier details
        $supplier = $this->supplierRepo->findById($candidate['supplier_id']);

        if (!$supplier) {
            // Supplier was deleted - skip this suggestion instead of crashing
            error_log("Warning: Skipping suggestion for deleted supplier ID: {$candidate['supplier_id']}");
            return null;
        }

        // Generate Arabic reason
        $reasonAr = $this->generateReasonArabic(
            $candidate['primary_source'],
            $candidate['confidence'],
            $candidate['confirmation_count'],
            $candidate['rejection_count']
        );

        return new SuggestionDTO(
            supplier_id: $candidate['supplier_id'],
            official_name: $supplier['official_name'],
            confidence: $candidate['confidence'],
            level: $candidate['level'],
            reason_ar: $reasonAr,
            english_name: $supplier['english_name'] ?? null,
            confirmation_count: $candidate['confirmation_count'],
            rejection_count: $candidate['rejection_count'],
            usage_count: $candidate['usage_count'] ?? 0,
            primary_source: $candidate['primary_source'],
            signal_count: $candidate['signal_count'],
            is_ambiguous: $candidate['is_ambiguous'],
            requires_confirmation: $this->requiresConfirmation($candidate)
        );
    }

    /**
     * Generate Arabic reason string
     * 
     * Format (Charter Part 3, Section 6.2):
     * - Primary match type
     * - Confirmation/rejection context
     * - Confidence interpretation
     * 
     * @param string $primarySource
     * @param int $confidence
     * @param int $confirmations
     * @param int $rejections
     * @return string Arabic reason
     */
    private function generateReasonArabic(
        string $primarySource,
        int $confidence,
        int $confirmations,
        int $rejections
    ): string {
        // Base reason by signal type
        $baseReason = match($primarySource) {
            'alias_exact' => 'تطابق دقيق',
            'entity_anchor_unique' => 'تطابق بالكيان المميز',
            'entity_anchor_generic' => 'تطابق بالكيان العام',
            'fuzzy_official_strong' => 'تشابه قوي مع الاسم الرسمي',
            'fuzzy_official_medium' => 'تشابه متوسط مع الاسم الرسمي',
            'fuzzy_official_weak' => 'تشابه ضعيف مع الاسم الرسمي',
            'historical_frequent' => 'استخدام متكرر سابقاً',
            'historical_occasional' => 'استخدام سابق',
            default => 'اقتراح مطابق',
        };

        // Add confirmation context
        if ($confirmations > 0) {
            $baseReason .= " + تم تأكيده {$confirmations} " . ($confirmations == 1 ? 'مرة' : 'مرات');
        }

        // Add rejection context (if any)
        if ($rejections > 0) {
            $baseReason .= " (رُفض {$rejections} " . ($rejections == 1 ? 'مرة' : 'مرات') . ")";
        }

        return $baseReason;
    }

    /**
     * Determine if suggestion requires explicit confirmation
     * 
     * Heuristic: Requires confirmation if:
     * - Ambiguous (conflicting signals)
     * - Low confidence (< 65)
     * - Has rejections but no confirmations
     * 
     * @param array $candidate
     * @return bool
     */
    private function requiresConfirmation(array $candidate): bool
    {
        if ($candidate['is_ambiguous']) {
            return true;
        }

        if ($candidate['confidence'] < 65) {
            return true;
        }

        if ($candidate['rejection_count'] > 0 && $candidate['confirmation_count'] === 0) {
            return true;
        }

        return false;
    }
}
