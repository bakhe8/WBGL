<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Canonical timeline presentation/storage normalizer.
 *
 * - Unifies actor representation for old/new events.
 * - Normalizes status/workflow values for locale-safe display.
 */
final class TimelinePresentationNormalizer
{
    /**
     * @param array<string,mixed> $event
     * @return array{
     *   kind:string,
     *   display:string,
     *   user_id:int|null,
     *   username:string|null,
     *   email:string|null,
     *   i18n_key:string|null,
     *   icon:string
     * }
     */
    public static function actorFromEvent(array $event): array
    {
        $kind = self::normalizeKind((string)($event['actor_kind'] ?? ''));
        $display = self::normalizeText($event['actor_display'] ?? null);
        $userId = self::normalizeInt($event['actor_user_id'] ?? null);
        $username = self::normalizeText($event['actor_username'] ?? null);
        $email = self::normalizeText($event['actor_email'] ?? null);
        $createdBy = self::normalizeText($event['created_by'] ?? null);

        $legacy = self::parseLegacyCreator($createdBy ?? '');

        if ($kind === '') {
            $kind = $legacy['kind'];
        }
        if ($display === null || $display === '') {
            $display = $legacy['display'];
        }
        if ($userId === null) {
            $userId = $legacy['user_id'];
        }
        if ($username === null || $username === '') {
            $username = $legacy['username'];
        }
        if ($email === null || $email === '') {
            $email = $legacy['email'];
        }

        // Guardrail: if stored kind is incorrect but textual actor markers indicate system,
        // always force system presentation to avoid leaking technical actor strings.
        $displayCheck = is_string($display) ? $display : '';
        if ($kind !== 'system' && (self::isSystemDisplay($displayCheck) || self::isSystemDisplay((string)$createdBy))) {
            $kind = 'system';
            $display = self::defaultActorDisplay('system');
            $userId = null;
            $username = null;
            $email = null;
        }

        // Ensure technical placeholder labels never leak to UI.
        if ($kind === 'user' && $display !== null && self::isGenericUserDisplay($display)) {
            $display = self::defaultActorDisplay('user');
        }

        if ($display === null || $display === '') {
            $display = self::defaultActorDisplay($kind);
        }

        $i18nKey = null;
        if ($kind === 'system') {
            $i18nKey = 'timeline.actor.system';
        } elseif ($kind === 'service') {
            $i18nKey = 'timeline.actor.service';
        } elseif ($kind === 'user' && self::isGenericUserDisplay($display)) {
            $i18nKey = 'timeline.actor.user';
        }

        return [
            'kind' => $kind,
            'display' => $display,
            'user_id' => $userId,
            'username' => $username,
            'email' => $email,
            'i18n_key' => $i18nKey,
            'icon' => $kind === 'system' ? '🤖' : '👤',
        ];
    }

    /**
     * @return array{
     *   actor_kind:string,
     *   actor_display:string,
     *   actor_user_id:int|null,
     *   actor_username:string|null,
     *   actor_email:string|null
     * }
     */
    public static function actorStorageFromCreator(string $creator): array
    {
        $parsed = self::parseLegacyCreator($creator);

        return [
            'actor_kind' => $parsed['kind'],
            'actor_display' => $parsed['display'],
            'actor_user_id' => $parsed['user_id'],
            'actor_username' => $parsed['username'],
            'actor_email' => $parsed['email'],
        ];
    }

    /**
     * @param string $field
     * @param mixed $value
     * @return array{display:string,i18n_key:string|null}
     */
    public static function presentValue(string $field, mixed $value): array
    {
        if (is_array($value)) {
            $name = self::normalizeText($value['name'] ?? null);
            if ($name !== null && $name !== '') {
                return ['display' => $name, 'i18n_key' => null];
            }

            $scalar = self::normalizeText($value['value'] ?? null);
            if ($scalar !== null && $scalar !== '') {
                return ['display' => $scalar, 'i18n_key' => null];
            }

            return ['display' => self::emptyDisplay(), 'i18n_key' => 'timeline.value.empty'];
        }

        if ($value === null || (is_string($value) && trim($value) === '')) {
            return ['display' => self::emptyDisplay(), 'i18n_key' => 'timeline.value.empty'];
        }

        if (is_bool($value)) {
            return [
                'display' => $value ? 'Yes' : 'No',
                'i18n_key' => $value ? 'timeline.value.yes' : 'timeline.value.no'
            ];
        }

        $raw = trim((string)$value);
        $normalized = mb_strtolower($raw, 'UTF-8');

        if ($field === 'status') {
            $canonical = $normalized === 'approved' ? 'ready' : $normalized;
            $statusMap = [
                'pending' => 'timeline.status.pending',
                'ready' => 'timeline.status.ready',
                'released' => 'timeline.status.released',
                'extended' => 'timeline.status.extended',
                'reduced' => 'timeline.status.reduced',
                'issued' => 'timeline.status.issued',
            ];
            if (isset($statusMap[$canonical])) {
                return ['display' => $canonical, 'i18n_key' => $statusMap[$canonical]];
            }
            return ['display' => $raw, 'i18n_key' => null];
        }

        if ($field === 'workflow_step') {
            $workflowMap = [
                'draft' => 'timeline.workflow_step.draft',
                'audited' => 'timeline.workflow_step.audited',
                'analyzed' => 'timeline.workflow_step.analyzed',
                'supervised' => 'timeline.workflow_step.supervised',
                'approved' => 'timeline.workflow_step.approved',
                'signed' => 'timeline.workflow_step.signed',
            ];
            if (isset($workflowMap[$normalized])) {
                return ['display' => $normalized, 'i18n_key' => $workflowMap[$normalized]];
            }
            return ['display' => $raw, 'i18n_key' => null];
        }

        if ($field === 'active_action') {
            $actionMap = [
                'extension' => 'timeline.action.extension',
                'reduction' => 'timeline.action.reduction',
                'release' => 'timeline.action.release',
            ];
            if (isset($actionMap[$normalized])) {
                return ['display' => $normalized, 'i18n_key' => $actionMap[$normalized]];
            }
        }

        return ['display' => $raw, 'i18n_key' => null];
    }

