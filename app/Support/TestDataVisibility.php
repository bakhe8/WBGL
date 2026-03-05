<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Centralized visibility rules for test data.
 *
 * Behavior:
 * - In production mode: test data is always hidden.
 * - Outside production: test data is shown by default.
 * - include_test_data can explicitly force show/hide:
 *   - truthy: 1,true,yes,on
 *   - falsy: 0,false,no,off
 */
final class TestDataVisibility
{
    /**
     * Resolve whether test data should be included for current request scope.
     *
     * @param array<string,mixed>|null $source
     */
    public static function includeTestData(Settings $settings, ?array $source = null): bool
    {
        if ($settings->isProductionMode()) {
            return false;
        }

        $source = is_array($source) ? $source : $_GET;
        if (!array_key_exists('include_test_data', $source)) {
            return true;
        }
        $raw = $source['include_test_data'] ?? null;

        if (is_bool($raw)) {
            return $raw;
        }
        if (is_int($raw)) {
            return $raw === 1;
        }
        if (!is_string($raw)) {
            return true;
        }

        $normalized = strtolower(trim($raw));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return true;
    }

    /**
     * Ensure links preserve include_test_data flag when active.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public static function withQueryFlag(array $params, bool $includeTestData): array
    {
        $params['include_test_data'] = $includeTestData ? '1' : '0';

        return $params;
    }

    /**
     * Heuristic to identify test-like batches by identifier/name/notes.
     *
     * This is used for view-level isolation to avoid surfacing legacy
     * experimental batches as operational data in default mode.
     */
    public static function isTestLikeBatch(
        string $batchIdentifier,
        ?string $batchName = null,
        ?string $batchNotes = null
    ): bool {
        $id = strtolower(trim($batchIdentifier));
        $name = strtolower(trim((string)$batchName));
        $notes = strtolower(trim((string)$batchNotes));

        $idPatterns = [
            'test_',
            'test data',
            'integration_flow',
            'email_import_draft',
            'copyof',
            'book1',
            'sim_import',
        ];

        foreach ($idPatterns as $pattern) {
            if ($pattern !== '' && str_contains($id, $pattern)) {
                return true;
            }
        }

        $text = $name . ' ' . $notes;
        $textPatterns = [
            'test',
            'اختبار',
            'copy of',
        ];

        foreach ($textPatterns as $pattern) {
            if ($pattern !== '' && str_contains($text, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
