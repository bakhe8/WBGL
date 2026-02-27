<?php
declare(strict_types=1);

namespace App\Support;

class ThemeResolver
{
    private const THEMES = ['system', 'light', 'dark', 'desert'];

    /**
     * @return array{theme:string,source:string}
     */
    public static function resolve(?string $userPreference, Settings $settings): array
    {
        $userTheme = self::normalize($userPreference);
        if ($userTheme !== null) {
            return ['theme' => $userTheme, 'source' => 'user_preference'];
        }

        $defaultTheme = self::normalize((string)$settings->get('DEFAULT_THEME', 'system')) ?? 'system';
        return ['theme' => $defaultTheme, 'source' => 'org_default'];
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

        return in_array($candidate, self::THEMES, true) ? $candidate : null;
    }

    /**
     * @return string[]
     */
    public static function allowedThemes(): array
    {
        return self::THEMES;
    }
}

