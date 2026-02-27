<?php

declare(strict_types=1);

use App\Services\TimelineHybridLedger;
use App\Support\Database;
use PHPUnit\Framework\TestCase;

final class TimelineHybridLedgerTest extends TestCase
{
    public function testApplyPatchHandlesAddReplaceAndRemove(): void
    {
        $state = [
            'amount' => 1000,
            'status' => 'pending',
            'bank_name' => 'Bank A',
        ];

        $patch = [
            ['op' => 'replace', 'path' => '/amount', 'value' => 1500],
            ['op' => 'replace', 'path' => '/status', 'value' => 'ready'],
            ['op' => 'add', 'path' => '/supplier_name', 'value' => 'Supplier X'],
            ['op' => 'remove', 'path' => '/bank_name'],
        ];

        $result = TimelineHybridLedger::applyPatch($state, $patch);

        $this->assertSame(1500, $result['amount']);
        $this->assertSame('ready', $result['status']);
        $this->assertSame('Supplier X', $result['supplier_name']);
        $this->assertArrayNotHasKey('bank_name', $result);
    }

    public function testResolveEventSnapshotUsesAnchorAndPatchWhenSnapshotDataEmpty(): void
    {
        $db = Database::connect();
        $event = [
            'id' => 999999,
            'guarantee_id' => 999999,
            'snapshot_data' => '{}',
            'anchor_snapshot' => json_encode([
                'amount' => 1000,
                'status' => 'pending',
            ], JSON_UNESCAPED_UNICODE),
            'patch_data' => json_encode([
                ['op' => 'replace', 'path' => '/amount', 'value' => 2000],
            ], JSON_UNESCAPED_UNICODE),
        ];

        $snapshot = TimelineHybridLedger::resolveEventSnapshot($db, $event);

        $this->assertSame(2000, $snapshot['amount'] ?? null);
        $this->assertSame('pending', $snapshot['status'] ?? null);
    }

    public function testFetchLatestKnownStateReconstructsWhenLatestEventIsPatchOnly(): void
    {
        $db = Database::connect();

        if (!TimelineHybridLedger::supportsHybridColumns($db)) {
            $this->markTestSkipped('Hybrid columns are not available in guarantee_history');
        }

        $guaranteeId = 990001;
        $db->prepare('DELETE FROM guarantee_history WHERE guarantee_id = ?')->execute([$guaranteeId]);
        $db->prepare('DELETE FROM guarantees WHERE id = ?')->execute([$guaranteeId]);

        $insertGuarantee = $db->prepare(
            'INSERT INTO guarantees (id, guarantee_number, raw_data, import_source, imported_at, imported_by, is_test_data)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $insertGuarantee->execute([
            $guaranteeId,
            'TEST-HYBRID-' . $guaranteeId,
            json_encode([
                'supplier' => 'Supplier Test',
                'bank' => 'Bank A',
                'amount' => 1000,
                'expiry_date' => '2030-01-01',
                'issue_date' => '2026-01-01',
            ], JSON_UNESCAPED_UNICODE),
            'unit_test',
            date('Y-m-d H:i:s'),
            'phpunit',
            1,
        ]);

        $db->prepare('DELETE FROM guarantee_history WHERE guarantee_id = ?')->execute([$guaranteeId]);

        $insert = $db->prepare(
            'INSERT INTO guarantee_history (
                guarantee_id, event_type, event_subtype, snapshot_data, event_details, created_at, created_by,
                history_version, patch_data, anchor_snapshot, is_anchor, anchor_reason, letter_context, template_version
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $anchor = [
            'amount' => 1000,
            'status' => 'pending',
            'bank_name' => 'Bank A',
        ];
        $patchAmount = [
            ['op' => 'replace', 'path' => '/amount', 'value' => 1500],
        ];
        $patchStatus = [
            ['op' => 'replace', 'path' => '/status', 'value' => 'ready'],
        ];

        $insert->execute([
            $guaranteeId,
            'import',
            'excel',
            null,
            '{}',
            date('Y-m-d H:i:s'),
            'test',
            'v2',
            json_encode($patchAmount, JSON_UNESCAPED_UNICODE),
            json_encode($anchor, JSON_UNESCAPED_UNICODE),
            1,
            'milestone_event',
            null,
            'v1',
        ]);

        $insert->execute([
            $guaranteeId,
            'status_change',
            'status_change',
            null,
            '{}',
            date('Y-m-d H:i:s'),
            'test',
            'v2',
            json_encode($patchStatus, JSON_UNESCAPED_UNICODE),
            null,
            0,
            null,
            null,
            'v1',
        ]);

        $state = TimelineHybridLedger::fetchLatestKnownState($db, $guaranteeId);

        $this->assertIsArray($state);
        $this->assertSame(1500, $state['amount'] ?? null);
        $this->assertSame('ready', $state['status'] ?? null);
        $this->assertSame('Bank A', $state['bank_name'] ?? null);

        $db->prepare('DELETE FROM guarantee_history WHERE guarantee_id = ?')->execute([$guaranteeId]);
        $db->prepare('DELETE FROM guarantees WHERE id = ?')->execute([$guaranteeId]);
    }

