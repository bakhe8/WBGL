<?php
declare(strict_types=1);

namespace App\Support;

use PDO;

/**
 * API token authentication throttle.
 *
 * Scope key: token fingerprint + client IP + user-agent hash.
 */
class ApiTokenRateLimiter
{
    private const DEFAULT_MAX_ATTEMPTS = 12;
    private const DEFAULT_WINDOW_SECONDS = 60;
    private const DEFAULT_LOCKOUT_SECONDS = 120;

    /**
     * @return array{
     *   allowed:bool,
     *   remaining:int,
     *   retry_after:int,
     *   limit:int,
     *   window_seconds:int,
     *   allowlisted:bool,
     *   token_fingerprint:?string
     * }
     */
    public static function check(string $plainToken): array
    {
        $maxAttempts = self::maxAttempts();
        $windowSeconds = self::windowSeconds();
        $fingerprint = self::tokenFingerprint($plainToken);
        if ($fingerprint === '') {
            return [
                'allowed' => true,
                'remaining' => $maxAttempts,
                'retry_after' => 0,
                'limit' => $maxAttempts,
                'window_seconds' => $windowSeconds,
                'allowlisted' => false,
                'token_fingerprint' => null,
            ];
        }

        if (self::isAllowlisted($plainToken, $fingerprint)) {
            return [
                'allowed' => true,
                'remaining' => $maxAttempts,
                'retry_after' => 0,
                'limit' => $maxAttempts,
                'window_seconds' => $windowSeconds,
                'allowlisted' => true,
                'token_fingerprint' => $fingerprint,
            ];
        }

        $identifier = self::buildIdentifier($fingerprint);
        $record = self::find($identifier);
        if ($record === null) {
            return [
                'allowed' => true,
                'remaining' => $maxAttempts,
                'retry_after' => 0,
                'limit' => $maxAttempts,
                'window_seconds' => $windowSeconds,
                'allowlisted' => false,
                'token_fingerprint' => $fingerprint,
            ];
        }

        $now = time();
        $lockedUntil = self::toTimestamp($record['locked_until'] ?? null);
        if ($lockedUntil > $now) {
            return [
                'allowed' => false,
                'remaining' => 0,
                'retry_after' => max(0, $lockedUntil - $now),
                'limit' => $maxAttempts,
                'window_seconds' => $windowSeconds,
                'allowlisted' => false,
                'token_fingerprint' => $fingerprint,
            ];
        }

        $windowStarted = self::toTimestamp($record['window_started_at'] ?? null);
        if ($windowStarted <= 0 || ($now - $windowStarted) >= $windowSeconds) {
            self::resetWindow($identifier, $now);
            return [
                'allowed' => true,
                'remaining' => $maxAttempts,
                'retry_after' => 0,
                'limit' => $maxAttempts,
                'window_seconds' => $windowSeconds,
                'allowlisted' => false,
                'token_fingerprint' => $fingerprint,
            ];
        }

        $attempts = (int)($record['attempt_count'] ?? 0);
        return [
            'allowed' => true,
            'remaining' => max(0, $maxAttempts - $attempts),
            'retry_after' => 0,
            'limit' => $maxAttempts,
            'window_seconds' => $windowSeconds,
            'allowlisted' => false,
            'token_fingerprint' => $fingerprint,
        ];
    }

