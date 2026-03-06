<?php
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

require_once __DIR__ . '/_bootstrap.php';

use App\Support\Settings;
use App\Services\SettingsAuditService;
wbgl_api_require_permission('manage_users');

/**
 * @param array<string,mixed> $settings
 * @return array<string,mixed>
 */
function wbgl_settings_redact_sensitive(array $settings): array
{
    $redacted = $settings;
    foreach ($redacted as $key => $value) {
        $upperKey = strtoupper((string)$key);
        if (
            str_contains($upperKey, 'PASS')
            || str_contains($upperKey, 'PASSWORD')
            || str_contains($upperKey, 'SECRET')
            || str_contains($upperKey, 'TOKEN')
            || str_contains($upperKey, 'API_KEY')
            || str_contains($upperKey, 'PRIVATE_KEY')
        ) {
            $redacted[$key] = is_string($value) && trim($value) !== '' ? '***' : '';
        }
    }

    return $redacted;
}

// Handle POST request to save settings
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!is_array($input)) {
            wbgl_api_compat_fail(400, 'Invalid JSON input');
        }
        
        // Validation
        $errors = [];
        
        // Validate thresholds
        if (isset($input['MATCH_AUTO_THRESHOLD'])) {
            $value = (float)$input['MATCH_AUTO_THRESHOLD'];
            if ($value < 0.0 || $value > 100.0) {
                $errors[] = "MATCH_AUTO_THRESHOLD must be between 0 and 100";
            }
            $input['MATCH_AUTO_THRESHOLD'] = $value;
        }
        $thresholds = ['MATCH_REVIEW_THRESHOLD', 'MATCH_WEAK_THRESHOLD'];
        foreach ($thresholds as $key) {
            if (isset($input[$key])) {
                $value = (float)$input[$key];
                if ($value < 0.0 || $value > 1.0) {
                    $errors[] = "$key must be between 0.0 and 1.0";
                }
                $input[$key] = $value;
            }
        }
        
        // Validate weights (> 0)
        $weights = ['WEIGHT_OFFICIAL', 'WEIGHT_ALT_CONFIRMED', 'WEIGHT_ALT_LEARNING', 'WEIGHT_FUZZY', 'CONFLICT_DELTA'];
        foreach ($weights as $key) {
            if (isset($input[$key])) {
                $value = (float)$input[$key];
                if ($value <= 0.0) {
                    $errors[] = "$key must be greater than 0";
                }
                $input[$key] = $value;
            }
        }
        
        // Validate limits (> 0)
        if (isset($input['CANDIDATES_LIMIT'])) {
            $value = (int)$input['CANDIDATES_LIMIT'];
            if ($value <= 0) {
                $errors[] = "CANDIDATES_LIMIT must be greater than 0";
            }
            $input['CANDIDATES_LIMIT'] = $value;
        }

        if (isset($input['NOTIFICATION_UI_MAX_ITEMS'])) {
            $value = (int)$input['NOTIFICATION_UI_MAX_ITEMS'];
            if ($value < 10 || $value > 200) {
                $errors[] = "NOTIFICATION_UI_MAX_ITEMS must be between 10 and 200";
            }
            $input['NOTIFICATION_UI_MAX_ITEMS'] = $value;
        }

        if (isset($input['NOTIFICATIONS_ENABLED'])) {
            $input['NOTIFICATIONS_ENABLED'] = ((int)$input['NOTIFICATIONS_ENABLED']) === 1 ? 1 : 0;
        }

        if (isset($input['NOTIFICATION_POLICY_OVERRIDES'])) {
            $rawOverrides = $input['NOTIFICATION_POLICY_OVERRIDES'];
            if (is_string($rawOverrides)) {
                $trimmedOverrides = trim($rawOverrides);
                if ($trimmedOverrides === '') {
                    $input['NOTIFICATION_POLICY_OVERRIDES'] = [];
                } else {
                    $decodedOverrides = json_decode($trimmedOverrides, true);
                    if (!is_array($decodedOverrides)) {
                        $errors[] = "NOTIFICATION_POLICY_OVERRIDES must be a valid JSON object";
                    } else {
                        $input['NOTIFICATION_POLICY_OVERRIDES'] = $decodedOverrides;
                    }
                }
            } elseif (is_array($rawOverrides)) {
                $input['NOTIFICATION_POLICY_OVERRIDES'] = $rawOverrides;
            } else {
                $errors[] = "NOTIFICATION_POLICY_OVERRIDES must be JSON object or array";
            }
        }

        // Logical validation: AUTO >= REVIEW
        if (isset($input['MATCH_AUTO_THRESHOLD']) && isset($input['MATCH_REVIEW_THRESHOLD'])) {
            $reviewComparable = $input['MATCH_REVIEW_THRESHOLD'];
            if ($reviewComparable >= 0.0 && $reviewComparable <= 1.0) {
                $reviewComparable *= 100;
            }
            if ($input['MATCH_AUTO_THRESHOLD'] < $reviewComparable) {
                $errors[] = "MATCH_AUTO_THRESHOLD must be >= MATCH_REVIEW_THRESHOLD";
            }
        }
        
        if (!empty($errors)) {
            wbgl_api_compat_fail(400, 'Validation failed', [
                'errors' => $errors,
            ]);
        }
        
        // Save settings
        $settings = new Settings();
        $beforeSettings = $settings->all();
        $saved = $settings->save($input);

        try {
            SettingsAuditService::recordChangeSet(
                $beforeSettings,
                $saved,
                $input,
                wbgl_api_current_user_display(),
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            );
        } catch (Throwable $auditError) {
            // Non-blocking audit trail
        }
        
        wbgl_api_compat_success([
            'settings' => wbgl_settings_redact_sensitive($saved),
        ]);
        
    } catch (Exception $e) {
        wbgl_api_compat_fail(500, $e->getMessage(), [], 'internal');
    }
}

// Handle GET request to load settings
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $settings = new Settings();
        wbgl_api_compat_success([
            'settings' => wbgl_settings_redact_sensitive($settings->all()),
        ]);
    } catch (Exception $e) {
        wbgl_api_compat_fail(500, $e->getMessage(), [], 'internal');
    }
}

// Method not allowed
wbgl_api_compat_fail(405, 'Method not allowed');
