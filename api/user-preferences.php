<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use App\Services\AuditTrailService;
use App\Repositories\UserRepository;
use App\Support\AuthService;
use App\Support\Database;
use App\Support\DirectionResolver;
use App\Support\Input;
use App\Support\LocaleResolver;
use App\Support\Settings;
use App\Support\ThemeResolver;

wbgl_api_json_headers();
wbgl_api_require_login();

$user = AuthService::getCurrentUser();
if (!$user) {
    wbgl_api_fail(401, 'Unauthorized');
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$settings = Settings::getInstance();

if ($method === 'GET') {
    $locale = LocaleResolver::resolve($user, $settings);
    $direction = DirectionResolver::resolve(
        $locale['locale'],
        $user->preferredDirection ?? 'auto',
        (string)$settings->get('DEFAULT_DIRECTION', 'auto')
    );
    $theme = ThemeResolver::resolve($user->preferredTheme ?? null, $settings);
    echo json_encode([
        'success' => true,
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
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method !== 'POST') {
    wbgl_api_fail(405, 'Method not allowed');
}

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

$languageInput = Input::string($input, 'language', '');
$themeInput = Input::string($input, 'theme', '');
$directionInput = Input::string($input, 'direction_override', Input::string($input, 'direction', ''));

$hasLanguage = $languageInput !== '';
$hasTheme = $themeInput !== '';
$hasDirection = $directionInput !== '';

if (!$hasLanguage && !$hasTheme && !$hasDirection) {
    wbgl_api_fail(422, 'At least one preference is required');
}

$language = $hasLanguage ? LocaleResolver::normalize($languageInput) : null;
if ($hasLanguage && $language === null) {
    wbgl_api_fail(422, 'language must be ar or en');
}

$theme = $hasTheme ? ThemeResolver::normalize($themeInput) : null;
if ($hasTheme && $theme === null) {
    wbgl_api_fail(422, 'theme must be one of: system, light, dark, desert');
}

$directionOverride = $hasDirection ? DirectionResolver::normalizeOverride($directionInput) : null;

$db = Database::connect();
$repo = new UserRepository($db);
$repo->updateUiPreferences(
    (int)$user->id,
    $language,
    $theme,
    $directionOverride
);

if ($language !== null) {
    $user->preferredLanguage = $language;
    $_SESSION['ui_language'] = $language;
}
if ($theme !== null) {
    $user->preferredTheme = $theme;
    $_SESSION['ui_theme'] = $theme;
}
if ($directionOverride !== null) {
    $user->preferredDirection = $directionOverride;
    $_SESSION['ui_direction_override'] = $directionOverride;
}

AuthService::forceAuthenticatedUser($user);

$resolvedLocale = LocaleResolver::resolve($user, $settings);
$resolvedDirection = DirectionResolver::resolve(
    $resolvedLocale['locale'],
    $user->preferredDirection ?? 'auto',
    (string)$settings->get('DEFAULT_DIRECTION', 'auto')
);
$resolvedTheme = ThemeResolver::resolve($user->preferredTheme ?? null, $settings);

AuditTrailService::record(
    'ui_preference_updated',
    'update',
    'user',
    (string)$user->id,
    [
        'updated_fields' => [
            'language' => $language,
            'theme' => $theme,
            'direction_override' => $directionOverride,
        ],
        'resolved' => [
            'language' => $resolvedLocale['locale'],
            'theme' => $resolvedTheme['theme'],
            'direction' => $resolvedDirection['direction'],
        ],
    ]
);

echo json_encode([
    'success' => true,
    'preferences' => [
        'language' => $resolvedLocale['locale'],
        'theme' => $resolvedTheme['theme'],
        'direction' => $resolvedDirection['direction'],
        'direction_override' => $resolvedDirection['override'],
        'resolution' => [
            'locale_source' => $resolvedLocale['source'],
            'direction_source' => $resolvedDirection['source'],
            'theme_source' => $resolvedTheme['source'],
        ],
    ],
], JSON_UNESCAPED_UNICODE);
