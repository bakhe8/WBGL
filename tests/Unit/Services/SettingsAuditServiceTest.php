<?php

declare(strict_types=1);

use App\Services\SettingsAuditService;
use App\Support\Database;
use PHPUnit\Framework\TestCase;

final class SettingsAuditServiceTest extends TestCase
{
    private array $tokens = [];

    protected function setUp(): void
    {
        if (!$this->hasTable('settings_audit_logs')) {
            $this->markTestSkipped('settings_audit_logs table is not available');
        }
    }

    protected function tearDown(): void
    {
        if (empty($this->tokens)) {
            return;
        }
        $db = Database::connect();
        foreach ($this->tokens as $token) {
            $db->prepare('DELETE FROM settings_audit_logs WHERE change_set_token = ?')->execute([$token]);
        }
        $this->tokens = [];
    }

    public function testRecordChangeSetStoresOnlyChangedKeys(): void
    {
        $before = [
            'MATCH_AUTO_THRESHOLD' => 95,
            'PRODUCTION_MODE' => false,
            'CANDIDATES_LIMIT' => 20,
        ];
        $after = [
            'MATCH_AUTO_THRESHOLD' => 90,
            'PRODUCTION_MODE' => false,
            'CANDIDATES_LIMIT' => 25,
        ];
        $submitted = [
            'MATCH_AUTO_THRESHOLD' => 90,
            'PRODUCTION_MODE' => false,
            'CANDIDATES_LIMIT' => 25,
        ];

        $inserted = SettingsAuditService::recordChangeSet(
            $before,
            $after,
            $submitted,
            'phpunit',
            '127.0.0.1',
            'phpunit-agent'
        );

        $this->assertSame(2, $inserted);

        $rows = SettingsAuditService::listRecent(10);
        $matching = array_values(array_filter($rows, static fn(array $row): bool => ($row['changed_by'] ?? '') === 'phpunit'));
        $this->assertNotEmpty($matching);
        $token = (string)$matching[0]['change_set_token'];
        $this->tokens[] = $token;

        $keys = array_map(static fn(array $row): string => (string)$row['setting_key'], array_filter(
            $matching,
            static fn(array $row): bool => (string)$row['change_set_token'] === $token
        ));
        sort($keys);
        $this->assertSame(['CANDIDATES_LIMIT', 'MATCH_AUTO_THRESHOLD'], $keys);
    }

    public function testRecordChangeSetReturnsZeroForNoDiff(): void
    {
        $same = [
            'MATCH_AUTO_THRESHOLD' => 95,
            'PRODUCTION_MODE' => false,
        ];
        $inserted = SettingsAuditService::recordChangeSet(
            $same,
            $same,
            $same,
            'phpunit'
        );

        $this->assertSame(0, $inserted);
    }

    private function hasTable(string $table): bool
    {
        $db = Database::connect();
        $stmt = $db->prepare(
            "SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = ? LIMIT 1"
        );
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    }
}
