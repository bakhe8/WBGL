<?php

declare(strict_types=1);

use App\Services\NotificationService;
use App\Support\Database;
use PHPUnit\Framework\TestCase;

final class NotificationServiceTest extends TestCase
{
    private array $createdIds = [];

    protected function tearDown(): void
    {
        if (empty($this->createdIds)) {
            return;
        }
        $db = Database::connect();
        $placeholders = implode(',', array_fill(0, count($this->createdIds), '?'));
        $stmt = $db->prepare("DELETE FROM notifications WHERE id IN ({$placeholders})");
        $stmt->execute($this->createdIds);
        $this->createdIds = [];
    }

    public function testCreateWithDedupeReturnsSameIdOnRepeat(): void
    {
        $dedupeKey = 'ut-notif-' . uniqid('', true);

        $firstId = NotificationService::create(
            'unit_test',
            'Unit Notification',
            'This is a test notification',
            null,
            ['k' => 'v'],
            $dedupeKey
        );
        $secondId = NotificationService::create(
            'unit_test',
            'Unit Notification',
            'This is a test notification',
            null,
            ['k' => 'v'],
            $dedupeKey
        );

        $this->createdIds[] = $firstId;
        $this->assertSame($firstId, $secondId);
    }

    public function testMarkReadForCurrentUserDoesNotThrow(): void
    {
        $id = NotificationService::create(
            'unit_test',
            'Mark Read Test',
            'Test read update',
            null,
            [],
            'ut-mark-read-' . uniqid('', true)
        );
        $this->createdIds[] = $id;

        NotificationService::markReadForCurrentUser($id);
        $db = Database::connect();
        $stmt = $db->prepare('SELECT is_read FROM notifications WHERE id = ?');
        $stmt->execute([$id]);
        $this->assertSame('1', (string)$stmt->fetchColumn());
    }
}
