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

    /** @var int[] */
    private static array $undoRequestIds = [];
    /** @var int[] */
    private static array $deadLetterIds = [];
    /** @var int[] */
    private static array $printGuaranteeIds = [];
    /** @var int[] */
    private static array $fixtureGuaranteeIds = [];

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
                $in = implode(',', array_fill(0, count(self::$fixtureGuaranteeIds), '?'));
                $fixtureIds = array_values(array_unique(self::$fixtureGuaranteeIds));

                $stmt = self::$db->prepare("DELETE FROM undo_requests WHERE guarantee_id IN ({$in})");
                $stmt->execute($fixtureIds);

                $stmt = self::$db->prepare("DELETE FROM guarantee_history WHERE guarantee_id IN ({$in})");
                $stmt->execute($fixtureIds);

                $stmt = self::$db->prepare("DELETE FROM guarantee_decisions WHERE guarantee_id IN ({$in})");
                $stmt->execute($fixtureIds);

                $stmt = self::$db->prepare("DELETE FROM guarantees WHERE id IN ({$in})");
                $stmt->execute($fixtureIds);
            }

            self::$db->exec("DELETE FROM api_access_tokens WHERE token_name LIKE 'integration-%'");

            if (self::$operatorUserId > 0) {
                $stmt = self::$db->prepare('DELETE FROM users WHERE id = ?');
                $stmt->execute([self::$operatorUserId]);
            }
        }

        self::$adminToken = '';
        self::$operatorToken = '';
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
            'Accept: text/html',
            'Authorization: Bearer ' . self::$adminToken,
        ], [
            'guarantee_id' => $guaranteeId,
            'decided_by' => 'integration_admin',
        ]);
        $this->assertSame(200, $extend['status'], $extend['body']);
        $this->assertStringContainsString('record-form-section', $extend['body']);

        $rawAfterExtend = self::rawDataForGuarantee($guaranteeId);
        $expectedExpiry = date('Y-m-d', strtotime($oldExpiry . ' +1 year'));
        $this->assertSame($expectedExpiry, (string)($rawAfterExtend['expiry_date'] ?? ''), json_encode($rawAfterExtend, JSON_UNESCAPED_UNICODE));

        $reduce = self::request('POST', '/api/reduce.php', [
            'Accept: text/html',
            'Authorization: Bearer ' . self::$adminToken,
        ], [
            'guarantee_id' => $guaranteeId,
            'new_amount' => $newAmount,
            'decided_by' => 'integration_admin',
        ]);
        $this->assertSame(200, $reduce['status'], $reduce['body']);
        $this->assertStringContainsString('record-form-section', $reduce['body']);

        $rawAfterReduce = self::rawDataForGuarantee($guaranteeId);
        $this->assertSame((float)$newAmount, (float)($rawAfterReduce['amount'] ?? 0.0), json_encode($rawAfterReduce, JSON_UNESCAPED_UNICODE));

        $release = self::request('POST', '/api/release.php', [
            'Accept: text/html',
            'Authorization: Bearer ' . self::$adminToken,
        ], [
            'guarantee_id' => $guaranteeId,
            'reason' => '[integration] lifecycle release',
            'decided_by' => 'integration_admin',
        ]);
        $this->assertSame(200, $release['status'], $release['body']);
        $this->assertStringContainsString('خطاب الإفراج', $release['body']);

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

        $roleId = self::$db->query("SELECT id FROM roles WHERE slug = 'data_entry' LIMIT 1")->fetchColumn();
        if (!$roleId) {
            $roleId = self::$db->query("SELECT id FROM roles WHERE slug = 'developer' LIMIT 1")->fetchColumn();
        }
        if (!$roleId) {
            throw new RuntimeException('No role found for integration operator bootstrap');
        }

        self::$operatorUsername = 'integration_operator_' . bin2hex(random_bytes(4));
        $stmt = self::$db->prepare(
            'INSERT INTO users (username, password_hash, full_name, email, role_id, preferred_language, preferred_theme, preferred_direction)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            self::$operatorUsername,
            password_hash('integration-pass', PASSWORD_DEFAULT),
            'Integration Operator',
            self::$operatorUsername . '@local.test',
            (int)$roleId,
            'ar',
            'system',
            'auto',
        ]);
        self::$operatorUserId = (int)self::$db->lastInsertId();
        $operatorIssued = ApiTokenService::issueToken(self::$operatorUserId, 'integration-operator-token', 2, ['*']);
        self::$operatorToken = (string)$operatorIssued['token'];
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
}
