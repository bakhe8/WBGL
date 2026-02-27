<?php
/**
 * =============================================================================
 * Settings - Application Configuration Manager
 * =============================================================================
 * 
 * 📚 DOCUMENTATION: docs/matching-system-guide.md
 * 
 * This class manages application settings stored in storage/settings.json.
 * Default values are defined below and can be overridden via the Settings UI.
 * 
 * MATCHING THRESHOLDS EXPLAINED:
 * ------------------------------
 * - MATCH_AUTO_THRESHOLD (95): Scores >= 95 are auto-accepted
 * - MATCH_REVIEW_THRESHOLD (0.70): Scores < 70% are HIDDEN from suggestions
 * - MATCH_WEAK_THRESHOLD (0.70): Same as Review (kept for backward compat)
 * 
 * ⚠️ WARNING: Lowering MATCH_REVIEW_THRESHOLD will show irrelevant suggestions.
 *             This is NOT recommended as a solution for "no candidates" issues.
 *             See docs/matching-system-guide.md for proper solutions.
 * =============================================================================
 */
declare(strict_types=1);

namespace App\Support;

class Settings
{
    private string $path;
    private string $localPath;

    /**
     * Default settings with documentation
     * 
     * @var array<string, mixed>
     */
    private array $defaults = [
        // Matching Thresholds
        'MATCH_AUTO_THRESHOLD' => Config::MATCH_AUTO_THRESHOLD,      // 95 - Auto-accept without review
        'MATCH_REVIEW_THRESHOLD' => Config::MATCH_REVIEW_THRESHOLD,  // 0.70 - Minimum to show in list
        'MATCH_WEAK_THRESHOLD' => 0.70,                              // Synced with Review Threshold
        'BANK_FUZZY_THRESHOLD' => 0.95,                              // Bank fuzzy match threshold
        'LEARNING_SCORE_CAP' => 0.90,                                // Max score for learning-based matches

        // Conflict Detection
        'CONFLICT_DELTA' => Config::CONFLICT_DELTA,                  // 0.1 - Score difference for conflicts

        // Base Scores (used by ConfidenceCalculatorV2)
        // These are the ACTUAL scores used in the matching system
        'BASE_SCORE_OVERRIDE_EXACT' => 100,           // Explicit override exact match
        'BASE_SCORE_ALIAS_EXACT' => 100,              // Exact alias match
        'BASE_SCORE_ENTITY_ANCHOR_UNIQUE' => 90,      // Unique entity anchor
        'BASE_SCORE_ENTITY_ANCHOR_GENERIC' => 75,     // Generic entity anchor
        'BASE_SCORE_FUZZY_OFFICIAL_STRONG' => 85,     // Strong fuzzy match (>= 0.95)
        'BASE_SCORE_FUZZY_OFFICIAL_MEDIUM' => 70,     // Medium fuzzy match (0.85-0.94)
        'BASE_SCORE_FUZZY_OFFICIAL_WEAK' => 55,       // Weak fuzzy match (0.75-0.84)
        'BASE_SCORE_HISTORICAL_FREQUENT' => 60,       // Frequently used historical pattern
        'BASE_SCORE_HISTORICAL_OCCASIONAL' => 45,     // Occasionally used historical pattern

        // Learning & Penalty Settings
        'REJECTION_PENALTY_PERCENTAGE' => 25,         // Penalty per rejection (25% = 0.75 multiplier)
        'CONFIRMATION_BOOST_TIER1' => 5,              // Boost for 1-2 confirmations
        'CONFIRMATION_BOOST_TIER2' => 10,             // Boost for 3-5 confirmations
        'CONFIRMATION_BOOST_TIER3' => 15,             // Boost for 6+ confirmations

        // UI Level Thresholds (0-100 scale)
        'LEVEL_B_THRESHOLD' => 85,                     // Minimum confidence for Level B (High)
        'LEVEL_C_THRESHOLD' => 65,                     // Minimum confidence for Level C (Medium)
        // Level D is anything below LEVEL_C_THRESHOLD down to minimum display threshold

        // System Settings
        'TIMEZONE' => 'Asia/Riyadh',                  // System timezone (configurable from UI)
        'PRODUCTION_MODE' => false,                   // Enable production mode (disables debug logging)
        'HISTORY_ANCHOR_INTERVAL' => 10,              // Periodic anchor cadence for hybrid ledger
        'HISTORY_TEMPLATE_VERSION' => 'v1',           // Template version stamped in letter_context
        'BREAK_GLASS_ENABLED' => true,                // Emergency override stays available for governed exceptions
        'BREAK_GLASS_REQUIRE_TICKET' => true,         // Require incident/ticket reference for emergency override
        'BREAK_GLASS_DEFAULT_TTL_MINUTES' => 60,      // Default emergency override validity window
        'BREAK_GLASS_MAX_TTL_MINUTES' => 240,         // Maximum allowed emergency override window
        'SECURITY_HEADERS_ENABLED' => true,           // Apply centralized HTTP security headers
        'CSRF_ENFORCE_MUTATING' => true,              // Enforce CSRF token for POST/PUT/PATCH/DELETE
        'SESSION_IDLE_TIMEOUT_SECONDS' => 1800,       // Inactive user session timeout (30 minutes)
        'SESSION_ABSOLUTE_TIMEOUT_SECONDS' => 43200,  // Absolute session timeout (12 hours)
        'LOG_FORMAT' => 'json',                       // Application log format: json|text
        'DEFAULT_LOCALE' => 'ar',                     // Organization-level UI locale default
        'DEFAULT_THEME' => 'system',                  // Organization-level UI theme default
        'DEFAULT_DIRECTION' => 'auto',                // Organization-level direction override (auto/rtl/ltr)

        // Observability Alert Thresholds
        'OBS_ALERT_API_DENIED_SPIKE_24H' => 25,       // Trigger when denied API calls within 24h exceed this threshold
        'OBS_ALERT_OPEN_DEAD_LETTERS' => 5,           // Trigger when open dead letters exceed this threshold
        'OBS_ALERT_SCHEDULER_FAILURES_24H' => 3,      // Trigger when scheduler failures in 24h exceed this threshold
        'OBS_ALERT_PENDING_UNDO_REQUESTS' => 10,      // Trigger when pending undo requests exceed this threshold
        'OBS_ALERT_SCHEDULER_STALE_HOURS' => 24,      // Trigger when no scheduler run happened for this many hours

        // Enterprise DB Runtime Defaults (PostgreSQL-only)
        'DB_DRIVER' => 'pgsql',                       // pgsql
        'DB_DATABASE' => '',
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => 5432,
        'DB_NAME' => 'wbgl',
        'DB_USER' => '',
        'DB_PASS' => '',
        'DB_SSLMODE' => 'prefer',

        // Limits
        'CANDIDATES_LIMIT' => 20,        // Max suggestions shown
    ];

