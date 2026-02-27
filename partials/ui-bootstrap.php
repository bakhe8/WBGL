<?php
declare(strict_types=1);

use App\Support\AuthService;
use App\Support\DirectionResolver;
use App\Support\Guard;
use App\Support\LocaleResolver;
use App\Support\Settings;
use App\Support\ThemeResolver;
use App\Support\UiPolicy;
use App\Support\ViewPolicy;

$wbglUiSettings = Settings::getInstance();
$wbglUiUser = AuthService::getCurrentUser();
$wbglLocale = LocaleResolver::resolve($wbglUiUser, $wbglUiSettings);
$wbglDirection = DirectionResolver::resolve(
    $wbglLocale['locale'],
    $wbglUiUser?->preferredDirection ?? 'auto',
    (string)$wbglUiSettings->get('DEFAULT_DIRECTION', 'auto')
);
$wbglTheme = ThemeResolver::resolve($wbglUiUser?->preferredTheme ?? null, $wbglUiSettings);
$wbglPermissions = Guard::permissions();
$wbglViewFile = basename((string)($_SERVER['PHP_SELF'] ?? ''));
$wbglViewPermission = ViewPolicy::requiredPermissionForView($wbglViewFile);
?>
<script>
    window.WBGL_BOOTSTRAP = Object.assign({}, window.WBGL_BOOTSTRAP || {}, {
        defaults: {
            locale: <?= json_encode($wbglUiSettings->get('DEFAULT_LOCALE', 'ar'), JSON_UNESCAPED_UNICODE) ?>,
            theme: <?= json_encode($wbglUiSettings->get('DEFAULT_THEME', 'system'), JSON_UNESCAPED_UNICODE) ?>,
            direction: <?= json_encode($wbglUiSettings->get('DEFAULT_DIRECTION', 'auto'), JSON_UNESCAPED_UNICODE) ?>
        },
        flags: {
            production_mode: <?= $wbglUiSettings->isProductionMode() ? 'true' : 'false' ?>
        },
        route: {
            view: <?= json_encode($wbglViewFile, JSON_UNESCAPED_UNICODE) ?>,
            required_permission: <?= json_encode($wbglViewPermission, JSON_UNESCAPED_UNICODE) ?>
        },
        policy: {
            capability_map: <?= json_encode(UiPolicy::capabilityMap(), JSON_UNESCAPED_UNICODE) ?>
        },
        ui: {
            allowed: {
                locales: ["ar", "en"],
                themes: <?= json_encode(ThemeResolver::allowedThemes(), JSON_UNESCAPED_UNICODE) ?>,
                direction_overrides: ["auto", "rtl", "ltr"]
            }
        },
        user: <?= json_encode($wbglUiUser ? [
            'id' => $wbglUiUser->id,
            'username' => $wbglUiUser->username,
            'full_name' => $wbglUiUser->fullName,
            'role_id' => $wbglUiUser->roleId,
            'permissions' => $wbglPermissions,
            'preferences' => [
                'language' => $wbglLocale['locale'],
                'theme' => $wbglTheme['theme'],
                'direction' => $wbglDirection['direction'],
                'direction_override' => $wbglDirection['override']
            ]
        ] : null, JSON_UNESCAPED_UNICODE) ?>
    });
</script>
