<?php

declare(strict_types=1);

use App\Services\PrintAuditService;
use App\Support\Database;
use PHPUnit\Framework\TestCase;

final class PrintAuditServiceTest extends TestCase
{
    private array $createdGuaranteeIds = [];

    protected function setUp(): void
    {
        if (!$this->hasTable('print_events')) {
            $this->markTestSkipped('print_events table is not available');
        }
    }

    protected function tearDown(): void
    {
        $db = Database::connect();

        foreach ($this->createdGuaranteeIds as $id) {
            $db->prepare('DELETE FROM print_events WHERE guarantee_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM guarantee_decisions WHERE guarantee_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM guarantee_history WHERE guarantee_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM guarantees WHERE id = ?')->execute([$id]);
        }

        $this->createdGuaranteeIds = [];
    }

    public function testRecordAndListByGuarantee(): void
    {
        $guaranteeId = $this->createGuarantee();

        $result = PrintAuditService::record(
            'print_requested',
            'single_letter',
            [$guaranteeId],
            'phpunit',
            null,
            ['trigger' => 'unit_test']
        );

        $this->assertSame(1, (int)$result['inserted']);
        $this->assertSame([$guaranteeId], $result['guarantee_ids']);

        $rows = PrintAuditService::listByGuarantee($guaranteeId, 20);
        $this->assertNotEmpty($rows);
        $this->assertSame('print_requested', (string)$rows[0]['event_type']);
        $this->assertSame('single_letter', (string)$rows[0]['context']);
        $this->assertSame('unit_test', (string)($rows[0]['payload']['trigger'] ?? ''));
    }

    public function testUnsupportedEventTypeThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported print event_type');

        $guaranteeId = $this->createGuarantee();
        PrintAuditService::record(
            'unknown_event',
            'single_letter',
            [$guaranteeId],
            'phpunit'
        );
    }

    private function createGuarantee(): int
    {
        $db = Database::connect();
        $number = 'UT-PRINT-' . uniqid('', true);
        $raw = json_encode([
            'supplier' => 'Print Test Supplier',
            'bank' => 'Print Test Bank',
            'amount' => '1000',
            'contract_number' => 'C-PRINT-UT',
            'expiry_date' => date('Y-m-d', strtotime('+30 days')),
        ], JSON_UNESCAPED_UNICODE);

        $stmt = $db->prepare(
            'INSERT INTO guarantees (guarantee_number, raw_data, import_source, imported_at, imported_by, normalized_supplier_name, is_test_data)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $number,
            $raw,
            'unit_test_print',
            date('Y-m-d H:i:s'),
            'phpunit',
            'print test supplier',
            1,
        ]);

        $id = (int)$db->lastInsertId();
        $this->createdGuaranteeIds[] = $id;
        return $id;
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