    public function __construct(string $path = '', string $localPath = '')
    {
        $this->path = $path ?: (__DIR__ . '/../../storage/settings.json');
        $this->localPath = $localPath ?: (__DIR__ . '/../../storage/settings.local.json');
    }

    public function all(): array
    {
        $primary = $this->loadPrimary();
        $local = $this->loadFileData($this->localPath);
        $merged = array_merge($primary, $local);
        $merged['MATCH_AUTO_THRESHOLD'] = $this->normalizePercentage($merged['MATCH_AUTO_THRESHOLD'] ?? null);
        return $merged;
    }

    public function save(array $data): array
    {
        // Save only to primary settings.json; local secret overrides stay in settings.local.json.
        $current = $this->loadPrimary();
        $merged = array_merge($current, $data);
        file_put_contents($this->path, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $merged;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();
        return $all[$key] ?? $default;
    }

    /**
     * Get singleton instance for global access
     * 
     * @return Settings
     */
    public static function getInstance(): Settings
    {
        static $instance = null;
        if ($instance === null) {
            $instance = new Settings();
        }
        return $instance;
    }

    /**
     * Check if production mode is enabled
     * 
     * @return bool
     */
    public function isProductionMode(): bool
    {
        return (bool) $this->get('PRODUCTION_MODE', false);
    }

    /**
     * Normalize percentage values to 0-100 scale.
     */
    private function normalizePercentage(mixed $value): mixed
    {
        if (is_numeric($value)) {
            $numeric = (float) $value;
            if ($numeric >= 0 && $numeric <= 1) {
                return $numeric * 100;
            }
        }
        return $value;
    }

    /**
     * @return array<string,mixed>
     */
    private function loadPrimary(): array
    {
        $data = $this->loadFileData($this->path);
        $merged = array_merge($this->defaults, $data);
        $merged['MATCH_AUTO_THRESHOLD'] = $this->normalizePercentage($merged['MATCH_AUTO_THRESHOLD'] ?? null);
        return $merged;
    }

    /**
     * @return array<string,mixed>
     */
    private function loadFileData(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
