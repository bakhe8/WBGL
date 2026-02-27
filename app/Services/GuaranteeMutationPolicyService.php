<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use PDO;

class GuaranteeMutationPolicyService
{
    /**
     * Enforce read-only behavior for released guarantees, unless emergency override is explicitly authorized.
     *
     * @return array{allowed:bool,is_released:bool,reason:string,break_glass:?array}
     */
    public static function evaluate(
        int $guaranteeId,
        array $input,
        string $actionName,
        string $actor
    ): array {
        if ($guaranteeId <= 0) {
            return [
                'allowed' => false,
                'is_released' => false,
                'reason' => 'guarantee_id is required',
                'break_glass' => null,
            ];
        }

        $db = Database::connect();
        $stmt = $db->prepare(
            'SELECT status, is_locked, locked_reason
             FROM guarantee_decisions
             WHERE guarantee_id = ?
             LIMIT 1'
        );
        $stmt->execute([$guaranteeId]);
        $decision = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($decision)) {
            return [
                'allowed' => true,
                'is_released' => false,
                'reason' => '',
                'break_glass' => null,
            ];
        }

        $status = strtolower(trim((string)($decision['status'] ?? '')));
        $isLocked = (bool)($decision['is_locked'] ?? false);
        $isReleased = $status === 'released' || $isLocked;

        if (!$isReleased) {
            return [
                'allowed' => true,
                'is_released' => false,
                'reason' => '',
                'break_glass' => null,
            ];
        }

        if (BreakGlassService::isRequested($input)) {
            $breakGlass = BreakGlassService::authorizeAndRecord(
                $input,
                $actionName,
                'guarantee',
                (string)$guaranteeId,
                $actor
            );
            return [
                'allowed' => true,
                'is_released' => true,
                'reason' => 'allowed_via_break_glass',
                'break_glass' => $breakGlass,
            ];
        }

        $lockedReason = trim((string)($decision['locked_reason'] ?? ''));
        $reason = 'الضمان في حالة released ومقفل للتعديل. المسموح فقط العرض والطباعة والملاحظات.';
        if ($lockedReason !== '') {
            $reason .= ' السبب: ' . $lockedReason;
        }

        return [
            'allowed' => false,
            'is_released' => true,
            'reason' => $reason,
            'break_glass' => null,
        ];
    }
}
