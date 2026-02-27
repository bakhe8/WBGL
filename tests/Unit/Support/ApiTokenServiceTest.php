<?php

declare(strict_types=1);

use App\Models\User;
use App\Repositories\UserRepository;
use App\Support\ApiTokenService;
use App\Support\Database;
use PHPUnit\Framework\TestCase;

final class ApiTokenServiceTest extends TestCase
{
    private array $createdUserIds = [];
    private array $createdTokenHashes = [];

    protected function tearDown(): void
    {
        $db = Database::connect();

        if (!empty($this->createdTokenHashes) && $this->hasTable($db, 'api_access_tokens')) {
            $placeholders = implode(',', array_fill(0, count($this->createdTokenHashes), '?'));
            $stmt = $db->prepare("DELETE FROM api_access_tokens WHERE token_hash IN ({$placeholders})");
            $stmt->execute($this->createdTokenHashes);
        }

        if (!empty($this->createdUserIds)) {
            $placeholders = implode(',', array_fill(0, count($this->createdUserIds), '?'));
            $stmt = $db->prepare("DELETE FROM users WHERE id IN ({$placeholders})");
            $stmt->execute($this->createdUserIds);
        }

        $this->createdUserIds = [];
        $this->createdTokenHashes = [];
    }

    public function testIssueResolveAndRevokeTokenLifecycle(): void
    {
        $db = Database::connect();
        if (!$this->hasTable($db, 'api_access_tokens')) {
            $this->markTestSkipped('api_access_tokens table is not available');
        }

        $repo = new UserRepository($db);
        $user = $repo->create(new User(
            id: 0,
            username: 'ut_token_' . uniqid('', true),
            passwordHash: password_hash('secret', PASSWORD_BCRYPT),
            fullName: 'Token Test User',
            email: null,
            roleId: null,
            preferredLanguage: 'en',
            lastLogin: null,
            createdAt: date('Y-m-d H:i:s')
        ));
        $this->createdUserIds[] = (int)$user->id;

        $issued = ApiTokenService::issueToken((int)$user->id, 'unit-test-token', 1, ['*']);
        $token = (string)($issued['token'] ?? '');
        $this->assertNotSame('', $token);
        $this->assertSame('Bearer', (string)($issued['token_type'] ?? ''));
        $this->createdTokenHashes[] = hash('sha256', $token);

        $resolved = ApiTokenService::resolveUserByToken($token);
        $this->assertNotNull($resolved);
        $this->assertSame((int)$user->id, (int)$resolved->id);
        $this->assertSame('en', $resolved->preferredLanguage);

        $revoked = ApiTokenService::revokeToken($token);
        $this->assertTrue($revoked);

        $resolvedAfterRevoke = ApiTokenService::resolveUserByToken($token);
        $this->assertNull($resolvedAfterRevoke);
    }

    private function hasTable(\PDO $db, string $table): bool
    {
        $stmt = $db->prepare(
            "SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = ? LIMIT 1"
        );
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    }
}
