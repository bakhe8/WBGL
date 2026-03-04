<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\AuthService;
use App\Support\DirectionResolver;
use App\Support\LocaleResolver;
use App\Support\Settings;

/**
 * SettingsDashboardService
 *
 * Prepares settings page read-model so the view remains focused on rendering.
 */
final class SettingsDashboardService
{
    /**
     * @return array<string,mixed>
     */
    public static function buildViewModel(?string $acceptLanguage = null): array
    {
        $settings = new Settings();
        $currentSettings = $settings->all();
        $currentUser = AuthService::getCurrentUser();

        $localeInfo = LocaleResolver::resolve(
            $currentUser,
            $settings,
            $acceptLanguage
        );
        $pageLocale = (string)($localeInfo['locale'] ?? 'ar');

        $directionInfo = DirectionResolver::resolve(
            $pageLocale,
            $currentUser?->preferredDirection ?? 'auto',
            (string)$settings->get('DEFAULT_DIRECTION', 'auto')
        );
        $pageDirection = (string)($directionInfo['direction'] ?? ($pageLocale === 'ar' ? 'rtl' : 'ltr'));

        return [
            'settings' => $settings,
            'currentSettings' => $currentSettings,
            'currentUser' => $currentUser,
            'localeInfo' => $localeInfo,
            'pageLocale' => $pageLocale,
            'pageDirection' => $pageDirection,
            'currentDateTimeLabel' => self::formatCurrentDateTimeLabel(),
        ];
    }

    private static function formatCurrentDateTimeLabel(): string
    {
        try {
            return date('Y-m-d H:i:s') . ' (' . date_default_timezone_get() . ')';
        } catch (\Throwable) {
            return date('Y-m-d H:i:s');
        }
    }
}
