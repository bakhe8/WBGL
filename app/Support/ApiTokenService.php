<?php
declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use App\Repositories\UserRepository;
use PDO;
use RuntimeException;

class ApiTokenService
{
    /**
     * @return array{token:string,token_type:string,expires_at:?string}
     */
    public static function issueToken(
        int $userId,
        string $tokenName = 'api-client',
        ?int $ttlHours = 24 * 30,
        array $abilities = ['*']
    ): array {
        if ($userId <= 0) {
            throw new RuntimeException('Invalid user id');
        }

        $plainToken = 'wbgl_pat_' . bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $plainToken);
        $tokenPrefix = substr($plainToken, 0, 16);
        $expiresAt = null;
        if ($ttlHours !== null && $ttlHours > 0) {
            $expiresAt = date('Y-m-d H:i:s', time() + ($ttlHours * 3600));
        }

        $db = Database::connect();
        $stmt = $db->prepare(
            'INSERT INTO api_access_tokens
                (user_id, token_name, token_hash, token_prefix, abilities_json, expires_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            $userId,
            trim($tokenName) !== '' ? $tokenName : 'api-client',
            $tokenHash,
            $tokenPrefix,
            json_encode($abilities, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $expiresAt,
        ]);

        return [
            'token' => $plainToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt,
        ];
    }

    public static function authenticateRequest(): ?User
    {
        $token = self::extractBearerToken();
        if ($token === null) {
            return null;
        }
        $user = self::resolveUserByToken($token);
        if ($user !== null) {
            AuthService::forceAuthenticatedUser($user);
        }
        return $user;
    }

    public static function revokeCurrentToken(): bool
    {
        $token = self::extractBearerToken();
        if ($token === null) {
            return false;
        }
        return self::revokeToken($token);
    }

    public static function revokeToken(string $plainToken): bool
    {
        $plainToken = trim($plainToken);
        if ($plainToken === '') {
            return false;
        }

        $db = Database::connect();
        $stmt = $db->prepare(
            'UPDATE api_access_tokens
             SET revoked_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE token_hash = ? AND revoked_at IS NULL'
        );
        $stmt->execute([hash('sha256', $plainToken)]);
        return $stmt->rowCount() > 0;
    }

    public static function extractBearerToken(): ?string
    {
        $header = self::readAuthorizationHeader();
        if ($header === '') {
            return null;
        }
        if (!preg_match('/^\s*Bearer\s+(.+)\s*$/i', $header, $m)) {
            return null;
        }
        $token = trim((string)($m[1] ?? ''));
        return $token !== '' ? $token : null;
    }

    public static function hasBearerToken(): bool
    {
        return self::extractBearerToken() !== null;
    }

    public static function resolveUserByToken(string $plainToken): ?User
    {
        $plainToken = trim($plainToken);
        if ($plainToken === '') {
            return null;
        }

        $db = Database::connect();
        $stmt = $db->prepare(
            'SELECT user_id
             FROM api_access_tokens
             WHERE token_hash = ?
               AND revoked_at IS NULL
               AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)
             LIMIT 1'
        );
        $stmt->execute([hash('sha256', $plainToken)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['user_id'])) {
            return null;
        }

        $userId = (int)$row['user_id'];
        $repo = new UserRepository($db);
        $user = $repo->find($userId);
        if ($user === null) {
            return null;
        }

        $touch = $db->prepare(
            'UPDATE api_access_tokens
             SET last_used_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE token_hash = ?'
        );
        $touch->execute([hash('sha256', $plainToken)]);

        return $user;
    }

    private static function readAuthorizationHeader(): string
    {
        $candidates = [
            $_SERVER['HTTP_AUTHORIZATION'] ?? null,
            $_SERVER['Authorization'] ?? null,
            $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null,
        ];
        foreach ($candidates as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            foreach ($headers as $name => $value) {
                if (is_string($name) && strtolower($name) === 'authorization' && is_string($value)) {
                    return trim($value);
                }
            }
        }

        return '';
    }
}

