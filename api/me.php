<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use App\Support\AuthService;
use App\Support\DirectionResolver;
use App\Support\Guard;
use App\Support\LocaleResolver;
use App\Support\Settings;
use App\Support\ThemeResolver;

wbgl_api_require_login();

$user = AuthService::getCurrentUser();
if (!$user) {
    wbgl_api_compat_fail(401, 'Unauthorized', [], 'permission');
}

$settings = Settings::getInstance();
$locale = LocaleResolver::resolve($user, $settings);
$direction = DirectionResolver::resolve(
    $locale['locale'],
    $user->preferredDirection ?? 'auto',
    (string)$settings->get('DEFAULT_DIRECTION', 'auto')
);
$theme = ThemeResolver::resolve($user->preferredTheme ?? null, $settings);
$permissions = Guard::permissions();

wbgl_api_compat_success([
    'user' => [
        'id' => $user->id,
        'username' => $user->username,
        'full_name' => $user->fullName,
        'email' => $user->email,
        'role_id' => $user->roleId,
        'permissions' => $permissions,
        'preferences' => [
            'language' => $locale['locale'],
            'theme' => $theme['theme'],
            'direction' => $direction['direction'],
            'direction_override' => $direction['override'],
            'resolution' => [
                'locale_source' => $locale['source'],
                'direction_source' => $direction['source'],
                'theme_source' => $theme['source'],
            ],
        ],
    ],
]);
