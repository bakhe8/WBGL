<?php
declare(strict_types=1);

namespace App\Support;

class DirectionResolver
{
    private const RTL_LOCALES = ['ar'];
    private const OVERRIDES = ['auto', 'rtl', 'ltr'];

    /**
     * @return array{direction:string,source:string,override:string}
     */
    public static function resolve(string $locale, ?string $userOverride = null, ?string $defaultOverride = null): array
    {
        $normalizedUser = self::normalizeOverride($userOverride);
        if ($normalizedUser !== 'auto') {
            return [
                'direction' => $normalizedUser,
                'source' => 'user_override',
                'override' => $normalizedUser,
            ];
        }

        $normalizedDefault = self::normalizeOverride($defaultOverride);
        if ($normalizedDefault !== 'auto') {
            return [
                'direction' => $normalizedDefault,
                'source' => 'org_default',
                'override' => $normalizedDefault,
            ];
        }

        return [
            'direction' => self::localeDirection($locale),
            'source' => 'locale',
            'override' => 'auto',
        ];
    }

    public static function localeDirection(string $locale): string
    {
        $normalized = LocaleResolver::normalize($locale) ?? 'ar';
        return in_array($normalized, self::RTL_LOCALES, true) ? 'rtl' : 'ltr';
    }

    public static function normalizeOverride(?string $value): string
    {
        $candidate = strtolower(trim((string)$value));
        if (!in_array($candidate, self::OVERRIDES, true)) {
            return 'auto';
        }
        return $candidate;
    }
}