    /**
     * @param array<string,mixed> $change
     * @return array<string,mixed>
     */
    public static function normalizeChange(array $change): array
    {
        $field = (string)($change['field'] ?? '');
        $old = self::presentValue($field, $change['old_value'] ?? null);
        $new = self::presentValue($field, $change['new_value'] ?? null);

        $change['old_present'] = $old;
        $change['new_present'] = $new;

        return $change;
    }

    /**
     * @return array{kind:string,display:string,user_id:int|null,username:string|null,email:string|null}
     */
    private static function parseLegacyCreator(string $creator): array
    {
        $normalizedCreator = trim($creator);
        if ($normalizedCreator === '') {
            return [
                'kind' => 'system',
                'display' => self::defaultActorDisplay('system'),
                'user_id' => null,
                'username' => null,
                'email' => null,
            ];
        }

        $lower = mb_strtolower($normalizedCreator, 'UTF-8');
        if (self::isSystemDisplay($lower)) {
            return [
                'kind' => 'system',
                'display' => self::defaultActorDisplay('system'),
                'user_id' => null,
                'username' => null,
                'email' => null,
            ];
        }

        if (self::isGenericUserDisplay($lower)) {
            return [
                'kind' => 'user',
                'display' => self::defaultActorDisplay('user'),
                'user_id' => null,
                'username' => null,
                'email' => null,
            ];
        }

        $name = $normalizedCreator;
        $inside = '';
        if (preg_match('/^\s*(.*?)\s*\((.*?)\)\s*$/u', $normalizedCreator, $matches) === 1) {
            $name = trim((string)$matches[1]);
            $inside = trim((string)$matches[2]);
        }

        $username = null;
        $userId = null;
        $email = null;
        if ($inside !== '') {
            foreach (explode('|', $inside) as $partRaw) {
                $part = trim((string)$partRaw);
                if ($part === '') {
                    continue;
                }

                if ($username === null && preg_match('/^@([A-Za-z0-9_.-]+)$/', $part, $m) === 1) {
                    $username = $m[1];
                    continue;
                }

                if ($userId === null && preg_match('/^id\s*:\s*(\d+)$/i', $part, $m) === 1) {
                    $userId = (int)$m[1];
                    continue;
                }

                if ($email === null && filter_var($part, FILTER_VALIDATE_EMAIL)) {
                    $email = $part;
                }
            }
        }

        if ($username === null && preg_match('/@([A-Za-z0-9_.-]+)/', $normalizedCreator, $m) === 1) {
            $username = $m[1];
        }
        if ($userId === null && preg_match('/\bid\s*:\s*(\d+)\b/i', $normalizedCreator, $m) === 1) {
            $userId = (int)$m[1];
        }
        if ($email === null && preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $normalizedCreator, $m) === 1) {
            $email = $m[0];
        }

        $display = trim($name);
        if ($display === '') {
            if ($username !== null && $username !== '') {
                $display = '@' . $username;
            } elseif ($email !== null && $email !== '') {
                $display = $email;
            } elseif ($userId !== null) {
                $display = 'id:' . $userId;
            } else {
                $display = self::defaultActorDisplay('user');
            }
        }

        $kind = 'user';
        if (str_contains($lower, 'service') || str_contains($lower, 'integration')) {
            $kind = 'service';
        }

        return [
            'kind' => $kind,
            'display' => $display,
            'user_id' => $userId,
            'username' => $username,
            'email' => $email,
        ];
    }

    private static function normalizeKind(string $kind): string
    {
        $normalized = mb_strtolower(trim($kind), 'UTF-8');
        if (in_array($normalized, ['system', 'user', 'service'], true)) {
            return $normalized;
        }
        return '';
    }

    private static function isSystemDisplay(string $value): bool
    {
        $normalized = mb_strtolower(trim($value), 'UTF-8');
        if (in_array($normalized, ['system', 'system ai', 'النظام', 'بواسطة النظام'], true)) {
            return true;
        }

        // Accept technical system actor markers produced by maintenance/backfill scripts.
        return str_starts_with($normalized, 'system:') || str_starts_with($normalized, 'system_');
    }

    private static function isGenericUserDisplay(string $value): bool
    {
        $normalized = mb_strtolower(trim($value), 'UTF-8');
        return in_array(
            $normalized,
            [
                'user',
                'web_user',
                'المستخدم',
                'بواسطة المستخدم',
                'legacy_user_unresolved',
                'legacy_user_historical',
                'legacy user (historical data)',
            ],
            true
        );
    }

    private static function defaultActorDisplay(string $kind): string
    {
        return match ($kind) {
            'service' => 'service',
            'user' => 'user',
            default => 'system',
        };
    }

    private static function emptyDisplay(): string
    {
        return '-';
    }

    private static function normalizeText(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    private static function normalizeInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            $int = (int)$value;
            return $int > 0 ? $int : null;
        }
        return null;
    }
}
