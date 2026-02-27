<?php

declare(strict_types=1);

use App\Services\UndoRequestService;
use App\Support\Database;
use PHPUnit\Framework\TestCase;

final class UndoRequestServiceTest extends TestCase
{
    private array $createdGuaranteeIds = [];

    protected function tearDown(): void
    {
        $db = Database::connect();
        $db->exec("DELETE FROM notifications WHERE type LIKE 'undo_request_%'");
        foreach ($this->createdGuaranteeIds as $id) {
            $db->prepare('DELETE FROM undo_requests WHERE guarantee_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM guarantee_history WHERE guarantee_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM guarantee_decisions WHERE guarantee_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM guarantees WHERE id = ?')->execute([$id]);
        }
        $this->createdGuaranteeIds = [];
    }

    public function testSubmitAndListPendingUndoRequests(): void
    {
        $gid = $this->createGuaranteeWithDecision('ready');
        $requestId = UndoRequestService::submit($gid, 'Need correction', 'request_user');

        $this->assertGreaterThan(0, $requestId);

        $rows = UndoRequestService::list('pending', 50);
        $ids = array_map(static fn(array $r): int => (int)$r['id'], $rows);
        $this->assertContains($requestId, $ids);
    }

    public function testSelfApprovalIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Self-approval is not allowed');

        $gid = $this->createGuaranteeWithDecision('ready');
        $requestId = UndoRequestService::submit($gid, 'Need correction', 'same_actor');
        UndoRequestService::approve($requestId, 'same_actor', 'self approve');
    }

    public function testCannotSubmitSecondPendingRequestForSameGuarantee(): void
    {
        $gid = $this->createGuaranteeWithDecision('ready');
        UndoRequestService::submit($gid, 'Need correction', 'requester');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already a pending undo request');
        UndoRequestService::submit($gid, 'Second request', 'another_user');
    }

    public function testRequesterCannotExecuteOwnApprovedUndoRequest(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Self-approval is not allowed');

        $gid = $this->createGuaranteeWithDecision('ready');
        $requestId = UndoRequestService::submit($gid, 'Need correction', 'same_actor');
        UndoRequestService::approve($requestId, 'approver', 'approved');
        UndoRequestService::execute($requestId, 'same_actor');
    }

    public function testApprovedUndoRequestCanExecuteAndReopenGuarantee(): void
    {
        $gid = $this->createGuaranteeWithDecision('ready');
        $requestId = UndoRequestService::submit($gid, 'Need correction', 'requester');
        UndoRequestService::approve($requestId, 'approver', 'approved');
        UndoRequestService::execute($requestId, 'executor');

        $db = Database::connect();
        $status = $db->prepare('SELECT status FROM undo_requests WHERE id = ?');
        $status->execute([$requestId]);
        $this->assertSame('executed', (string)$status->fetchColumn());

        $decision = $db->prepare('SELECT status FROM guarantee_decisions WHERE guarantee_id = ?');
        $decision->execute([$gid]);
        $this->assertSame('pending', (string)$decision->fetchColumn());
    }

    private function createGuaranteeWithDecision(string $decisionStatus): int
    {
        $db = Database::connect();
        $number = 'UT-UNDO-' . uniqid('', true);
        $raw = json_encode([
            'supplier' => 'Test Supplier',
            'bank' => 'Test Bank',
            'amount' => '1000',
            'contract_number' => 'C-UT',
            'expiry_date' => date('Y-m-d', strtotime('+30 days')),
        ], JSON_UNESCAPED_UNICODE);

        $stmt = $db->prepare(
            'INSERT INTO guarantees (guarantee_number, raw_data, import_source, imported_at, imported_by, normalized_supplier_name, is_test_data)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $number,
            $raw,
            'unit_test',
            date('Y-m-d H:i:s'),
            'phpunit',
            'test supplier',
            1,
        ]);
        $gid = (int)$db->lastInsertId();

        $dec = $db->prepare(
            'INSERT INTO guarantee_decisions (guarantee_id, status, created_at, updated_at, workflow_step, signatures_received)
             VALUES (?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?, ?)'
        );
        $dec->execute([$gid, $decisionStatus, 'draft', 0]);

        $this->createdGuaranteeIds[] = $gid;
        return $gid;
    }
}
