<?php
declare(strict_types=1);

namespace App\Services\SmartPaste;

/**
 * Ensures parse responses always carry confidence details consistently.
 *
 * This guard is used by both parse-paste endpoints to avoid drift or
 * accidental loss of confidence strength in one source path.
 */
final class ParseResponseConfidenceGuard
{
    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    public static function strengthen(array $result, string $rawText): array
    {
        if (empty($result['success']) || !is_array($result['extracted'] ?? null)) {
            return $result;
        }

        if (!empty($result['multi'])) {
            return $result;
        }

        $confidence = is_array($result['confidence'] ?? null) ? $result['confidence'] : [];
        $hasConfidence = !empty($confidence);
        $hasOverall = isset($result['overall_confidence']) && is_numeric($result['overall_confidence']);

        if (!$hasConfidence) {
            $confidence = self::calculateConfidence((array)$result['extracted'], $rawText);
            if (!empty($confidence)) {
                $result['confidence'] = $confidence;
            }
        }

        if (!$hasOverall) {
            $result['overall_confidence'] = self::calculateOverallConfidence($confidence);
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $extracted
     * @return array<string,array<string,mixed>>
     */
    private static function calculateConfidence(array $extracted, string $text): array
    {
        $calculator = new ConfidenceCalculator();
        $confidence = [];

        if (!empty($extracted['supplier'])) {
            $confidence['supplier'] = $calculator->calculateSupplierConfidence(
                $text,
                (string)$extracted['supplier'],
                'fuzzy',
                85,
                0
            );
        }

        if (!empty($extracted['bank'])) {
            $confidence['bank'] = $calculator->calculateBankConfidence(
                $text,
                (string)$extracted['bank'],
                'fuzzy',
                85
            );
        }

        if (!empty($extracted['amount'])) {
            $confidence['amount'] = $calculator->calculateAmountConfidence(
                $text,
                (float)$extracted['amount']
            );
        }

        if (!empty($extracted['expiry_date'])) {
            $confidence['expiry_date'] = $calculator->calculateDateConfidence(
                $text,
                (string)$extracted['expiry_date']
            );
        }

        if (!empty($extracted['issue_date'])) {
            $confidence['issue_date'] = $calculator->calculateDateConfidence(
                $text,
                (string)$extracted['issue_date']
            );
        }

        return $confidence;
    }

    /**
     * @param array<string,array<string,mixed>> $confidence
     */
    private static function calculateOverallConfidence(array $confidence): int
    {
        if (empty($confidence)) {
            return 0;
        }

        $scores = array_values(array_filter(
            array_map(
                static fn(array $item): int => (int)($item['confidence'] ?? 0),
                $confidence
            ),
            static fn(int $value): bool => $value > 0
        ));

        if (empty($scores)) {
            return 0;
        }

        return (int)round(array_sum($scores) / count($scores));
    }
}
