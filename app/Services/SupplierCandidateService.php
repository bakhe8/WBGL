<?php
declare(strict_types=1);

namespace App\Services;

/**
 * @deprecated Since 2026-01-03 (Phase 4 - 100% Authority Cutover)
 * 
 * This service has been REPLACED by:
 * - FuzzySignalFeeder (for fuzzy matching signals)
 * - UnifiedLearningAuthority (for suggestion integration)
 * 
 * ❌ DO NOT USE - Will be removed in Phase 6
 * 
 * Authority integrates fuzzy matching as one of many signals.
 * No need for separate candidate service.
 * 
 * Removal date: 2026-04-03 (3 months from deprecation)
 */
class SupplierCandidateService
{
    public function __construct()
    {
        trigger_error(
            'SupplierCandidateService is deprecated. Fuzzy matching now integrated in UnifiedLearningAuthority.',
            E_USER_DEPRECATED
        );
    }

    /**
     * @deprecated Use UnifiedLearningAuthority::getSuggestions()
     */
    public function supplierCandidates(string $rawName): array
    {
        throw new \RuntimeException(
            'SupplierCandidateService is deprecated. '
            . 'Use UnifiedLearningAuthority which includes fuzzy matching signals.'
        );
    }
}