    /**
     * @return array{
     *   locked:bool,
     *   remaining:int,
     *   retry_after:int,
     *   limit:int,
     *   window_seconds:int,
     *   allowlisted:bool,
     *   token_fingerprint:?string
     * }
     */
    public static function recordFailure(string $plainToken): array
    {
        $maxAttempts = self::maxAttempts();
        $windowSeconds = self::windowSeconds();
        $fingerprint = self::tokenFingerprint($plainToken);
        if ($fingerprint === '') {
            return [
                'locked' => false,
                'remaining' => $maxAttempts,
                'retry_after' => 0,
                'limit' => $maxAttempts,
                'window_seconds' => $windowSeconds,
                'allowlisted' => false,
                'token_fingerprint' => null,
            ];
        }

        if (self::isAllowlisted($plainToken, $fingerprint)) {
            return [
                'locked' => false,
                'remaining' => $maxAttempts,
                'retry_after' => 0,
                'limit' => $maxAttempts,
                'window_seconds' => $windowSeconds,
                'allowlisted' => true,
                'token_fingerprint' => $fingerprint,
            ];
        }

        $identifier = self::buildIdentifier($fingerprint);
        $db = Database::connect();
        $record = self::find($identifier);
        $now = time();
        $nowStr = date('c', $now);

        if ($record === null) {
            $stmt = $db->prepare(
                'INSERT INTO api_token_rate_limits
                    (identifier, token_fingerprint, ip_address, user_agent_hash, attempt_count, window_started_at, last_attempt_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $identifier,
                $fingerprint,
                self::clientIp(),
                self::clientUserAgentHash(),
                1,
                $nowStr,
                $nowStr,
                $nowStr,
                $nowStr,
            ]);

            return [
                'locked' => false,
                'remaining' => max(0, $maxAttempts - 1),
                'retry_after' => 0,
                'limit' => $maxAttempts,
                'window_seconds' => $windowSeconds,
                'allowlisted' => false,
                'token_fingerprint' => $fingerprint,
            ];
        }

        $windowStarted = self::toTimestamp($record['window_started_at'] ?? null);
        $attempts = (int)($record['attempt_count'] ?? 0);
        if ($windowStarted <= 0 || ($now - $windowStarted) >= $windowSeconds) {
            $attempts = 0;
            $windowStarted = $now;
        }

        $attempts++;
        $locked = false;
        $retryAfter = 0;
        $lockedUntil = null;
        if ($attempts >= $maxAttempts) {
            $locked = true;
            $retryAfter = self::lockoutSeconds();
            $lockedUntil = date('c', $now + $retryAfter);
        }

        $stmt = $db->prepare(
            'UPDATE api_token_rate_limits
             SET attempt_count = ?, window_started_at = ?, locked_until = ?, last_attempt_at = ?, updated_at = ?
             WHERE identifier = ?'
        );
        $stmt->execute([
            $attempts,
            date('c', $windowStarted),
            $lockedUntil,
            $nowStr,
            $nowStr,
            $identifier,
        ]);

        return [
            'locked' => $locked,
            'remaining' => max(0, $maxAttempts - $attempts),
            'retry_after' => $retryAfter,
            'limit' => $maxAttempts,
            'window_seconds' => $windowSeconds,
            'allowlisted' => false,
            'token_fingerprint' => $fingerprint,
        ];
    }

    public static function clear(string $plainToken): void
    {
        $fingerprint = self::tokenFingerprint($plainToken);
        if ($fingerprint === '') {
            return;
        }

        $identifier = self::buildIdentifier($fingerprint);
        $db = Database::connect();
        $stmt = $db->prepare('DELETE FROM api_token_rate_limits WHERE identifier = ?');
        $stmt->execute([$identifier]);
    }

    private static function find(string $identifier): ?array
    {
        $db = Database::connect();
        $stmt = $db->prepare('SELECT * FROM api_token_rate_limits WHERE identifier = ? LIMIT 1');
        $stmt->execute([$identifier]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private static function resetWindow(string $identifier, int $now): void
    {
        $db = Database::connect();
        $nowStr = date('c', $now);
        $stmt = $db->prepare(
            'UPDATE api_token_rate_limits
             SET attempt_count = 0, window_started_at = ?, locked_until = NULL, updated_at = ?
             WHERE identifier = ?'
        );
        $stmt->execute([$nowStr, $nowStr, $identifier]);
    }

    private static function buildIdentifier(string $fingerprint): string
    {
        return hash('sha256', $fingerprint . '|' . self::clientIp() . '|' . self::clientUserAgentHash());
    }

    private static function tokenFingerprint(string $plainToken): string
    {
        $token = trim($plainToken);
        if ($token === '') {
            return '';
        }
        return substr(hash('sha256', $token), 0, 24);
    }

    private static function toTimestamp(mixed $value): int
    {
        if (!is_scalar($value)) {
            return 0;
        }
        $text = trim((string)$value);
        if ($text === '') {
            return 0;
        }
        $ts = strtotime($text);
        return $ts !== false ? $ts : 0;
    }

    private static function maxAttempts(): int
    {
        return self::intSetting('API_TOKEN_RATE_LIMIT_MAX_ATTEMPTS', self::DEFAULT_MAX_ATTEMPTS, 1, 200);
    }

    private static function windowSeconds(): int
    {
        return self::intSetting('API_TOKEN_RATE_LIMIT_WINDOW_SECONDS', self::DEFAULT_WINDOW_SECONDS, 10, 3600);
    }

    private static function lockoutSeconds(): int
    {
        return self::intSetting('API_TOKEN_RATE_LIMIT_LOCKOUT_SECONDS', self::DEFAULT_LOCKOUT_SECONDS, 10, 7200);
    }

    private static function intSetting(string $key, int $default, int $min, int $max): int
    {
        $envValue = getenv('WBGL_' . $key);
        if ($envValue !== false && trim((string)$envValue) !== '') {
            $intValue = (int)$envValue;
            if ($intValue < $min) {
                return $min;
            }
            if ($intValue > $max) {
                return $max;
            }
            return $intValue;
        }

        $value = Settings::getInstance()->get($key, $default);
        $intValue = is_numeric($value) ? (int)$value : $default;
        if ($intValue < $min) {
            return $min;
        }
        if ($intValue > $max) {
            return $max;
        }
        return $intValue;
    }

    private static function clientIp(): string
    {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $forwarded = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($forwarded !== '') {
            $parts = explode(',', $forwarded);
            if (!empty($parts[0])) {
                $ip = trim($parts[0]);
            }
        }
        return $ip !== '' ? $ip : '127.0.0.1';
    }

    private static function clientUserAgentHash(): string
    {
        $userAgent = trim(strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? 'unknown-agent')));
        if ($userAgent === '') {
            $userAgent = 'unknown-agent';
        }

        if (strlen($userAgent) > 512) {
            $userAgent = substr($userAgent, 0, 512);
        }

        return substr(hash('sha256', $userAgent), 0, 24);
    }

