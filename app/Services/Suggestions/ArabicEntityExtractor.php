<?php

namespace App\Services\Suggestions;

/**
 * Arabic Entity Extractor (Stub)
 * 
 * Extracts entity anchors from Arabic text.
 * This is a STUB for Phase 2 - full implementation in Phase 2B.
 * 
 * Extracts anchors by stripping common prefixes, filtering stop words,
 * and generating compound anchors from adjacent tokens.
 */
class ArabicEntityExtractor
{
    /**
     * Extract entity anchors from text
     * 
     * @param string $text Normalized Arabic text
     * @return array<string> Array of anchors
     */
    public function extractAnchors(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        // Tokenize normalized text and extract distinctive anchors + compounds.
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $stopWords = [
            // Arabic
            'شركة', 'شركه', 'الشركة', 'مؤسسة', 'مؤسسه', 'المؤسسة', 'مكتب', 'المكتب', 'دار',
            'في', 'من', 'الى', 'على', 'عن', 'مع', 'بين', 'و', 'او', 'ثم',
            'مجموعة', 'العالمية', 'الوطنية', 'الدولية', 'العامة', 'عامة',
            'للتجارة', 'التجاره', 'التجارة', 'للتجاره', 'التجارية', 'التجاريه',
            'المقاولات', 'للمقاولات', 'المقاول', 'المقاولون',
            'خدمات', 'الخدمات', 'توريد', 'التوريد', 'استيراد', 'تصدير',
            'المحدودة', 'المحدوده', 'القابضة', 'القابضه', 'الاستثمارية', 'الاستثماريه',
            'الاولى', 'العربية', 'السعودية', 'السعوديه',
            'الصناعية', 'الصناعيه', 'التقنية', 'تقنية', 'التقنيه',

            // English
            'company', 'co', 'corp', 'inc', 'ltd', 'limited', 'llc',
            'establishment', 'est', 'trading', 'general', 'group',
            'international', 'national', 'technology', 'services',
            'contracting', 'engineering', 'works', 'supplies',
            'import', 'export', 'holdings', 'investment', 'arabia', 'saudi',
            'the', 'and', 'for', 'of', 'to', 'in', 'at'
        ];

        $companyPrefixes = [
            'شركة', 'شركه', 'مؤسسة', 'مؤسسه', 'مكتب', 'دار', 'مجموعة', 'مصنع', 'فرع', 'مركز'
        ];

        $compoundAllowedShort = ['بن', 'بنت', 'ابن', 'عبد'];

        $anchors = [];
        $compoundTokens = [];

        foreach ($words as $word) {
            $word = trim($word);
            if ($word === '') {
                continue;
            }

            $word = $this->stripLeadingParticles($word);
            $word = $this->stripCompanyPrefix($word, $companyPrefixes);

            if ($word === '' || $this->isNumericToken($word)) {
                continue;
            }

            $lower = mb_strtolower($word, 'UTF-8');
            if (in_array($lower, $stopWords, true)) {
                continue;
            }

            $length = mb_strlen($word, 'UTF-8');
            if ($length >= 3) {
                $anchors[] = $word;
            }

            if ($length >= 2 || in_array($lower, $compoundAllowedShort, true)) {
                $compoundTokens[] = $word;
            }
        }

        $anchors = array_merge($anchors, $this->buildCompoundAnchors($compoundTokens, $compoundAllowedShort));

        $unique = [];
        foreach ($anchors as $anchor) {
            $unique[$anchor] = true;
        }

        return array_keys($unique);
    }

    private function stripCompanyPrefix(string $word, array $prefixes): string
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($word, $prefix)) {
                $prefixLength = mb_strlen($prefix, 'UTF-8');
                if (mb_strlen($word, 'UTF-8') > $prefixLength + 1) {
                    return trim(mb_substr($word, $prefixLength, null, 'UTF-8'));
                }
            }
        }
        return $word;
    }

    private function stripLeadingParticles(string $word): string
    {
        $particles = ['وال', 'بال', 'كال', 'لل'];
        foreach ($particles as $particle) {
            if (str_starts_with($word, $particle)) {
                $particleLength = mb_strlen($particle, 'UTF-8');
                if (mb_strlen($word, 'UTF-8') > $particleLength + 1) {
                    return trim(mb_substr($word, $particleLength, null, 'UTF-8'));
                }
            }
        }

        return $word;
    }

    /**
     * @param array<int, string> $tokens
     * @param array<int, string> $allowedShort
     * @return array<int, string>
     */
    private function buildCompoundAnchors(array $tokens, array $allowedShort): array
    {
        $anchors = [];
        $count = count($tokens);
        if ($count < 2) {
            return $anchors;
        }

        $allowedLookup = array_fill_keys($allowedShort, true);

        for ($i = 0; $i < $count; $i++) {
            for ($length = 2; $length <= 3; $length++) {
                if ($i + $length > $count) {
                    continue;
                }

                $slice = array_slice($tokens, $i, $length);
                $hasDistinctive = false;

                foreach ($slice as $part) {
                    $partLower = mb_strtolower($part, 'UTF-8');
                    $partLength = mb_strlen($part, 'UTF-8');
                    if ($partLength >= 3 && empty($allowedLookup[$partLower])) {
                        $hasDistinctive = true;
                        break;
                    }
                }

                if ($hasDistinctive) {
                    $anchors[] = implode(' ', $slice);
                }
            }
        }

        return $anchors;
    }

    private function isNumericToken(string $word): bool
    {
        return preg_match('/^\d+$/u', $word) === 1;
    }
}
