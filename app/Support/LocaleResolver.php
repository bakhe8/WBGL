<?php
declare(strict_types=1);

namespace App\Support;

use App\Models\User;

class LocaleResolver
{
    private const SUPPORTED = ['ar', 'en'];
    private const FALLBACK = 'ar';

    /**
     * @return array{locale:string,source:string}
     */
    public static function resolve(?User $user, Settings $settings, ?string $acceptLanguageHeader = null): array
    {
        $userLocale = self::normalize($user?->preferredLanguage);
        if ($userLocale !== null) {
            return ['locale' => $userLocale, 'source' => 'user_preference'];
        }

        $orgDefault = self::normalize((string)$settings->get('DEFAULT_LOCALE', self::FALLBACK));
        if ($orgDefault !== null) {
            return ['locale' => $orgDefault, 'source' => 'org_default'];
        }

        $browser = self::fromAcceptLanguage($acceptLanguageHeader ?? (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
        if ($browser !== null) {
            return ['locale' => $browser, 'source' => 'browser'];
        }

        return ['locale' => self::FALLBACK, 'source' => 'fallback'];
    }

    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $candidate = strtolower(trim($value));
        if ($candidate === '') {
            return null;
        }

        if (str_contains($candidate, '-')) {
            $candidate = explode('-', $candidate, 2)[0];
        }

        if (str_contains($candidate, '_')) {
            $candidate = explode('_', $candidate, 2)[0];
        }

        return in_array($candidate, self::SUPPORTED, true) ? $candidate : null;
    }

    private static function fromAcceptLanguage(string $header): ?string
    {
        $header = trim($header);
        if ($header === '') {
            return null;
        }

        $parts = explode(',', $header);
        foreach ($parts as $part) {
            $localeRaw = trim(explode(';', $part, 2)[0]);
            $normalized = self::normalize($localeRaw);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }
}

