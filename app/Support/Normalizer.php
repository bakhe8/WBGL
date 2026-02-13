<?php
declare(strict_types=1);

namespace App\Support;

use App\Support\ArabicNormalizer;

/**
 * Normalizer - Unified normalization utility
 * 
 * All supplier text normalization now uses ArabicNormalizer as the base.
 * This ensures consistency across the entire system.
 */
class Normalizer
{
    /**
     * Normalize general names using ArabicNormalizer
     * 
     * @param string $value Input text
     * @return string Normalized text
     */
    public function normalizeName(string $value): string
    {
        return ArabicNormalizer::normalize($value);
    }

    /**
     * Normalize supplier names
     * 
     * Uses ArabicNormalizer for base normalization, then optionally
     * removes common company words (disabled for better matching)
     * 
     * @param string $value Supplier name
     * @return string Normalized supplier name
     */
    public function normalizeSupplierName(string $value): string
    {
        // Use ArabicNormalizer for complete normalization
        $normalized = ArabicNormalizer::normalize($value);
        
        // Optional: Remove common company words
        // DISABLED for now - better to keep full name for accuracy
        // If you need this, uncomment the code below:
        
        /*
        $stopWords = [
            'شركة', 'شركه', 'مؤسسة', 'مؤسسه', 'مكتب', 'مصنع',
            'trading', 'est', 'establishment', 'company', 'co', 'ltd',
            'limited', 'llc', 'inc', 'international', 'global'
        ];
        
        $parts = preg_split('/\s+/u', $normalized);
        $filtered = array_filter($parts, fn($p) => $p !== '' && !in_array($p, $stopWords, true));
        $normalized = implode(' ', $filtered);
        $normalized = preg_replace('/\s+/u', ' ', $normalized);
        $normalized = trim($normalized);
        */
        
        return $normalized;
    }

    /**
     * Normalize bank names - delegates to specialized BankNormalizer
     * 
     * @param string $value Bank name
     * @return string Normalized bank name
     * @see BankNormalizer For specialized bank normalization logic
     */
    public function normalizeBankName(string $value): string
    {
        return BankNormalizer::normalize($value);
    }

    /**
     * Normalize bank short codes (e.g., SAIB → SAIB)
     * 
     * @param string $code Bank short code
     * @return string Normalized short code
     */
    public function normalizeBankShortCode(string $code): string
    {
        $code = strtoupper(trim($code));
        return preg_replace('/[^A-Z0-9]/', '', $code) ?? '';
    }

    /**
     * Make supplier key (normalized without spaces)
     * 
     * Used for deduplication and matching purposes.
     * 
     * @param string $value Supplier name
     * @return string Normalized key without spaces
     */
    public function makeSupplierKey(string $value): string
    {
        $norm = $this->normalizeSupplierName($value);
        return str_replace(' ', '', $norm);
    }
}
