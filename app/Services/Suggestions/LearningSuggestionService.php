<?php
declare(strict_types=1);

namespace App\Services\Suggestions;

/**
 * @deprecated Since 2026-01-03 (Phase 4 - 100% Authority Cutover)
 * 
 * This service has been REPLACED by UnifiedLearningAuthority.
 * 
 * ❌ DO NOT USE - Will be removed in Phase 6
 * 
 * Migration: Use App\Services\Learning\UnifiedLearningAuthority::getSuggestions()
 * 
 * Authority provides:
 * - Unified confidence (0-100)
 * - Canonical SuggestionDTO format
 * - Charter-compliant scoring
 * - Predictable results
 * 
 * Removal date: 2026-04-03 (3 months from deprecation)
 */
class LearningSuggestionService
{
    public function __construct()
    {
        trigger_error(
            'LearningSuggestionService is deprecated. Use UnifiedLearningAuthority instead.',
            E_USER_DEPRECATED
        );
    }

    /**
     * @deprecated Use UnifiedLearningAuthority::getSuggestions()
     */
    public function getSuggestions(string $rawName): array
    {
        throw new \RuntimeException(
            'LearningSuggestionService is deprecated. '
            . 'Use App\Services\Learning\UnifiedLearningAuthority instead.'
        );
    }
}
