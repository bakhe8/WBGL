<?php
declare(strict_types=1);

namespace App\Support;

use PDO;
use RuntimeException;

/**
 * Helper utilities for stale-write protection around guarantee_decisions.
 */
final class GuaranteeDecisionConcurrencyGuard
{
    /**
     * @return array<string,mixed>
     */
    public static function snapshot(PDO $db, int $guaranteeId): array
    {
        $stmt = $db->prepare(
            "SELECT status, workflow_step, active_action, signatures_received, is_locked, supplier_id, bank_id
             FROM guarantee_decisions
             WHERE guarantee_id = ?
             LIMIT 1"
        );
        $stmt->execute([$guaranteeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            throw new RuntimeException('لا يوجد قرار لهذا الضمان.');
        }

        return self::normalize($row);
    }

    /**
     * @return array<string,mixed>
     */
    public static function lockSnapshot(PDO $db, int $guaranteeId): array
    {
        $stmt = $db->prepare(
            "SELECT status, workflow_step, active_action, signatures_received, is_locked, supplier_id, bank_id
             FROM guarantee_decisions
             WHERE guarantee_id = ?
             FOR UPDATE"
        );
        $stmt->execute([$guaranteeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            throw new RuntimeException('لا يوجد قرار لهذا الضمان.');
        }

        return self::normalize($row);
    }

    /**
     * @param array<string,mixed> $expected
     * @param array<string,mixed> $actual
     * @param array<int,string> $fields
     */
    public static function assertExpectedSnapshot(
        array $expected,
        array $actual,
        array $fields,
        string $message = 'تم تحديث السجل بواسطة مستخدم آخر. يرجى إعادة التحميل ثم إعادة المحاولة.'
    ): void {
        foreach ($fields as $field) {
            $expectedValue = self::normalizeField($field, $expected[$field] ?? null);
            $actualValue = self::normalizeField($field, $actual[$field] ?? null);
            if ($expectedValue === $actualValue) {
                continue;
            }

            throw new ConcurrencyConflictException($message, [
                'field' => $field,
                'expected' => $expectedValue,
                'actual' => $actualValue,
            ]);
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function normalize(array $row): array
    {
        return [
            'status' => self::normalizeField('status', $row['status'] ?? null),
            'workflow_step' => self::normalizeField('workflow_step', $row['workflow_step'] ?? null),
            'active_action' => self::normalizeField('active_action', $row['active_action'] ?? null),
            'signatures_received' => self::normalizeField('signatures_received', $row['signatures_received'] ?? null),
            'is_locked' => self::normalizeField('is_locked', $row['is_locked'] ?? null),
            'supplier_id' => self::normalizeField('supplier_id', $row['supplier_id'] ?? null),
            'bank_id' => self::normalizeField('bank_id', $row['bank_id'] ?? null),
        ];
    }

    private static function normalizeField(string $field, mixed $value): mixed
    {
        return match ($field) {
            'status', 'workflow_step', 'active_action' => strtolower(trim((string)$value)),
            'signatures_received' => (int)$value,
            'is_locked' => (bool)$value,
            'supplier_id', 'bank_id' => (int)$value,
            default => is_scalar($value) ? (string)$value : $value,
        };
    }
}