    public function testLegacySnapshotFallbackIsAppliedEvenWhenStateAlreadyExists(): void
    {
        $db = Database::connect();

        if (!TimelineHybridLedger::supportsHybridColumns($db)) {
            $this->markTestSkipped('Hybrid columns are not available in guarantee_history');
        }

        $guaranteeId = 990002;
        $db->prepare('DELETE FROM guarantee_history WHERE guarantee_id = ?')->execute([$guaranteeId]);
        $db->prepare('DELETE FROM guarantees WHERE id = ?')->execute([$guaranteeId]);

        $insertGuarantee = $db->prepare(
            'INSERT INTO guarantees (id, guarantee_number, raw_data, import_source, imported_at, imported_by, is_test_data)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $insertGuarantee->execute([
            $guaranteeId,
            'TEST-HYBRID-' . $guaranteeId,
            json_encode([
                'supplier' => 'Supplier Test',
                'bank' => 'Bank A',
                'amount' => 1000,
                'expiry_date' => '2030-01-01',
                'issue_date' => '2026-01-01',
            ], JSON_UNESCAPED_UNICODE),
            'unit_test',
            date('Y-m-d H:i:s'),
            'phpunit',
            1,
        ]);

        $insert = $db->prepare(
            'INSERT INTO guarantee_history (
                guarantee_id, event_type, event_subtype, snapshot_data, event_details, created_at, created_by,
                history_version, patch_data, anchor_snapshot, is_anchor, anchor_reason, letter_context, template_version
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        // Row 1: establish non-empty state via anchor+patch.
        $insert->execute([
            $guaranteeId,
            'import',
            'excel',
            null,
            '{}',
            date('Y-m-d H:i:s'),
            'test',
            'v2',
            json_encode([['op' => 'replace', 'path' => '/amount', 'value' => 1000]], JSON_UNESCAPED_UNICODE),
            json_encode(['amount' => 900, 'bank_name' => 'AnchorBank', 'status' => 'pending'], JSON_UNESCAPED_UNICODE),
            1,
            'milestone_event',
            null,
            'v1',
        ]);

        // Row 2: legacy row (no anchor/patch) must fallback to snapshot_data contract.
        $insert->execute([
            $guaranteeId,
            'modified',
            'manual_edit',
            json_encode(['amount' => 1200, 'bank_name' => 'LegacyBank', 'status' => 'pending'], JSON_UNESCAPED_UNICODE),
            json_encode([
                'changes' => [
                    [
                        'field' => 'amount',
                        'old_value' => 1000,
                        'new_value' => 1200,
                        'trigger' => 'manual_edit',
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE),
            date('Y-m-d H:i:s'),
            'test',
            null,
            null,
            null,
            0,
            null,
            null,
            null,
        ]);
        $legacyRowId = (int) $db->lastInsertId();

        // Row 3: marker row to test reconstructStateBeforeEvent.
        $insert->execute([
            $guaranteeId,
            'status_change',
            'status_change',
            null,
            '{}',
            date('Y-m-d H:i:s'),
            'test',
            'v2',
            null,
            null,
            0,
            null,
            null,
            'v1',
        ]);
        $targetEventId = (int) $db->lastInsertId();

        $upToState = TimelineHybridLedger::reconstructStateUpToEvent($db, $guaranteeId, $legacyRowId);
        $beforeState = TimelineHybridLedger::reconstructStateBeforeEvent($db, $guaranteeId, $targetEventId);

        // Fallback from legacy row must override prior state from row 1.
        $this->assertSame('LegacyBank', $upToState['bank_name'] ?? null);
        $this->assertSame('LegacyBank', $beforeState['bank_name'] ?? null);

        // Normalized before-change contract from event_details.
        $this->assertSame(1000, $upToState['amount'] ?? null);
        $this->assertSame(1000, $beforeState['amount'] ?? null);

        $db->prepare('DELETE FROM guarantee_history WHERE guarantee_id = ?')->execute([$guaranteeId]);
        $db->prepare('DELETE FROM guarantees WHERE id = ?')->execute([$guaranteeId]);
    }
}
