<?php
declare(strict_types=1);

use App\Support\ApiTokenService;
use App\Support\Database;
use PHPUnit\Framework\TestCase;

final class EnterpriseApiFlowsTest extends TestCase
{
    private static string $projectRoot;
    private static ?PDO $db = null;
    private static $serverProcess = null;
    private static int $serverPid = 0;
    private static int $serverPort = 0;
    private static string $baseUrl = '';
    private static string $serverLogPath = '';

    private static int $adminUserId = 0;
    private static string $adminToken = '';
    private static int $operatorUserId = 0;
    private static string $operatorUsername = '';
    private static string $operatorToken = '';
    private static int $supervisorUserId = 0;
    private static string $supervisorToken = '';
    private static int $approverUserId = 0;
    private static string $approverToken = '';

    /** @var int[] */
    private static array $undoRequestIds = [];
    /** @var int[] */
    private static array $deadLetterIds = [];
    /** @var int[] */
    private static array $printGuaranteeIds = [];
    /** @var int[] */
    private static array $fixtureGuaranteeIds = [];
    /** @var string[] */
    private static array $batchImportSources = [];

    public static function setUpBeforeClass(): void
    {
        self::$projectRoot = dirname(__DIR__, 2);
        require_once self::$projectRoot . '/app/Support/autoload.php';

        self::$db = Database::connect();
        self::$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        self::bootstrapUsersAndTokens();
        self::startServer();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db instanceof PDO) {
            if (!empty(self::$undoRequestIds)) {
                $in = implode(',', array_fill(0, count(self::$undoRequestIds), '?'));
                $stmt = self::$db->prepare("DELETE FROM undo_requests WHERE id IN ({$in})");
                $stmt->execute(self::$undoRequestIds);
            }

            if (!empty(self::$deadLetterIds)) {
                $in = implode(',', array_fill(0, count(self::$deadLetterIds), '?'));
                $stmt = self::$db->prepare("DELETE FROM scheduler_dead_letters WHERE id IN ({$in})");
                $stmt->execute(self::$deadLetterIds);
            }

            if (!empty(self::$printGuaranteeIds)) {
                $in = implode(',', array_fill(0, count(self::$printGuaranteeIds), '?'));
                $stmt = self::$db->prepare(
                    "DELETE FROM print_events WHERE source_page = '/tests/integration' AND guarantee_id IN ({$in})"
                );
                $stmt->execute(array_values(array_unique(self::$printGuaranteeIds)));
            } else {
                self::$db->exec("DELETE FROM print_events WHERE source_page = '/tests/integration'");
            }

            if (!empty(self::$fixtureGuaranteeIds)) {
                $fixtureIds = array_values(array_unique(self::$fixtureGuaranteeIds));
                $in = implode(',', array_fill(0, count($fixtureIds), '?'));

                $stmt = self::$db->prepare("DELETE FROM undo_requests WHERE guarantee_id IN ({$in})");
                $stmt->execute($fixtureIds);

                $attachmentStmt = self::$db->prepare("SELECT file_path FROM guarantee_attachments WHERE guarantee_id IN ({$in})");
                $attachmentStmt->execute($fixtureIds);
                $attachmentRows = $attachmentStmt->fetchAll(PDO::FETCH_ASSOC);
                if (is_array($attachmentRows)) {
                    foreach ($attachmentRows as $attachmentRow) {
                        $relativePath = trim((string)($attachmentRow['file_path'] ?? ''));
                        if ($relativePath === '') {
                            continue;
                        }
                        $normalizedRelativePath = str_replace('/', DIRECTORY_SEPARATOR, ltrim($relativePath, '/\\'));
                        $absolutePath = self::$projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . $normalizedRelativePath;
                        if (is_file($absolutePath)) {
                            @unlink($absolutePath);
                        }
                    }
                }

                $stmt = self::$db->prepare("DELETE FROM guarantee_attachments WHERE guarantee_id IN ({$in})");
                $stmt->execute($fixtureIds);

                $stmt = self::$db->prepare("DELETE FROM guarantee_notes WHERE guarantee_id IN ({$in})");
                $stmt->execute($fixtureIds);

                $stmt = self::$db->prepare("DELETE FROM guarantee_history WHERE guarantee_id IN ({$in})");
                $stmt->execute($fixtureIds);

                $stmt = self::$db->prepare("DELETE FROM guarantee_decisions WHERE guarantee_id IN ({$in})");
                $stmt->execute($fixtureIds);

                $stmt = self::$db->prepare("DELETE FROM guarantees WHERE id IN ({$in})");
                $stmt->execute($fixtureIds);

                $fixtureTargetIds = array_map(static fn(int $id): string => (string)$id, $fixtureIds);
                $stmt = self::$db->prepare("DELETE FROM break_glass_events WHERE target_type = 'guarantee' AND target_id IN ({$in})");
                $stmt->execute($fixtureTargetIds);
            }

            if (!empty(self::$batchImportSources)) {
                $sources = array_values(array_unique(self::$batchImportSources));
                $in = implode(',', array_fill(0, count($sources), '?'));

                $stmt = self::$db->prepare("DELETE FROM batch_audit_events WHERE import_source IN ({$in})");
                $stmt->execute($sources);

                $stmt = self::$db->prepare("DELETE FROM batch_metadata WHERE import_source IN ({$in})");
                $stmt->execute($sources);

                $stmt = self::$db->prepare("DELETE FROM break_glass_events WHERE target_type = 'batch' AND target_id IN ({$in})");
                $stmt->execute($sources);
            }

            self::$db->exec("DELETE FROM api_access_tokens WHERE token_name LIKE 'integration-%'");

            if (self::$approverUserId > 0) {
                $stmt = self::$db->prepare('DELETE FROM users WHERE id = ?');
                $stmt->execute([self::$approverUserId]);
            }

            if (self::$supervisorUserId > 0) {
                $stmt = self::$db->prepare('DELETE FROM users WHERE id = ?');
                $stmt->execute([self::$supervisorUserId]);
            }

            if (self::$operatorUserId > 0) {
                $stmt = self::$db->prepare('DELETE FROM users WHERE id = ?');
                $stmt->execute([self::$operatorUserId]);
            }
        }

