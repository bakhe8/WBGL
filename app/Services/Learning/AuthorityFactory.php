<?php

namespace App\Services\Learning;

use App\Services\Learning\UnifiedLearningAuthority;
use App\Services\Learning\ConfidenceCalculatorV2;
use App\Services\Learning\SuggestionFormatter;
use App\Services\Learning\Feeders\AliasSignalFeeder;
use App\Services\Learning\Feeders\LearningSignalFeeder;
use App\Services\Learning\Feeders\FuzzySignalFeeder;
use App\Services\Learning\Feeders\AnchorSignalFeeder;
use App\Services\Learning\Feeders\HistoricalSignalFeeder;
use App\Support\Normalizer;
use App\Repositories\SupplierRepository;
use App\Repositories\SupplierAlternativeNameRepository;
use App\Repositories\LearningRepository;
use App\Repositories\GuaranteeDecisionRepository;
use App\Services\Suggestions\ArabicEntityExtractor;

/**
 * Authority Factory
 * 
 * Creates and wires UnifiedLearningAuthority with all signal feeders.
 * This is the SERVICE PROVIDER for the Authority.
 * 
 * Usage:
 * ```php
 * $factory = new AuthorityFactory();
 * $authority = $factory->create();
 * $suggestions = $authority->getSuggestions('شركة النورس');
 * ```
 */
class AuthorityFactory
{
    /**
     * Create fully-wired UnifiedLearningAuthority
     * 
     * @return UnifiedLearningAuthority
     */
    public static function create(): UnifiedLearningAuthority
    {
        // 1. Create dependencies
        $normalizer = new Normalizer();
        // ✅ INJECT SETTINGS for dynamic thresholds
        $settings = new \App\Support\Settings();
        $calculator = new ConfidenceCalculatorV2($settings);
        
        $supplierRepo = new SupplierRepository();
        $formatter = new SuggestionFormatter($supplierRepo);

        // 2. Create Authority
        $authority = new UnifiedLearningAuthority(
            $normalizer,
            $calculator,
            $formatter
        );

        // 3. Register all feeders
        $authority
            ->registerFeeder(self::createAliasFeeder())
            ->registerFeeder(self::createLearningFeeder())
            ->registerFeeder(self::createFuzzyFeeder())
            ->registerFeeder(self::createAnchorFeeder())
            ->registerFeeder(self::createHistoricalFeeder());

        return $authority;
    }

    /**
     * Create Alias Signal Feeder
     */
    private static function createAliasFeeder(): AliasSignalFeeder
    {
        $aliasRepo = new SupplierAlternativeNameRepository();
        return new AliasSignalFeeder($aliasRepo);
    }

    /**
     * Create Learning Signal Feeder
     */
    private static function createLearningFeeder(): LearningSignalFeeder
    {
        $learningRepo = new LearningRepository();
        return new LearningSignalFeeder($learningRepo);
    }

    /**
     * Create Fuzzy Signal Feeder
     */
    private static function createFuzzyFeeder(): FuzzySignalFeeder
    {
        $supplierRepo = new SupplierRepository();
        return new FuzzySignalFeeder($supplierRepo);
    }

    /**
     * Create Anchor Signal Feeder
     */
    private static function createAnchorFeeder(): AnchorSignalFeeder
    {
        $entityExtractor = new ArabicEntityExtractor();
        $supplierRepo = new SupplierRepository();
        return new AnchorSignalFeeder($entityExtractor, $supplierRepo);
    }

    /**
     * Create Historical Signal Feeder
     */
    private static function createHistoricalFeeder(): HistoricalSignalFeeder
    {
        $decisionRepo = new GuaranteeDecisionRepository();
        return new HistoricalSignalFeeder($decisionRepo);
    }
}