    private static function isAllowlisted(string $plainToken, string $fingerprint): bool
    {
        $ipRules = self::listSetting('API_TOKEN_RATE_LIMIT_ALLOWLIST_IPS');
        $tokenRules = self::listSetting('API_TOKEN_RATE_LIMIT_ALLOWLIST_TOKEN_PREFIXES');

        $clientIp = self::clientIp();
        foreach ($ipRules as $rule) {
            if (self::ipMatchesRule($clientIp, $rule)) {
                return true;
            }
        }

        if (!empty($tokenRules)) {
            $tokenPrefix = strtolower(substr(trim($plainToken), 0, 16));
            foreach ($tokenRules as $rule) {
                $normalized = strtolower(trim($rule));
                if ($normalized === '') {
                    continue;
                }
                if ($tokenPrefix !== '' && str_starts_with($tokenPrefix, $normalized)) {
                    return true;
                }
                if ($fingerprint !== '' && str_starts_with($fingerprint, $normalized)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<int,string>
     */
    private static function listSetting(string $key): array
    {
        $envValue = getenv('WBGL_' . $key);
        if ($envValue !== false && trim((string)$envValue) !== '') {
            return self::normalizeList($envValue);
        }

        $value = Settings::getInstance()->get($key, []);
        return self::normalizeList($value);
    }

    /**
     * @param mixed $value
     * @return array<int,string>
     */
    private static function normalizeList(mixed $value): array
    {
        if (is_string($value)) {
            $parts = preg_split('/[\s,;|]+/', $value) ?: [];
            $items = array_map('trim', $parts);
            $items = array_filter($items, static fn(string $item): bool => $item !== '');
            return array_values(array_unique($items));
        }

        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $text = trim((string)$item);
            if ($text !== '') {
                $items[] = $text;
            }
        }

        return array_values(array_unique($items));
    }

    private static function ipMatchesRule(string $ip, string $rule): bool
    {
        $ip = trim($ip);
        $rule = trim($rule);
        if ($ip === '' || $rule === '') {
            return false;
        }

        if (!str_contains($rule, '/')) {
            return strcasecmp($ip, $rule) === 0;
        }

        [$subnet, $prefixRaw] = array_pad(explode('/', $rule, 2), 2, '');
        $subnet = trim($subnet);
        $prefixRaw = trim($prefixRaw);
        if ($subnet === '' || $prefixRaw === '' || !ctype_digit($prefixRaw)) {
            return false;
        }

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false) {
            return false;
        }
        if (strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $prefix = (int)$prefixRaw;
        $maxBits = strlen($ipBin) * 8;
        if ($prefix < 0 || $prefix > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
        return ((ord($ipBin[$fullBytes]) & $mask) === (ord($subnetBin[$fullBytes]) & $mask));
    }
}
