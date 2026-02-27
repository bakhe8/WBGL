<?php
declare(strict_types=1);

namespace App\Support;

use PDO;

/**
 * LoginRateLimiter
 *
 * Lightweight DB-backed throttle for login attempts.
 * Default policy mirrors 5 attempts / 1 minute.
 */
class LoginRateLimiter
{
    private const MAX_ATTEMPTS = 5;
    private const WINDOW_SECONDS = 60;
    private const LOCKOUT_SECONDS = 60;

    public static function check(string $username): array
    {
        $normalizedUser = self::normalizeUsername($username);
        $key = self::buildIdentifier($normalizedUser);
        $record = self::find($key);

        if ($record === null) {
            return [
                'allowed' => true,
                'remaining' => self::MAX_ATTEMPTS,
                'retry_after' => 0,
            ];
        }

        $now = time();
        $lockedUntil = self::toTimestamp($record['locked_until'] ?? null);
        if ($lockedUntil > $now) {
            return [
                'allowed' => false,
                'remaining' => 0,
                'retry_after' => $lockedUntil - $now,
            ];
        }

        $windowStarted = self::toTimestamp($record['window_started_at'] ?? null);
        if ($windowStarted <= 0 || ($now - $windowStarted) >= self::WINDOW_SECONDS) {
            self::resetWindow($key, $now);
            return [
                'allowed' => true,
                'remaining' => self::MAX_ATTEMPTS,
                'retry_after' => 0,
            ];
        }

        $attempts = (int)($record['attempt_count'] ?? 0);
        return [
            'allowed' => true,
            'remaining' => max(0, self::MAX_ATTEMPTS - $attempts),
            'retry_after' => 0,
        ];
    }

    public static function recordFailure(string $username): array
    {
        $normalizedUser = self::normalizeUsername($username);
        $key = self::buildIdentifier($normalizedUser);
        $db = Database::connect();
        $record = self::find($key);
        $now = time();
        $nowStr = date('Y-m-d H:i:s', $now);

        if ($record === null) {
            $stmt = $db->prepare(
                'INSERT INTO login_rate_limits (identifier, username, ip_address, attempt_count, window_started_at, last_attempt_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $key,
                $normalizedUser,
                self::clientIp(),
                1,
                $nowStr,
                $nowStr,
                $nowStr,
            ]);

            return [
                'locked' => false,
                'remaining' => self::MAX_ATTEMPTS - 1,
                'retry_after' => 0,
            ];
        }

        $windowStarted = self::toTimestamp($record['window_started_at'] ?? null);
        $attempts = (int)($record['attempt_count'] ?? 0);
        if ($windowStarted <= 0 || ($now - $windowStarted) >= self::WINDOW_SECONDS) {
            $attempts = 0;
            $windowStarted = $now;
        }

        $attempts++;
        $lockedUntil = null;
        $retryAfter = 0;
        $locked = false;

        if ($attempts >= self::MAX_ATTEMPTS) {
            $locked = true;
            $retryAfter = self::LOCKOUT_SECONDS;
            $lockedUntil = date('Y-m-d H:i:s', $now + self::LOCKOUT_SECONDS);
        }

        $stmt = $db->prepare(
            'UPDATE login_rate_limits
             SET attempt_count = ?, window_started_at = ?, locked_until = ?, last_attempt_at = ?, updated_at = ?
             WHERE identifier = ?'
        );
        $stmt->execute([
            $attempts,
            date('Y-m-d H:i:s', $windowStarted),
            $lockedUntil,
            $nowStr,
            $nowStr,
            $key,
        ]);

        return [
            'locked' => $locked,
            'remaining' => max(0, self::MAX_ATTEMPTS - $attempts),
            'retry_after' => $retryAfter,
        ];
    }

    public static function clear(string $username): void
    {
        $key = self::buildIdentifier(self::normalizeUsername($username));
        $db = Database::connect();
        $stmt = $db->prepare('DELETE FROM login_rate_limits WHERE identifier = ?');
        $stmt->execute([$key]);
    }

    private static function find(string $identifier): ?array
    {
        $db = Database::connect();
        $stmt = $db->prepare('SELECT * FROM login_rate_limits WHERE identifier = ? LIMIT 1');
        $stmt->execute([$identifier]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private static function resetWindow(string $identifier, int $now): void
    {
        $db = Database::connect();
        $nowStr = date('Y-m-d H:i:s', $now);
        $stmt = $db->prepare(
            'UPDATE login_rate_limits
             SET attempt_count = 0, window_started_at = ?, locked_until = NULL, updated_at = ?
             WHERE identifier = ?'
        );
        $stmt->execute([$nowStr, $nowStr, $identifier]);
    }

    private static function buildIdentifier(string $normalizedUser): string
    {
        return hash('sha256', $normalizedUser . '|' . self::clientIp() . '|' . self::clientUserAgent());
    }

    private static function normalizeUsername(string $username): string
    {
        return strtolower(trim($username));
    }

    private static function toTimestamp(?string $value): int
    {
        if ($value === null || trim($value) === '') {
            return 0;
        }
        $ts = strtotime($value);
        return $ts !== false ? $ts : 0;
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

    private static function clientUserAgent(): string
    {
        $userAgent = trim(strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? 'unknown-agent')));
        if ($userAgent === '') {
            return 'unknown-agent';
        }

        if (strlen($userAgent) > 255) {
            $userAgent = substr($userAgent, 0, 255);
        }

        return $userAgent;
    }
}