        self::$adminToken = '';
        self::$operatorToken = '';
        self::$supervisorToken = '';
        self::$approverToken = '';
        self::stopServer();
    }

    public function testAuthFlowRequiresBearerAndAcceptsToken(): void
    {
        $guest = self::request('GET', '/api/me.php', [
            'Accept: application/json',
        ]);
        $this->assertSame(401, $guest['status'], $guest['body']);
        $guestPayload = json_decode($guest['body'], true);
        $this->assertIsArray($guestPayload);
        $this->assertSame(false, $guestPayload['success'] ?? null);

        $authed = self::request('GET', '/api/me.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ]);
        $this->assertSame(200, $authed['status'], $authed['body']);
        $authedPayload = json_decode($authed['body'], true);
        $this->assertIsArray($authedPayload);
        $this->assertTrue((bool)($authedPayload['success'] ?? false), $authed['body']);
        $this->assertSame(self::$adminUserId, (int)($authedPayload['user']['id'] ?? 0));
    }

    public function testPrintEventsApiFlow(): void
    {
        $guaranteeId = self::requireGuaranteeId();
        self::$printGuaranteeIds[] = $guaranteeId;

        $post = self::request('POST', '/api/print-events.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], [
            'event_type' => 'preview_opened',
            'context' => 'single_letter',
            'guarantee_id' => $guaranteeId,
            'channel' => 'browser',
            'source_page' => '/tests/integration',
            'meta' => [
                'suite' => 'EnterpriseApiFlowsTest',
                'scenario' => 'print-events',
            ],
        ]);
        $this->assertSame(200, $post['status'], $post['body']);
        $postPayload = json_decode($post['body'], true);
        $this->assertIsArray($postPayload);
        $this->assertTrue((bool)($postPayload['success'] ?? false), $post['body']);
        $this->assertSame(1, (int)($postPayload['data']['inserted'] ?? 0));

        $list = self::request(
            'GET',
            '/api/print-events.php?guarantee_id=' . $guaranteeId . '&limit=10',
            [
                'Accept: application/json',
                'Authorization: Bearer ' . self::$adminToken,
            ]
        );
        $this->assertSame(200, $list['status'], $list['body']);
        $listPayload = json_decode($list['body'], true);
        $this->assertIsArray($listPayload);
        $this->assertTrue((bool)($listPayload['success'] ?? false), $list['body']);

        $rows = is_array($listPayload['data'] ?? null) ? $listPayload['data'] : [];
        $found = false;
        foreach ($rows as $row) {
            if (
                (string)($row['event_type'] ?? '') === 'preview_opened'
                && (string)($row['source_page'] ?? '') === '/tests/integration'
            ) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, $list['body']);
    }

    public function testHistorySnapshotTimeMachineFlow(): void
    {
        $historyId = self::requireHistoryId();

        $response = self::request(
            'GET',
            '/api/get-history-snapshot.php?history_id=' . $historyId . '&index=1',
            [
                'Accept: text/html',
                'Authorization: Bearer ' . self::$adminToken,
            ]
        );

        $this->assertSame(200, $response['status'], $response['body']);
        $this->assertStringContainsString('record-form-section', $response['body']);
    }

    public function testGetRecordReadPathDoesNotPersistAutoMatches(): void
    {
        if (!(self::$db instanceof PDO)) {
            $this->fail('Database handle is not available');
        }

        $fixture = self::createGetRecordReadOnlyFixture();
        $guaranteeId = (int)$fixture['guarantee_id'];
        $recordIndex = self::indexForGuaranteeInAllFilter($guaranteeId);

        $decisionBefore = self::decisionSnapshotForGuarantee($guaranteeId);
        $historyCountBefore = self::historyCountForGuarantee($guaranteeId);
        $this->assertNotEmpty($decisionBefore, 'Fixture must include a visible pending decision row.');
        $this->assertNull($decisionBefore['supplier_id'] ?? null, 'Fixture must start without supplier decision.');
        $this->assertNull($decisionBefore['bank_id'] ?? null, 'Fixture must start without bank decision.');
        $this->assertSame('pending', (string)($decisionBefore['status'] ?? ''), 'Fixture must start in pending status.');
        $this->assertSame(0, $historyCountBefore, 'Fixture must start without history rows.');

        $response = self::request(
            'GET',
            '/api/get-record.php?index=' . $recordIndex . '&filter=all',
            [
                'Accept: text/html',
                'Authorization: Bearer ' . self::$adminToken,
            ]
        );

        $this->assertSame(200, $response['status'], $response['body']);
        $this->assertStringContainsString('record-form-section', $response['body']);

        $decisionAfter = self::decisionSnapshotForGuarantee($guaranteeId);
        $historyCountAfter = self::historyCountForGuarantee($guaranteeId);

        $this->assertSame($decisionBefore['supplier_id'] ?? null, $decisionAfter['supplier_id'] ?? null, 'GET /api/get-record.php must not mutate supplier decision.');
        $this->assertSame($decisionBefore['bank_id'] ?? null, $decisionAfter['bank_id'] ?? null, 'GET /api/get-record.php must not mutate bank decision.');
        $this->assertSame((string)($decisionBefore['status'] ?? ''), (string)($decisionAfter['status'] ?? ''), 'GET /api/get-record.php must not mutate decision status.');
        $this->assertSame((string)($decisionBefore['decision_source'] ?? ''), (string)($decisionAfter['decision_source'] ?? ''), 'GET /api/get-record.php must not mutate decision source.');
        $this->assertSame($historyCountBefore, $historyCountAfter, 'GET /api/get-record.php must not write timeline events.');
    }

    public function testGetRecordOutOfScopeActionableFilterReturnsEmptyStateWithoutSensitiveFields(): void
    {
        if (!(self::$db instanceof PDO)) {
            $this->fail('Database handle is not available');
        }

        $fixture = self::createHiddenWriteFixture();
        $guaranteeId = (int)$fixture['guarantee_id'];
        $guaranteeNumber = self::guaranteeNumberForGuarantee($guaranteeId);
        $rawData = self::rawDataForGuarantee($guaranteeId);

        $response = self::request(
            'GET',
            '/api/get-record.php?index=1&filter=actionable&stage=draft',
            [
                'Accept: text/html',
                'Authorization: Bearer ' . self::$supervisorToken,
            ]
        );

        $this->assertSame(200, $response['status'], $response['body']);
        $this->assertStringContainsString('record-form-section', $response['body']);
        $this->assertStringContainsString('decision-card-empty-state', $response['body']);
        $this->assertStringContainsString('data-surface-can-view-record="0"', $response['body']);
        $this->assertStringContainsString('data-surface-can-view-preview="0"', $response['body']);

        $this->assertStringNotContainsString($guaranteeNumber, $response['body']);
        $this->assertStringNotContainsString('data-record-id="' . $guaranteeId . '"', $response['body']);

        $supplierName = trim((string)($rawData['supplier'] ?? ''));
        if ($supplierName !== '') {
            $this->assertStringNotContainsString($supplierName, $response['body']);
        }

        $contractNumber = trim((string)($rawData['contract_number'] ?? ''));
        if ($contractNumber !== '') {
            $this->assertStringNotContainsString($contractNumber, $response['body']);
        }
    }

    public function testSaveAndNextRejectsInvisibleGuaranteeAndPreventsWrites(): void
    {
        if (!(self::$db instanceof PDO)) {
            $this->fail('Database handle is not available');
        }

        $fixture = self::createHiddenWriteFixture();
        $guaranteeId = (int)$fixture['guarantee_id'];
        $supplierId = (int)$fixture['supplier_id'];
        $supplierName = (string)$fixture['supplier_name'];

        $decisionBefore = self::decisionSnapshotForGuarantee($guaranteeId);
        $historyCountBefore = self::historyCountForGuarantee($guaranteeId);

        $response = self::request('POST', '/api/save-and-next.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$supervisorToken,
        ], [
            'guarantee_id' => $guaranteeId,
            'supplier_id' => $supplierId,
            'supplier_name' => $supplierName,
            'status_filter' => 'all',
        ]);

        $this->assertSame(403, $response['status'], $response['body']);
        $payload = json_decode($response['body'], true);
        $this->assertIsArray($payload);
        $this->assertSame(false, $payload['success'] ?? null, $response['body']);
        $this->assertSame('Permission Denied', (string)($payload['error'] ?? ''), $response['body']);

        $decisionAfter = self::decisionSnapshotForGuarantee($guaranteeId);
        $historyCountAfter = self::historyCountForGuarantee($guaranteeId);

        $this->assertSame($decisionBefore['supplier_id'] ?? null, $decisionAfter['supplier_id'] ?? null, 'Invisible save-and-next must not mutate supplier_id.');
        $this->assertSame($decisionBefore['bank_id'] ?? null, $decisionAfter['bank_id'] ?? null, 'Invisible save-and-next must not mutate bank_id.');
        $this->assertSame((string)($decisionBefore['status'] ?? ''), (string)($decisionAfter['status'] ?? ''), 'Invisible save-and-next must not mutate status.');
        $this->assertSame((string)($decisionBefore['decision_source'] ?? ''), (string)($decisionAfter['decision_source'] ?? ''), 'Invisible save-and-next must not mutate decision source.');
        $this->assertSame($historyCountBefore, $historyCountAfter, 'Invisible save-and-next must not write timeline events.');
    }

    public function testSaveNoteRejectsInvisibleGuaranteeAndPreventsWrites(): void
    {
        if (!(self::$db instanceof PDO)) {
            $this->fail('Database handle is not available');
        }

        $fixture = self::createHiddenWriteFixture();
        $guaranteeId = (int)$fixture['guarantee_id'];
        $notesBefore = self::notesCountForGuarantee($guaranteeId);

        $response = self::request('POST', '/api/save-note.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$supervisorToken,
        ], [
            'guarantee_id' => $guaranteeId,
            'content' => 'Hidden record note should be denied',
        ]);

        $this->assertSame(403, $response['status'], $response['body']);
        $payload = json_decode($response['body'], true);
        $this->assertIsArray($payload);
        $this->assertSame(false, $payload['success'] ?? null, $response['body']);
        $this->assertSame('Permission Denied', (string)($payload['error'] ?? ''), $response['body']);

        $notesAfter = self::notesCountForGuarantee($guaranteeId);
        $this->assertSame($notesBefore, $notesAfter, 'Invisible save-note must not insert notes.');
    }

    public function testUploadAttachmentRejectsInvisibleGuaranteeAndPreventsWrites(): void
    {
        if (!(self::$db instanceof PDO)) {
            $this->fail('Database handle is not available');
        }

        $fixture = self::createHiddenWriteFixture();
        $guaranteeId = (int)$fixture['guarantee_id'];
        $attachmentsBefore = self::attachmentsCountForGuarantee($guaranteeId);

        $response = self::requestMultipart(
            '/api/upload-attachment.php',
            [
                'Accept: application/json',
                'Authorization: Bearer ' . self::$supervisorToken,
            ],
            [
                'guarantee_id' => (string)$guaranteeId,
            ],
            [
                'field_name' => 'file',
                'filename' => 'hidden-visibility.txt',
                'content_type' => 'text/plain',
                'content' => 'hidden fixture upload payload',
            ]
        );

        $this->assertSame(403, $response['status'], $response['body']);
        $payload = json_decode($response['body'], true);
        $this->assertIsArray($payload);
        $this->assertSame(false, $payload['success'] ?? null, $response['body']);
        $this->assertSame('Permission Denied', (string)($payload['error'] ?? ''), $response['body']);

        $attachmentsAfter = self::attachmentsCountForGuarantee($guaranteeId);
        $this->assertSame($attachmentsBefore, $attachmentsAfter, 'Invisible upload-attachment must not insert attachments.');
    }

    public function testUndoGovernanceWorkflowEnforcesDualControl(): void
    {
        $guaranteeId = self::requireGuaranteeIdWithDecision();
        $reason = '[integration] dual-control validation #' . bin2hex(random_bytes(4));

        $submit = self::request('POST', '/api/undo-requests.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$operatorToken,
        ], [
            'action' => 'submit',
            'guarantee_id' => $guaranteeId,
            'reason' => $reason,
        ]);
        $this->assertSame(200, $submit['status'], $submit['body']);
        $submitPayload = json_decode($submit['body'], true);
        $this->assertIsArray($submitPayload);
        $this->assertTrue((bool)($submitPayload['success'] ?? false), $submit['body']);
        $requestId = (int)($submitPayload['request_id'] ?? 0);
        $this->assertGreaterThan(0, $requestId, $submit['body']);
        self::$undoRequestIds[] = $requestId;

        $selfApprove = self::request('POST', '/api/undo-requests.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$operatorToken,
        ], [
            'action' => 'approve',
            'request_id' => $requestId,
            'note' => 'self-approval should fail',
        ]);
        $this->assertSame(400, $selfApprove['status'], $selfApprove['body']);
        $selfApprovePayload = json_decode($selfApprove['body'], true);
        $this->assertIsArray($selfApprovePayload);
        $this->assertSame(false, $selfApprovePayload['success'] ?? null);
        $this->assertStringContainsString('Self-approval', (string)($selfApprovePayload['error'] ?? ''));

        $approve = self::request('POST', '/api/undo-requests.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], [
            'action' => 'approve',
            'request_id' => $requestId,
            'note' => 'approved by integration admin token',
        ]);
        $this->assertSame(200, $approve['status'], $approve['body']);
        $approvePayload = json_decode($approve['body'], true);
        $this->assertIsArray($approvePayload);
        $this->assertTrue((bool)($approvePayload['success'] ?? false), $approve['body']);

        $list = self::request(
            'GET',
            '/api/undo-requests.php?action=list&status=approved&guarantee_id=' . $guaranteeId . '&limit=30',
            [
                'Accept: application/json',
                'Authorization: Bearer ' . self::$adminToken,
            ]
        );
        $this->assertSame(200, $list['status'], $list['body']);
        $listPayload = json_decode($list['body'], true);
        $this->assertIsArray($listPayload);
        $this->assertTrue((bool)($listPayload['success'] ?? false), $list['body']);

        $rows = is_array($listPayload['data'] ?? null) ? $listPayload['data'] : [];
        $ids = array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $rows);
        $this->assertContains($requestId, $ids, $list['body']);
    }

    public function testLifecycleMutationFlowWritesTimelineHybridEvents(): void
    {
        if (!(self::$db instanceof PDO)) {
            $this->fail('Database handle is not available');
        }

        $fixture = self::createLifecycleFixture();
        $guaranteeId = (int)$fixture['guarantee_id'];
        $oldExpiry = (string)$fixture['expiry_date'];
        $newAmount = (float)$fixture['new_amount'];

        $extend = self::request('POST', '/api/extend.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], [
            'guarantee_id' => $guaranteeId,
            'decided_by' => 'integration_admin',
        ]);
        $this->assertSame(200, $extend['status'], $extend['body']);
        $extendPayload = json_decode($extend['body'], true);
        $this->assertIsArray($extendPayload);
        $this->assertTrue((bool)($extendPayload['success'] ?? false), $extend['body']);
        $this->assertNull($extendPayload['error'] ?? null, $extend['body']);
        $this->assertNotSame('', trim((string)($extendPayload['request_id'] ?? '')), $extend['body']);
        $this->assertStringContainsString('record-form-section', (string)($extendPayload['data']['html'] ?? ''));

        $rawAfterExtend = self::rawDataForGuarantee($guaranteeId);
        $expectedExpiry = date('Y-m-d', strtotime($oldExpiry . ' +1 year'));
        $this->assertSame($expectedExpiry, (string)($rawAfterExtend['expiry_date'] ?? ''), json_encode($rawAfterExtend, JSON_UNESCAPED_UNICODE));

        $reduce = self::request('POST', '/api/reduce.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], [
            'guarantee_id' => $guaranteeId,
            'new_amount' => $newAmount,
            'decided_by' => 'integration_admin',
        ]);
        $this->assertSame(200, $reduce['status'], $reduce['body']);
        $reducePayload = json_decode($reduce['body'], true);
        $this->assertIsArray($reducePayload);
        $this->assertTrue((bool)($reducePayload['success'] ?? false), $reduce['body']);
        $this->assertNull($reducePayload['error'] ?? null, $reduce['body']);
        $this->assertNotSame('', trim((string)($reducePayload['request_id'] ?? '')), $reduce['body']);
        $this->assertStringContainsString('record-form-section', (string)($reducePayload['data']['html'] ?? ''));

        $rawAfterReduce = self::rawDataForGuarantee($guaranteeId);
        $this->assertSame((float)$newAmount, (float)($rawAfterReduce['amount'] ?? 0.0), json_encode($rawAfterReduce, JSON_UNESCAPED_UNICODE));

        $release = self::request('POST', '/api/release.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], [
            'guarantee_id' => $guaranteeId,
            'reason' => '[integration] lifecycle release',
            'decided_by' => 'integration_admin',
        ]);
        $this->assertSame(200, $release['status'], $release['body']);
        $releasePayload = json_decode($release['body'], true);
        $this->assertIsArray($releasePayload);
        $this->assertTrue((bool)($releasePayload['success'] ?? false), $release['body']);
        $this->assertNull($releasePayload['error'] ?? null, $release['body']);
        $this->assertNotSame('', trim((string)($releasePayload['request_id'] ?? '')), $release['body']);
        $this->assertStringContainsString('خطاب الإفراج', (string)($releasePayload['data']['html'] ?? ''));

        $releaseDecision = self::decisionStateForGuarantee($guaranteeId);
        $this->assertSame('released', (string)($releaseDecision['status'] ?? ''));
        $this->assertSame(1, (int)($releaseDecision['is_locked'] ?? 0));

        $reopen = self::request('POST', '/api/reopen.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], [
            'guarantee_id' => $guaranteeId,
            'reason' => '[integration] lifecycle reopen',
        ]);
        $this->assertSame(200, $reopen['status'], $reopen['body']);
        $reopenPayload = json_decode($reopen['body'], true);
        $this->assertIsArray($reopenPayload);
        $this->assertTrue((bool)($reopenPayload['success'] ?? false), $reopen['body']);

        if ((string)($reopenPayload['mode'] ?? '') === 'undo_request') {
            $requestId = (int)($reopenPayload['request_id'] ?? 0);
            $this->assertGreaterThan(0, $requestId, $reopen['body']);
            self::$undoRequestIds[] = $requestId;

            $approve = self::request('POST', '/api/undo-requests.php', [
                'Accept: application/json',
                'Authorization: Bearer ' . self::$operatorToken,
            ], [
                'action' => 'approve',
                'request_id' => $requestId,
                'note' => 'integration approve lifecycle reopen',
            ]);
            $this->assertSame(200, $approve['status'], $approve['body']);
            $approvePayload = json_decode($approve['body'], true);
            $this->assertIsArray($approvePayload);
            $this->assertTrue((bool)($approvePayload['success'] ?? false), $approve['body']);

            $execute = self::request('POST', '/api/undo-requests.php', [
                'Accept: application/json',
                'Authorization: Bearer ' . self::$operatorToken,
            ], [
                'action' => 'execute',
                'request_id' => $requestId,
            ]);
            $this->assertSame(200, $execute['status'], $execute['body']);
            $executePayload = json_decode($execute['body'], true);
            $this->assertIsArray($executePayload);
            $this->assertTrue((bool)($executePayload['success'] ?? false), $execute['body']);
        }

        $reopenDecision = self::decisionStateForGuarantee($guaranteeId);
        $this->assertSame('pending', (string)($reopenDecision['status'] ?? ''), json_encode($reopenDecision, JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, (int)($reopenDecision['is_locked'] ?? 1), json_encode($reopenDecision, JSON_UNESCAPED_UNICODE));

        $historyRows = self::historyRowsForGuarantee($guaranteeId);
        $subtypeRows = [];
        foreach ($historyRows as $row) {
            $subtype = (string)($row['event_subtype'] ?? '');
            if ($subtype !== '') {
                $subtypeRows[$subtype] = $row;
            }
        }

        foreach (['extension', 'reduction', 'release', 'reopened'] as $requiredSubtype) {
            $this->assertArrayHasKey($requiredSubtype, $subtypeRows, json_encode($historyRows, JSON_UNESCAPED_UNICODE));
            $row = $subtypeRows[$requiredSubtype];
            $this->assertSame('v2', (string)($row['history_version'] ?? ''), json_encode($row, JSON_UNESCAPED_UNICODE));
            $this->assertSame(1, (int)($row['is_anchor'] ?? 0), json_encode($row, JSON_UNESCAPED_UNICODE));
            $this->assertNotSame('', trim((string)($row['anchor_snapshot'] ?? '')), json_encode($row, JSON_UNESCAPED_UNICODE));
        }
    }

    public function testSaveImportUsesUnifiedEnvelope(): void
    {
        $valid = self::request('POST', '/api/save-import.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], [
            'import_id' => 'integration-envelope-check',
            'guarantees' => [],
        ]);
        $this->assertSame(200, $valid['status'], $valid['body']);
        $validPayload = json_decode($valid['body'], true);
        $this->assertIsArray($validPayload);
        $this->assertTrue((bool)($validPayload['success'] ?? false), $valid['body']);
        $this->assertNull($validPayload['error'] ?? null, $valid['body']);
        $this->assertNotSame('', trim((string)($validPayload['request_id'] ?? '')), $valid['body']);
        $this->assertSame(0, (int)($validPayload['data']['saved_count'] ?? -1), $valid['body']);

        $invalid = self::request('POST', '/api/save-import.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], [
            'guarantees' => [],
        ]);
        $this->assertSame(400, $invalid['status'], $invalid['body']);
        $invalidPayload = json_decode($invalid['body'], true);
        $this->assertIsArray($invalidPayload);
        $this->assertSame(false, $invalidPayload['success'] ?? null, $invalid['body']);
        $this->assertNull($invalidPayload['data'] ?? null, $invalid['body']);
        $this->assertSame('Invalid Input Data', (string)($invalidPayload['error'] ?? ''), $invalid['body']);
        $this->assertNotSame('', trim((string)($invalidPayload['request_id'] ?? '')), $invalid['body']);
    }

    public function testReopenEndpointAllowsSupervisorWithoutManageData(): void
    {
        if (!(self::$db instanceof PDO)) {
            $this->fail('Database handle is not available');
        }

        $guaranteeId = self::createReleasedGuaranteeFixture();
        $response = self::request('POST', '/api/reopen.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$supervisorToken,
        ], [
            'guarantee_id' => $guaranteeId,
            'reason' => '[integration] supervisor reopen request',
        ]);

        $this->assertSame(200, $response['status'], $response['body']);
        $payload = json_decode($response['body'], true);
        $this->assertIsArray($payload);
        $this->assertTrue((bool)($payload['success'] ?? false), $response['body']);
        $this->assertNotSame('break_glass_direct', (string)($payload['mode'] ?? ''));

        if ((string)($payload['mode'] ?? '') === 'undo_request') {
            $requestId = (int)($payload['request_id'] ?? 0);
            $this->assertGreaterThan(0, $requestId, $response['body']);
            self::$undoRequestIds[] = $requestId;
        }
    }

    public function testBreakGlassReopenEnforcesTicketAndRecordsAuditForApprover(): void
    {
        if (!(self::$db instanceof PDO)) {
            $this->fail('Database handle is not available');
        }

        $guaranteeId = self::createReleasedGuaranteeFixture();
        $withoutTicket = self::request('POST', '/api/reopen.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$approverToken,
        ], [
            'guarantee_id' => $guaranteeId,
            'break_glass' => [
                'enabled' => true,
                'reason' => 'Critical correction window',
            ],
        ]);
        $this->assertSame(400, $withoutTicket['status'], $withoutTicket['body']);
        $withoutTicketPayload = json_decode($withoutTicket['body'], true);
        $this->assertIsArray($withoutTicketPayload);
        $this->assertStringContainsString('رقم التذكرة/الحادث مطلوب', (string)($withoutTicketPayload['error'] ?? ''));

        $beforeCount = self::countBreakGlassEventsForTarget('reopen_guarantee_direct', 'guarantee', (string)$guaranteeId);
        $ticketRef = 'INC-' . bin2hex(random_bytes(4));
        $withTicket = self::request('POST', '/api/reopen.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$approverToken,
        ], [
            'guarantee_id' => $guaranteeId,
            'break_glass' => [
                'enabled' => true,
                'reason' => 'Critical correction window',
                'ticket_ref' => $ticketRef,
                'ttl_minutes' => 45,
            ],
        ]);
        $this->assertSame(200, $withTicket['status'], $withTicket['body']);
        $withTicketPayload = json_decode($withTicket['body'], true);
        $this->assertIsArray($withTicketPayload);
        $this->assertTrue((bool)($withTicketPayload['success'] ?? false), $withTicket['body']);
        $this->assertSame('break_glass_direct', (string)($withTicketPayload['mode'] ?? ''));

        $event = self::latestBreakGlassEventForTarget('reopen_guarantee_direct', 'guarantee', (string)$guaranteeId);
        $this->assertIsArray($event);
        $this->assertSame($ticketRef, (string)($event['ticket_ref'] ?? ''));
        $this->assertSame('Critical correction window', (string)($event['reason'] ?? ''));
        $afterCount = self::countBreakGlassEventsForTarget('reopen_guarantee_direct', 'guarantee', (string)$guaranteeId);
        $this->assertSame($beforeCount + 1, $afterCount);

        $decision = self::decisionStateForGuarantee($guaranteeId);
        $this->assertSame('pending', (string)($decision['status'] ?? ''), json_encode($decision, JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, (int)($decision['is_locked'] ?? 1), json_encode($decision, JSON_UNESCAPED_UNICODE));
    }

    public function testBatchReopenAllowsSupervisorWithoutManageDataAndRecordsAudit(): void
    {
        if (!(self::$db instanceof PDO)) {
            $this->fail('Database handle is not available');
        }

        $importSource = 'integration_batch_reopen_' . bin2hex(random_bytes(4));
        self::$batchImportSources[] = $importSource;

        $stmt = self::$db->prepare(
            "INSERT INTO batch_metadata (import_source, status, created_at) VALUES (?, 'completed', CURRENT_TIMESTAMP)"
        );
        $stmt->execute([$importSource]);

        $response = self::request('POST', '/api/batches.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$supervisorToken,
        ], [
            'action' => 'reopen',
            'import_source' => $importSource,
            'reason' => '[integration] supervisor batch reopen',
        ]);
        $this->assertSame(200, $response['status'], $response['body']);
        $payload = json_decode($response['body'], true);
        $this->assertIsArray($payload);
        $this->assertTrue((bool)($payload['success'] ?? false), $response['body']);

        $statusStmt = self::$db->prepare('SELECT status FROM batch_metadata WHERE import_source = ? LIMIT 1');
        $statusStmt->execute([$importSource]);
        $this->assertSame('active', (string)$statusStmt->fetchColumn());

        $auditStmt = self::$db->prepare(
            'SELECT event_type, reason FROM batch_audit_events WHERE import_source = ? ORDER BY id DESC LIMIT 1'
        );
        $auditStmt->execute([$importSource]);
        $audit = $auditStmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($audit);
        $this->assertSame('batch_reopened', (string)($audit['event_type'] ?? ''), json_encode($audit, JSON_UNESCAPED_UNICODE));
        $this->assertSame('[integration] supervisor batch reopen', (string)($audit['reason'] ?? ''), json_encode($audit, JSON_UNESCAPED_UNICODE));
    }

    public function testSchedulerDeadLetterResolveFlow(): void
    {
        if (!(self::$db instanceof PDO)) {
            $this->fail('Database handle is not available');
        }

        $runToken = 'integration_dead_letter_' . bin2hex(random_bytes(5));
        $stmt = self::$db->prepare(
            "INSERT INTO scheduler_dead_letters
                (job_name, run_token, attempts, max_attempts, failure_reason, error_text, output_text, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'open', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $stmt->execute([
            'notify-expiry.php',
            $runToken,
            3,
            3,
            'integration_test_dead_letter',
            'integration injected failure',
            'integration output',
        ]);
        $deadLetterId = (int)self::$db->lastInsertId();
        self::$deadLetterIds[] = $deadLetterId;

        $open = self::request(
            'GET',
            '/api/scheduler-dead-letters.php?status=open&limit=30',
            [
                'Accept: application/json',
                'Authorization: Bearer ' . self::$adminToken,
            ]
        );
        $this->assertSame(200, $open['status'], $open['body']);
        $openPayload = json_decode($open['body'], true);
        $this->assertIsArray($openPayload);
        $this->assertTrue((bool)($openPayload['success'] ?? false), $open['body']);

        $openRows = is_array($openPayload['data'] ?? null) ? $openPayload['data'] : [];
        $openIds = array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $openRows);
        $this->assertContains($deadLetterId, $openIds, $open['body']);

        $resolve = self::request('POST', '/api/scheduler-dead-letters.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], [
            'action' => 'resolve',
            'id' => $deadLetterId,
            'note' => 'integration resolved',
        ]);
        $this->assertSame(200, $resolve['status'], $resolve['body']);
        $resolvePayload = json_decode($resolve['body'], true);
        $this->assertIsArray($resolvePayload);
        $this->assertTrue((bool)($resolvePayload['success'] ?? false), $resolve['body']);

        $resolved = self::request(
            'GET',
            '/api/scheduler-dead-letters.php?status=resolved&limit=30',
            [
                'Accept: application/json',
                'Authorization: Bearer ' . self::$adminToken,
            ]
        );
        $this->assertSame(200, $resolved['status'], $resolved['body']);
        $resolvedPayload = json_decode($resolved['body'], true);
        $this->assertIsArray($resolvedPayload);
        $this->assertTrue((bool)($resolvedPayload['success'] ?? false), $resolved['body']);
    }

    public function testMetricsEndpointRequiresManageUsersAndReturnsSnapshot(): void
    {
        $forbidden = self::request(
            'GET',
            '/api/metrics.php',
            [
                'Accept: application/json',
                'Authorization: Bearer ' . self::$operatorToken,
            ]
        );
        $this->assertSame(403, $forbidden['status'], $forbidden['body']);

        $allowed = self::request(
            'GET',
            '/api/metrics.php',
            [
                'Accept: application/json',
                'Authorization: Bearer ' . self::$adminToken,
            ]
        );
        $this->assertSame(200, $allowed['status'], $allowed['body']);
        $allowedPayload = json_decode($allowed['body'], true);
        $this->assertIsArray($allowedPayload);
        $this->assertTrue((bool)($allowedPayload['success'] ?? false), $allowed['body']);

        $data = $allowedPayload['data'] ?? null;
        $this->assertIsArray($data);
        $this->assertArrayHasKey('generated_at', $data);
        $this->assertArrayHasKey('counters', $data);
        $this->assertIsArray($data['counters']);
        $this->assertArrayHasKey('open_dead_letters', $data['counters']);
        $this->assertArrayHasKey('scheduler_failures_24h', $data['counters']);
    }

    public function testAlertsEndpointRequiresManageUsersAndReturnsAlertPayload(): void
    {
        $forbidden = self::request(
            'GET',
            '/api/alerts.php',
            [
                'Accept: application/json',
                'Authorization: Bearer ' . self::$operatorToken,
            ]
        );
        $this->assertSame(403, $forbidden['status'], $forbidden['body']);

        $allowed = self::request(
            'GET',
            '/api/alerts.php',
            [
                'Accept: application/json',
                'Authorization: Bearer ' . self::$adminToken,
            ]
        );
        $this->assertSame(200, $allowed['status'], $allowed['body']);
        $payload = json_decode($allowed['body'], true);
        $this->assertIsArray($payload);
        $this->assertTrue((bool)($payload['success'] ?? false), $allowed['body']);

        $data = $payload['data'] ?? null;
        $this->assertIsArray($data);
        $this->assertArrayHasKey('metrics', $data);
        $this->assertArrayHasKey('alerts', $data);
        $this->assertIsArray($data['metrics']);
        $this->assertIsArray($data['alerts']);
        $this->assertArrayHasKey('summary', $data['alerts']);
    }

    public function testRequestIdHeaderIsReturnedAndEchoedWhenProvided(): void
    {
        $requestId = 'integration_req_' . bin2hex(random_bytes(4));
        $response = self::request(
            'GET',
            '/api/me.php',
            [
                'Accept: application/json',
                'Authorization: Bearer ' . self::$adminToken,
                'X-Request-Id: ' . $requestId,
            ]
        );

        $this->assertSame(200, $response['status'], $response['body']);
        $headerValue = self::findHeaderValue($response['headers'], 'X-Request-Id');
        $this->assertSame($requestId, $headerValue, implode("\n", $response['headers']));

        $payload = json_decode($response['body'], true);
        $this->assertIsArray($payload);
        $this->assertTrue((bool)($payload['success'] ?? false), $response['body']);
    }

    private static function bootstrapUsersAndTokens(): void
    {
        if (!(self::$db instanceof PDO)) {
            throw new RuntimeException('Database handle is not available');
        }

        $adminId = self::$db->query("SELECT id FROM users WHERE username = 'admin' LIMIT 1")->fetchColumn();
        if (!$adminId) {
            $adminId = self::$db->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn();
        }
        if (!$adminId) {
            throw new RuntimeException('No users found in users table for integration token bootstrap');
        }
        self::$adminUserId = (int)$adminId;
        $adminIssued = ApiTokenService::issueToken(self::$adminUserId, 'integration-admin-token', 2, ['*']);
        self::$adminToken = (string)$adminIssued['token'];

        $operator = self::provisionUserForRole('data_entry', 'integration_operator_', 'Integration Operator', 'integration-operator-token');
        self::$operatorUserId = (int)$operator['user_id'];
        self::$operatorUsername = (string)$operator['username'];
        self::$operatorToken = (string)$operator['token'];

        $supervisor = self::provisionUserForRole('supervisor', 'integration_supervisor_', 'Integration Supervisor', 'integration-supervisor-token');
        self::$supervisorUserId = (int)$supervisor['user_id'];
        self::$supervisorToken = (string)$supervisor['token'];

        $approver = self::provisionUserForRole('approver', 'integration_approver_', 'Integration Approver', 'integration-approver-token');
        self::$approverUserId = (int)$approver['user_id'];
        self::$approverToken = (string)$approver['token'];
    }

    /**
     * @return array{user_id:int,username:string,token:string}
     */
    private static function provisionUserForRole(
        string $roleSlug,
        string $usernamePrefix,
        string $fullName,
        string $tokenName
    ): array {
        if (!(self::$db instanceof PDO)) {
            throw new RuntimeException('Database handle is not available');
        }

        $roleId = self::resolveRoleId($roleSlug);
        $username = $usernamePrefix . bin2hex(random_bytes(4));

        $stmt = self::$db->prepare(
            'INSERT INTO users (username, password_hash, full_name, email, role_id, preferred_language, preferred_theme, preferred_direction)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $username,
            password_hash('integration-pass', PASSWORD_DEFAULT),
            $fullName,
            $username . '@local.test',
            $roleId,
            'ar',
            'system',
            'auto',
        ]);

        $userId = (int)self::$db->lastInsertId();
        $issued = ApiTokenService::issueToken($userId, $tokenName, 2, ['*']);

        return [
            'user_id' => $userId,
            'username' => $username,
            'token' => (string)$issued['token'],
        ];
    }

    private static function resolveRoleId(string $roleSlug): int
    {
        if (!(self::$db instanceof PDO)) {
            throw new RuntimeException('Database handle is not available');
        }

        $stmt = self::$db->prepare('SELECT id FROM roles WHERE slug = ? LIMIT 1');
        $stmt->execute([$roleSlug]);
        $roleId = $stmt->fetchColumn();
        if ($roleId) {
            return (int)$roleId;
        }

        $fallback = self::$db->query("SELECT id FROM roles WHERE slug = 'developer' LIMIT 1")->fetchColumn();
        if ($fallback) {
            return (int)$fallback;
        }

        throw new RuntimeException('No role found for integration bootstrap: ' . $roleSlug);
    }

    private static function startServer(): void
    {
        self::$serverPort = self::findAvailablePort(8091, 50);
        self::$baseUrl = 'http://127.0.0.1:' . self::$serverPort;
        self::$serverLogPath = self::$projectRoot . '/storage/logs/integration-http-server.log';

        if (!is_dir(dirname(self::$serverLogPath))) {
            mkdir(dirname(self::$serverLogPath), 0777, true);
        }

        $command = sprintf(
            'php -S 127.0.0.1:%d -t %s',
            self::$serverPort,
            escapeshellarg(self::$projectRoot)
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', self::$serverLogPath, 'a'],
            2 => ['file', self::$serverLogPath, 'a'],
        ];

        self::$serverProcess = proc_open($command, $descriptors, $pipes, self::$projectRoot);
        if (!is_resource(self::$serverProcess)) {
            throw new RuntimeException('Could not start PHP built-in server for integration tests');
        }
        $status = proc_get_status(self::$serverProcess);
        self::$serverPid = is_array($status) && !empty($status['pid']) ? (int)$status['pid'] : 0;

        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        $started = false;
        for ($i = 0; $i < 40; $i++) {
            usleep(150000);
            $probe = self::request('GET', '/views/login.php', ['Accept: text/html']);
            if ($probe['status'] === 200) {
                $started = true;
                break;
            }
        }

        if (!$started) {
            self::stopServer();
            throw new RuntimeException('Integration HTTP server did not start in time');
        }
    }

    private static function stopServer(): void
    {
        if (is_resource(self::$serverProcess)) {
            @proc_terminate(self::$serverProcess);

            for ($i = 0; $i < 20; $i++) {
                $status = proc_get_status(self::$serverProcess);
                if (!is_array($status) || empty($status['running'])) {
                    break;
                }
                usleep(50000);
            }

            $status = proc_get_status(self::$serverProcess);
            if (is_array($status) && !empty($status['running']) && self::$serverPid > 0) {
                if (PHP_OS_FAMILY === 'Windows') {
                    @exec('taskkill /F /T /PID ' . self::$serverPid . ' >NUL 2>&1');
                } elseif (function_exists('posix_kill')) {
                    @posix_kill(self::$serverPid, 9);
                }
            }

            @proc_close(self::$serverProcess);
        }
        self::$serverProcess = null;
        self::$serverPid = 0;
    }

    private static function request(string $method, string $path, array $headers = [], ?array $jsonBody = null): array
    {
        $url = self::$baseUrl . $path;
        $headerLines = $headers;
        $content = null;

        if ($jsonBody !== null) {
            $content = json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $headerLines[] = 'Content-Type: application/json';
        }

        $context = stream_context_create([
            'http' => [
                'method' => strtoupper($method),
                'header' => implode("\r\n", $headerLines),
                'content' => $content,
                'ignore_errors' => true,
                'timeout' => 10,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        $responseHeaders = $http_response_header ?? [];
        $status = self::extractHttpStatus($responseHeaders);

        return [
            'status' => $status,
            'headers' => $responseHeaders,
            'body' => is_string($body) ? $body : '',
        ];
    }

    /**
     * @param array<string,string> $fields
     * @param array{field_name:string,filename:string,content_type:string,content:string} $file
     * @return array{status:int,headers:array<int,string>,body:string}
     */
    private static function requestMultipart(string $path, array $headers, array $fields, array $file): array
    {
        $url = self::$baseUrl . $path;
        $boundary = '----wbgl' . bin2hex(random_bytes(12));

        $body = '';
        foreach ($fields as $name => $value) {
            $body .= '--' . $boundary . "\r\n";
            $body .= 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n";
            $body .= $value . "\r\n";
        }

        $fieldName = $file['field_name'];
        $filename = $file['filename'];
        $contentType = $file['content_type'];
        $fileContent = $file['content'];

        $body .= '--' . $boundary . "\r\n";
        $body .= 'Content-Disposition: form-data; name="' . $fieldName . '"; filename="' . $filename . '"' . "\r\n";
        $body .= 'Content-Type: ' . $contentType . "\r\n\r\n";
        $body .= $fileContent . "\r\n";
        $body .= '--' . $boundary . "--\r\n";

        $headerLines = $headers;
        $headerLines[] = 'Content-Type: multipart/form-data; boundary=' . $boundary;
        $headerLines[] = 'Content-Length: ' . strlen($body);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headerLines),
                'content' => $body,
                'ignore_errors' => true,
                'timeout' => 10,
            ],
        ]);

        $responseBody = @file_get_contents($url, false, $context);
        $responseHeaders = $http_response_header ?? [];
        $status = self::extractHttpStatus($responseHeaders);

        return [
            'status' => $status,
            'headers' => $responseHeaders,
            'body' => is_string($responseBody) ? $responseBody : '',
        ];
    }

    private static function extractHttpStatus(array $headers): int
    {
        if (empty($headers)) {
            return 0;
        }
        $line = (string)$headers[0];
        if (preg_match('/\s(\d{3})\s/', $line, $m) === 1) {
            return (int)$m[1];
        }
        return 0;
    }

    private static function findHeaderValue(array $headers, string $headerName): ?string
    {
        $prefix = strtolower($headerName) . ':';
        foreach ($headers as $line) {
            $raw = trim((string)$line);
            if (strtolower(substr($raw, 0, strlen($prefix))) !== $prefix) {
                continue;
            }
            $value = trim(substr($raw, strlen($prefix)));
            return $value !== '' ? $value : '';
        }
        return null;
    }

    private static function findAvailablePort(int $startPort, int $tries): int
    {
        for ($i = 0; $i < $tries; $i++) {
            $port = $startPort + $i;
            $socket = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
            if (is_resource($socket)) {
                fclose($socket);
                return $port;
            }
        }
        throw new RuntimeException('Could not find free port for integration HTTP server');
    }

    private static function requireGuaranteeId(): int
    {
        if (!(self::$db instanceof PDO)) {
            throw new RuntimeException('Database handle is not available');
        }
        $id = self::$db->query('SELECT id FROM guarantees ORDER BY id ASC LIMIT 1')->fetchColumn();
        if (!$id) {
            throw new RuntimeException('No guarantees found for integration flow');
        }
        return (int)$id;
    }

    private static function requireHistoryId(): int
    {
        if (!(self::$db instanceof PDO)) {
            throw new RuntimeException('Database handle is not available');
        }
        $id = self::$db
            ->query(
                'SELECT gh.id
                 FROM guarantee_history gh
                 JOIN guarantees g ON g.id = gh.guarantee_id
                 ORDER BY gh.id DESC
                 LIMIT 1'
            )
            ->fetchColumn();
        if (!$id) {
            throw new RuntimeException('No history events found for integration flow');
        }
        return (int)$id;
    }

    private static function requireGuaranteeIdWithDecision(): int
    {
        if (!(self::$db instanceof PDO)) {
            throw new RuntimeException('Database handle is not available');
        }
        $id = self::$db
            ->query(
                'SELECT g.id
                 FROM guarantees g
                 JOIN guarantee_decisions d ON d.guarantee_id = g.id
                 ORDER BY g.id ASC
                 LIMIT 1'
            )
            ->fetchColumn();
        if (!$id) {
            throw new RuntimeException('No guarantees with decisions found for undo flow');
        }
        return (int)$id;
    }

    /**
     * @return array{guarantee_id:int,expiry_date:string,new_amount:float}
     */
    private static function createLifecycleFixture(): array
    {
        if (!(self::$db instanceof PDO)) {
            throw new RuntimeException('Database handle is not available');
        }

        $supplierId = (int)(self::$db->query('SELECT id FROM suppliers ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);
        $bankId = (int)(self::$db->query('SELECT id FROM banks ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);
        if ($supplierId <= 0 || $bankId <= 0) {
            throw new RuntimeException('Lifecycle fixture requires at least one supplier and one bank');
        }

        $initialAmount = 15325.75;
        $expiryDate = date('Y-m-d', strtotime('+90 days'));
        $guaranteeNumber = 'INT-LIFECYCLE-' . bin2hex(random_bytes(6));
        $rawData = json_encode([
            'supplier' => 'Integration Fixture Supplier',
            'bank' => 'Integration Fixture Bank',
            'amount' => $initialAmount,
            'contract_number' => 'INT-C-' . bin2hex(random_bytes(3)),
            'expiry_date' => $expiryDate,
            'issue_date' => date('Y-m-d', strtotime('-30 days')),
            'type' => 'Initial',
            'related_to' => 'contract',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $stmt = self::$db->prepare(
            'INSERT INTO guarantees
                (guarantee_number, raw_data, import_source, imported_at, imported_by, normalized_supplier_name, is_test_data)
             VALUES (?, ?, ?, CURRENT_TIMESTAMP, ?, ?, ?)'
        );
        $stmt->execute([
            $guaranteeNumber,
            $rawData,
            'integration_flow',
            'phpunit',
            'integration fixture supplier',
            1,
        ]);
        $guaranteeId = (int)self::$db->lastInsertId();
        self::$fixtureGuaranteeIds[] = $guaranteeId;

        $decision = self::$db->prepare(
            "INSERT INTO guarantee_decisions
                (guarantee_id, status, is_locked, supplier_id, bank_id, decision_source, decided_by, created_at, updated_at, workflow_step, signatures_received)
             VALUES (?, 'ready', ?, ?, ?, 'manual', 'integration_admin', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 'approved', 2)"
        );
        $decision->execute([$guaranteeId, 0, $supplierId, $bankId]);

        return [
            'guarantee_id' => $guaranteeId,
            'expiry_date' => $expiryDate,
            'new_amount' => $initialAmount - 500.25,
        ];
    }

    /**
     * @return array{guarantee_id:int,guarantee_number:string}
     */
    private static function createGetRecordReadOnlyFixture(): array
    {
        if (!(self::$db instanceof PDO)) {
            throw new RuntimeException('Database handle is not available');
        }

        $bankRow = self::$db
            ->query(
                'SELECT b.id AS bank_id, a.alternative_name
                 FROM banks b
                 JOIN bank_alternative_names a ON a.bank_id = b.id
                 ORDER BY a.id ASC
                 LIMIT 1'
            )
            ->fetch(PDO::FETCH_ASSOC);
        if (!is_array($bankRow) || empty($bankRow['bank_id']) || trim((string)($bankRow['alternative_name'] ?? '')) === '') {
            throw new RuntimeException('Read-only fixture requires at least one bank alternative name');
        }

        $adminMetaStmt = self::$db->prepare('SELECT username, full_name FROM users WHERE id = ? LIMIT 1');
        $adminMetaStmt->execute([self::$adminUserId]);
        $adminMeta = $adminMetaStmt->fetch(PDO::FETCH_ASSOC);
        $importedBy = trim((string)($adminMeta['username'] ?? ''));
        if ($importedBy === '') {
            $importedBy = trim((string)($adminMeta['full_name'] ?? ''));
        }
        if ($importedBy === '') {
            $importedBy = 'phpunit';
        }

        $guaranteeNumber = 'INT-GET-RO-' . bin2hex(random_bytes(6));
        $rawData = json_encode([
            'supplier' => 'Read Only Fixture Supplier ' . bin2hex(random_bytes(3)),
            'bank' => (string)$bankRow['alternative_name'],
            'amount' => 9450.25,
            'contract_number' => 'INT-RO-C-' . bin2hex(random_bytes(3)),
            'expiry_date' => date('Y-m-d', strtotime('+120 days')),
            'issue_date' => date('Y-m-d', strtotime('-15 days')),
            'type' => 'Initial',
            'related_to' => 'contract',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $stmt = self::$db->prepare(
            'INSERT INTO guarantees
                (guarantee_number, raw_data, import_source, imported_at, imported_by, normalized_supplier_name, is_test_data)
             VALUES (?, ?, ?, CURRENT_TIMESTAMP, ?, ?, ?)'
        );
        $stmt->execute([
            $guaranteeNumber,
            $rawData,
            'integration_flow',
            $importedBy,
            'read only fixture supplier',
            0,
        ]);

        $guaranteeId = (int)self::$db->lastInsertId();
        self::$fixtureGuaranteeIds[] = $guaranteeId;

        $decidedBy = trim((string)($adminMeta['username'] ?? ''));
        if ($decidedBy === '') {
            $decidedBy = $importedBy;
        }
        $lastModifiedBy = trim((string)($adminMeta['full_name'] ?? ''));
        if ($lastModifiedBy === '') {
            $lastModifiedBy = $decidedBy;
        }

        $decisionStmt = self::$db->prepare(
            "INSERT INTO guarantee_decisions
                (guarantee_id, status, supplier_id, bank_id, decision_source, decided_by, last_modified_by, created_at, updated_at, workflow_step, signatures_received)
             VALUES (?, 'pending', NULL, NULL, 'manual', ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 'draft', 0)"
        );
        $decisionStmt->execute([$guaranteeId, $decidedBy, $lastModifiedBy]);

        return [
            'guarantee_id' => $guaranteeId,
            'guarantee_number' => $guaranteeNumber,
        ];
    }

    private static function indexForGuaranteeInAllFilter(int $guaranteeId): int
    {
        if (!(self::$db instanceof PDO)) {
            throw new RuntimeException('Database handle is not available');
        }

        $conditions = '
            FROM guarantees g
            LEFT JOIN guarantee_decisions d ON d.guarantee_id = g.id
            WHERE g.id <= ?
              AND (d.is_locked IS NULL OR d.is_locked = FALSE)
        ';
        if (\App\Support\Settings::getInstance()->isProductionMode()) {
            $conditions .= ' AND g.is_test_data = 0';
        }

        $stmt = self::$db->prepare('SELECT COUNT(*) ' . $conditions);
        $stmt->execute([$guaranteeId]);
        $index = (int)$stmt->fetchColumn();

        if ($index < 1) {
            throw new RuntimeException('Could not resolve fixture index for get-record read-only test');
        }

        return $index;
    }

    /**
     * @return array<string,mixed>
     */
    private static function rawDataForGuarantee(int $guaranteeId): array
    {
        if (!(self::$db instanceof PDO)) {
            throw new RuntimeException('Database handle is not available');
        }

        $stmt = self::$db->prepare('SELECT raw_data FROM guarantees WHERE id = ? LIMIT 1');
        $stmt->execute([$guaranteeId]);
        $raw = (string)$stmt->fetchColumn();
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function guaranteeNumberForGuarantee(int $guaranteeId): string
    {
        if (!(self::$db instanceof PDO)) {
            throw new RuntimeException('Database handle is not available');
        }

        $stmt = self::$db->prepare('SELECT guarantee_number FROM guarantees WHERE id = ? LIMIT 1');
        $stmt->execute([$guaranteeId]);
        return (string)$stmt->fetchColumn();
    }

    /**
     * @return array<string,mixed>
     */
    private static function decisionSnapshotForGuarantee(int $guaranteeId): array
    {
        if (!(self::$db instanceof PDO)) {
            throw new RuntimeException('Database handle is not available');
        }

        $stmt = self::$db->prepare(
            'SELECT supplier_id, bank_id, status, decision_source
             FROM guarantee_decisions
             WHERE guarantee_id = ?
             LIMIT 1'
        );
        $stmt->execute([$guaranteeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    }

    private static function historyCountForGuarantee(int $guaranteeId): int
    {
        if (!(self::$db instanceof PDO)) {
            throw new RuntimeException('Database handle is not available');
        }

        $stmt = self::$db->prepare('SELECT COUNT(*) FROM guarantee_history WHERE guarantee_id = ?');
        $stmt->execute([$guaranteeId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * @return array{guarantee_id:int,supplier_id:int,supplier_name:string}
     */
    private static function createHiddenWriteFixture(): array
    {
        if (!(self::$db instanceof PDO)) {
            throw new RuntimeException('Database handle is not available');
        }

        $supplier = self::$db
            ->query('SELECT id, official_name FROM suppliers ORDER BY id ASC LIMIT 1')
            ->fetch(PDO::FETCH_ASSOC);
        $bank = self::$db
            ->query('SELECT id, arabic_name FROM banks ORDER BY id ASC LIMIT 1')
            ->fetch(PDO::FETCH_ASSOC);
        if (!is_array($supplier) || !is_array($bank)) {
            throw new RuntimeException('Hidden write fixture requires at least one supplier and one bank');
        }

        $supplierId = (int)($supplier['id'] ?? 0);
        $supplierName = trim((string)($supplier['official_name'] ?? ''));
        $bankId = (int)($bank['id'] ?? 0);
        $bankName = trim((string)($bank['arabic_name'] ?? ''));
        if ($supplierId <= 0 || $supplierName === '' || $bankId <= 0 || $bankName === '') {
            throw new RuntimeException('Hidden write fixture could not resolve supplier/bank baseline');
        }

        $guaranteeNumber = 'INT-HIDDEN-WRITE-' . bin2hex(random_bytes(6));
        $rawData = json_encode([
            'supplier' => 'Hidden Write Fixture Supplier',
            'bank' => $bankName,
            'amount' => 7530.10,
            'contract_number' => 'INT-HIDDEN-' . bin2hex(random_bytes(3)),
            'expiry_date' => date('Y-m-d', strtotime('+75 days')),
            'issue_date' => date('Y-m-d', strtotime('-10 days')),
            'type' => 'Initial',
            'related_to' => 'contract',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $insertGuarantee = self::$db->prepare(
            'INSERT INTO guarantees
                (guarantee_number, raw_data, import_source, imported_at, imported_by, normalized_supplier_name, is_test_data)
             VALUES (?, ?, ?, CURRENT_TIMESTAMP, ?, ?, ?)'
        );
        $insertGuarantee->execute([
            $guaranteeNumber,
            $rawData,
            'integration_flow',
            'hidden_fixture_actor',
            'hidden write fixture supplier',
            0,
        ]);

        $guaranteeId = (int)self::$db->lastInsertId();
        self::$fixtureGuaranteeIds[] = $guaranteeId;

        $insertDecision = self::$db->prepare(
            "INSERT INTO guarantee_decisions
                (guarantee_id, status, is_locked, supplier_id, bank_id, decision_source, decided_by, last_modified_by, created_at, updated_at, workflow_step, signatures_received)
             VALUES (?, 'pending', FALSE, NULL, ?, 'manual', 'hidden_fixture_actor', 'hidden_fixture_actor', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 'draft', 0)"
        );
        $insertDecision->execute([$guaranteeId, $bankId]);

        return [
            'guarantee_id' => $guaranteeId,
            'supplier_id' => $supplierId,
            'supplier_name' => $supplierName,
        ];
    }

    private static function notesCountForGuarantee(int $guaranteeId): int
    {
        if (!(self::$db instanceof PDO)) {
            throw new RuntimeException('Database handle is not available');
        }

        $stmt = self::$db->prepare('SELECT COUNT(*) FROM guarantee_notes WHERE guarantee_id = ?');
        $stmt->execute([$guaranteeId]);
        return (int)$stmt->fetchColumn();
    }

    private static function attachmentsCountForGuarantee(int $guaranteeId): int
    {
        if (!(self::$db instanceof PDO)) {
            throw new RuntimeException('Database handle is not available');
        }

        $stmt = self::$db->prepare('SELECT COUNT(*) FROM guarantee_attachments WHERE guarantee_id = ?');
        $stmt->execute([$guaranteeId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * @return array<string,mixed>
     */
    private static function decisionStateForGuarantee(int $guaranteeId): array
    {
        if (!(self::$db instanceof PDO)) {
            throw new RuntimeException('Database handle is not available');
        }

        $stmt = self::$db->prepare(
            'SELECT status, is_locked, locked_reason, active_action
             FROM guarantee_decisions
             WHERE guarantee_id = ?
             LIMIT 1'
        );
        $stmt->execute([$guaranteeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    private static function historyRowsForGuarantee(int $guaranteeId): array
    {
        if (!(self::$db instanceof PDO)) {
            throw new RuntimeException('Database handle is not available');
        }

        $stmt = self::$db->prepare(
            'SELECT id, event_type, event_subtype, history_version, is_anchor, patch_data, anchor_snapshot
             FROM guarantee_history
             WHERE guarantee_id = ?
             ORDER BY id ASC'
        );
        $stmt->execute([$guaranteeId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    private static function createReleasedGuaranteeFixture(): int
    {
        if (!(self::$db instanceof PDO)) {
            throw new RuntimeException('Database handle is not available');
        }

        $fixture = self::createLifecycleFixture();
        $guaranteeId = (int)$fixture['guarantee_id'];

        $stmt = self::$db->prepare(
            "UPDATE guarantee_decisions
             SET status = 'released',
                 is_locked = TRUE,
                 locked_reason = 'released',
                 last_modified_at = CURRENT_TIMESTAMP,
                 last_modified_by = 'integration_admin'
             WHERE guarantee_id = ?"
        );
        $stmt->execute([$guaranteeId]);

        return $guaranteeId;
    }

    private static function countBreakGlassEventsForTarget(string $actionName, string $targetType, string $targetId): int
    {
        if (!(self::$db instanceof PDO)) {
            throw new RuntimeException('Database handle is not available');
        }

        $stmt = self::$db->prepare(
            'SELECT COUNT(*)
             FROM break_glass_events
             WHERE action_name = ?
               AND target_type = ?
               AND target_id = ?'
        );
        $stmt->execute([$actionName, $targetType, $targetId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function latestBreakGlassEventForTarget(string $actionName, string $targetType, string $targetId): ?array
    {
        if (!(self::$db instanceof PDO)) {
            throw new RuntimeException('Database handle is not available');
        }

        $stmt = self::$db->prepare(
            'SELECT id, reason, ticket_ref, ttl_minutes, created_at
             FROM break_glass_events
             WHERE action_name = ?
               AND target_type = ?
               AND target_id = ?
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute([$actionName, $targetType, $targetId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}
