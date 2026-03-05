<?php
declare(strict_types=1);

use App\Support\ApiTokenService;
use App\Support\Database;
use App\Services\NotificationService;
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
    /** @var int[] */
    private static array $notificationIds = [];
    /** @var int[] */
    private static array $createdRoleIds = [];
    /** @var int[] */
    private static array $createdUserIds = [];
    /** @var int[] */
    private static array $createdBankIds = [];
    /** @var int[] */
    private static array $createdSupplierIds = [];
    /** @var int[] */
    private static array $learningConfirmationIds = [];
    /** @var int[] */
    private static array $matchingOverrideIds = [];

    public static function setUpBeforeClass(): void
    {
        self::$projectRoot = dirname(__DIR__, 2);
        require_once self::$projectRoot . '/app/Support/autoload.php';

        self::$db = Database::connect();
        self::$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        self::purgeLeakedIntegrationUsers();
        self::quarantineLeakedIntegrationGuarantees();

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

                $stmt = self::$db->prepare("DELETE FROM guarantee_occurrences WHERE guarantee_id IN ({$in})");
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

            if (!empty(self::$notificationIds)) {
                $ids = array_values(array_unique(self::$notificationIds));
                $in = implode(',', array_fill(0, count($ids), '?'));
                $stmt = self::$db->prepare("DELETE FROM notifications WHERE id IN ({$in})");
                $stmt->execute($ids);
            }

            if (!empty(self::$createdRoleIds)) {
                $roleIds = array_values(array_unique(self::$createdRoleIds));
                $in = implode(',', array_fill(0, count($roleIds), '?'));

                $stmt = self::$db->prepare("DELETE FROM role_permissions WHERE role_id IN ({$in})");
                $stmt->execute($roleIds);

                $stmt = self::$db->prepare("DELETE FROM roles WHERE id IN ({$in})");
                $stmt->execute($roleIds);
            }

            if (!empty(self::$createdUserIds)) {
                $userIds = array_values(array_unique(self::$createdUserIds));
                $in = implode(',', array_fill(0, count($userIds), '?'));

                $stmt = self::$db->prepare("DELETE FROM user_permissions WHERE user_id IN ({$in})");
                $stmt->execute($userIds);

                $stmt = self::$db->prepare("DELETE FROM users WHERE id IN ({$in})");
                $stmt->execute($userIds);
            }

            if (!empty(self::$createdBankIds)) {
                $bankIds = array_values(array_unique(self::$createdBankIds));
                $in = implode(',', array_fill(0, count($bankIds), '?'));
                $stmt = self::$db->prepare("DELETE FROM banks WHERE id IN ({$in})");
                $stmt->execute($bankIds);
            }

            if (!empty(self::$createdSupplierIds)) {
                $supplierIds = array_values(array_unique(self::$createdSupplierIds));
                $in = implode(',', array_fill(0, count($supplierIds), '?'));
                $stmt = self::$db->prepare("DELETE FROM suppliers WHERE id IN ({$in})");
                $stmt->execute($supplierIds);
            }

            if (!empty(self::$learningConfirmationIds)) {
                $learningIds = array_values(array_unique(self::$learningConfirmationIds));
                $in = implode(',', array_fill(0, count($learningIds), '?'));
                $stmt = self::$db->prepare("DELETE FROM learning_confirmations WHERE id IN ({$in})");
                $stmt->execute($learningIds);
            }

            if (!empty(self::$matchingOverrideIds)) {
                $overrideIds = array_values(array_unique(self::$matchingOverrideIds));
                $in = implode(',', array_fill(0, count($overrideIds), '?'));
                $stmt = self::$db->prepare("DELETE FROM supplier_overrides WHERE id IN ({$in})");
                $stmt->execute($overrideIds);
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
        $this->assertSame('Unauthorized', (string)($guestPayload['error'] ?? ''), $guest['body']);
        $this->assertTrue(
            !isset($guestPayload['error_type']) || (string)($guestPayload['error_type'] ?? '') === 'permission',
            $guest['body']
        );
        $this->assertNull($guestPayload['data'] ?? null, $guest['body']);
        $this->assertNotSame('', trim((string)($guestPayload['request_id'] ?? '')), $guest['body']);

        $authed = self::request('GET', '/api/me.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ]);
        $this->assertSame(200, $authed['status'], $authed['body']);
        $authedPayload = json_decode($authed['body'], true);
        $this->assertIsArray($authedPayload);
        $this->assertTrue((bool)($authedPayload['success'] ?? false), $authed['body']);
        $this->assertNull($authedPayload['error'] ?? null, $authed['body']);
        $this->assertNotSame('', trim((string)($authedPayload['request_id'] ?? '')), $authed['body']);
        $this->assertIsArray($authedPayload['data'] ?? null, $authed['body']);
        $this->assertSame(
            (int)($authedPayload['user']['id'] ?? 0),
            (int)($authedPayload['data']['user']['id'] ?? 0),
            $authed['body']
        );
        $this->assertSame(self::$adminUserId, (int)($authedPayload['user']['id'] ?? 0));
    }

    public function testLoginAndLogoutUseCompatEnvelope(): void
    {
        $csrfInvalid = self::request('POST', '/api/login.php', [
            'Accept: application/json',
        ], [
            'username' => self::$operatorUsername,
            'password' => 'integration-pass',
        ]);
        $this->assertSame(419, $csrfInvalid['status'], $csrfInvalid['body']);
        $csrfInvalidPayload = json_decode($csrfInvalid['body'], true);
        $this->assertIsArray($csrfInvalidPayload);
        $this->assertSame(false, $csrfInvalidPayload['success'] ?? null, $csrfInvalid['body']);
        $this->assertSame(
            'رمز الطلب غير صالح. يرجى تحديث الصفحة ثم المحاولة.',
            (string)($csrfInvalidPayload['message'] ?? ''),
            $csrfInvalid['body']
        );
        $this->assertSame('validation', (string)($csrfInvalidPayload['error_type'] ?? ''), $csrfInvalid['body']);
        $this->assertNull($csrfInvalidPayload['data'] ?? null, $csrfInvalid['body']);
        $this->assertNotSame('', trim((string)($csrfInvalidPayload['request_id'] ?? '')), $csrfInvalid['body']);

        $validation = self::request('POST', '/api/login.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], []);
        $this->assertSame(400, $validation['status'], $validation['body']);
        $validationPayload = json_decode($validation['body'], true);
        $this->assertIsArray($validationPayload);
        $this->assertSame(false, $validationPayload['success'] ?? null, $validation['body']);
        $this->assertSame('يرجى إدخال اسم المستخدم وكلمة المرور', (string)($validationPayload['message'] ?? ''), $validation['body']);
        $this->assertSame('validation', (string)($validationPayload['error_type'] ?? ''), $validation['body']);
        $this->assertNull($validationPayload['data'] ?? null, $validation['body']);
        $this->assertNotSame('', trim((string)($validationPayload['request_id'] ?? '')), $validation['body']);

        $issuedTokenName = 'integration-login-flow-' . bin2hex(random_bytes(4));
        $login = self::request('POST', '/api/login.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], [
            'username' => self::$operatorUsername,
            'password' => 'integration-pass',
            'issue_token' => true,
            'token_name' => $issuedTokenName,
        ]);
        $this->assertSame(200, $login['status'], $login['body']);
        $loginPayload = json_decode($login['body'], true);
        $this->assertIsArray($loginPayload);
        $this->assertTrue((bool)($loginPayload['success'] ?? false), $login['body']);
        $this->assertNull($loginPayload['error'] ?? null, $login['body']);
        $this->assertNull($loginPayload['error_type'] ?? null, $login['body']);
        $this->assertSame('تم تسجيل الدخول بنجاح', (string)($loginPayload['message'] ?? ''), $login['body']);
        $this->assertNotSame('', trim((string)($loginPayload['request_id'] ?? '')), $login['body']);
        $this->assertIsArray($loginPayload['data'] ?? null, $login['body']);
        $this->assertSame(
            (string)($loginPayload['message'] ?? ''),
            (string)($loginPayload['data']['message'] ?? ''),
            $login['body']
        );
        $this->assertIsArray($loginPayload['user'] ?? null, $login['body']);
        $this->assertSame(self::$operatorUserId, (int)($loginPayload['user']['id'] ?? 0), $login['body']);
        $this->assertSame(
            (string)($loginPayload['access_token'] ?? ''),
            (string)($loginPayload['data']['access_token'] ?? ''),
            $login['body']
        );
        $this->assertNotSame('', trim((string)($loginPayload['access_token'] ?? '')), $login['body']);

        $logoutIssued = ApiTokenService::issueToken(
            self::$adminUserId,
            'integration-logout-flow-' . bin2hex(random_bytes(4)),
            2,
            ['*']
        );
        $logoutToken = (string)($logoutIssued['token'] ?? '');
        $this->assertNotSame('', $logoutToken);

        $logout = self::request('GET', '/api/logout.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . $logoutToken,
        ]);
        $this->assertSame(200, $logout['status'], $logout['body']);
        $logoutPayload = json_decode($logout['body'], true);
        $this->assertIsArray($logoutPayload);
        $this->assertTrue((bool)($logoutPayload['success'] ?? false), $logout['body']);
        $this->assertNull($logoutPayload['error'] ?? null, $logout['body']);
        $this->assertNull($logoutPayload['error_type'] ?? null, $logout['body']);
        $this->assertSame('تم تسجيل الخروج بنجاح', (string)($logoutPayload['message'] ?? ''), $logout['body']);
        $this->assertNotSame('', trim((string)($logoutPayload['request_id'] ?? '')), $logout['body']);
        $this->assertIsArray($logoutPayload['data'] ?? null, $logout['body']);
        $this->assertSame(
            (string)($logoutPayload['message'] ?? ''),
            (string)($logoutPayload['data']['message'] ?? ''),
            $logout['body']
        );

        $revokedTokenAccess = self::request('GET', '/api/me.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . $logoutToken,
        ]);
        $this->assertSame(401, $revokedTokenAccess['status'], $revokedTokenAccess['body']);
        $revokedPayload = json_decode($revokedTokenAccess['body'], true);
        $this->assertIsArray($revokedPayload);
        $this->assertSame(false, $revokedPayload['success'] ?? null, $revokedTokenAccess['body']);
        $this->assertSame('Unauthorized', (string)($revokedPayload['error'] ?? ''), $revokedTokenAccess['body']);
    }

    public function testRolesCrudUsesCompatEnvelopeAndPermissionBoundaries(): void
    {
        $guest = self::request('GET', '/api/roles/create.php', [
            'Accept: application/json',
        ]);
        $this->assertSame(401, $guest['status'], $guest['body']);
        $guestPayload = json_decode($guest['body'], true);
        $this->assertIsArray($guestPayload);
        $this->assertSame(false, $guestPayload['success'] ?? null, $guest['body']);
        $this->assertSame('Unauthorized', (string)($guestPayload['error'] ?? ''), $guest['body']);
        $this->assertTrue(
            !isset($guestPayload['error_type']) || (string)($guestPayload['error_type'] ?? '') === 'permission',
            $guest['body']
        );

        $operatorDenied = self::request('GET', '/api/roles/create.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$operatorToken,
        ]);
        $this->assertSame(403, $operatorDenied['status'], $operatorDenied['body']);
        $operatorDeniedPayload = json_decode($operatorDenied['body'], true);
        $this->assertIsArray($operatorDeniedPayload);
        $this->assertSame(false, $operatorDeniedPayload['success'] ?? null, $operatorDenied['body']);
        $this->assertSame('Permission Denied', (string)($operatorDeniedPayload['error'] ?? ''), $operatorDenied['body']);
        $this->assertTrue(
            !isset($operatorDeniedPayload['error_type']) || (string)($operatorDeniedPayload['error_type'] ?? '') === 'permission',
            $operatorDenied['body']
        );

        $slug = 'integration_role_' . bin2hex(random_bytes(4));
        $create = self::request('POST', '/api/roles/create.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], [
            'name' => 'Integration Role',
            'slug' => $slug,
            'description' => 'Created by EnterpriseApiFlowsTest',
            'permission_ids' => [],
        ]);
        $this->assertSame(200, $create['status'], $create['body']);
        $createPayload = json_decode($create['body'], true);
        $this->assertIsArray($createPayload);
        $this->assertTrue((bool)($createPayload['success'] ?? false), $create['body']);
        $this->assertNull($createPayload['error'] ?? null, $create['body']);
        $this->assertNull($createPayload['error_type'] ?? null, $create['body']);
        $this->assertNotSame('', trim((string)($createPayload['request_id'] ?? '')), $create['body']);
        $this->assertIsArray($createPayload['role'] ?? null, $create['body']);
        $this->assertIsArray($createPayload['data'] ?? null, $create['body']);
        $this->assertSame(
            (int)($createPayload['role']['id'] ?? 0),
            (int)($createPayload['data']['role']['id'] ?? 0),
            $create['body']
        );
        $roleId = (int)($createPayload['role']['id'] ?? 0);
        $this->assertGreaterThan(0, $roleId, $create['body']);
        self::$createdRoleIds[] = $roleId;

        $updatedSlug = $slug . '_u';
        $update = self::request('POST', '/api/roles/update.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], [
            'role_id' => $roleId,
            'name' => 'Integration Role Updated',
            'slug' => $updatedSlug,
            'description' => 'Updated by EnterpriseApiFlowsTest',
            'permission_ids' => [],
        ]);
        $this->assertSame(200, $update['status'], $update['body']);
        $updatePayload = json_decode($update['body'], true);
        $this->assertIsArray($updatePayload);
        $this->assertTrue((bool)($updatePayload['success'] ?? false), $update['body']);
        $this->assertNull($updatePayload['error'] ?? null, $update['body']);
        $this->assertNull($updatePayload['error_type'] ?? null, $update['body']);
        $this->assertSame($roleId, (int)($updatePayload['role']['id'] ?? 0), $update['body']);
        $this->assertSame($updatedSlug, (string)($updatePayload['role']['slug'] ?? ''), $update['body']);

        $delete = self::request('POST', '/api/roles/delete.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], [
            'role_id' => $roleId,
        ]);
        $this->assertSame(200, $delete['status'], $delete['body']);
        $deletePayload = json_decode($delete['body'], true);
        $this->assertIsArray($deletePayload);
        $this->assertTrue((bool)($deletePayload['success'] ?? false), $delete['body']);
        $this->assertNull($deletePayload['error'] ?? null, $delete['body']);
        $this->assertNull($deletePayload['error_type'] ?? null, $delete['body']);
        $this->assertNotSame('', trim((string)($deletePayload['request_id'] ?? '')), $delete['body']);

        self::$createdRoleIds = array_values(array_filter(
            self::$createdRoleIds,
            static fn(int $id): bool => $id !== $roleId
        ));
    }

    public function testUsersCrudUsesCompatEnvelopeAndPermissionBoundaries(): void
    {
        $guest = self::request('GET', '/api/users/create.php', [
            'Accept: application/json',
        ]);
        $this->assertSame(401, $guest['status'], $guest['body']);
        $guestPayload = json_decode($guest['body'], true);
        $this->assertIsArray($guestPayload);
        $this->assertSame(false, $guestPayload['success'] ?? null, $guest['body']);
        $this->assertSame('Unauthorized', (string)($guestPayload['error'] ?? ''), $guest['body']);
        $this->assertTrue(
            !isset($guestPayload['error_type']) || (string)($guestPayload['error_type'] ?? '') === 'permission',
            $guest['body']
        );
        $this->assertNull($guestPayload['data'] ?? null, $guest['body']);
        $this->assertNotSame('', trim((string)($guestPayload['request_id'] ?? '')), $guest['body']);

        $operatorDenied = self::request('GET', '/api/users/create.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$operatorToken,
        ]);
        $this->assertSame(403, $operatorDenied['status'], $operatorDenied['body']);
        $operatorDeniedPayload = json_decode($operatorDenied['body'], true);
        $this->assertIsArray($operatorDeniedPayload);
        $this->assertSame(false, $operatorDeniedPayload['success'] ?? null, $operatorDenied['body']);
        $this->assertSame('Permission Denied', (string)($operatorDeniedPayload['error'] ?? ''), $operatorDenied['body']);
        $this->assertTrue(
            !isset($operatorDeniedPayload['error_type']) || (string)($operatorDeniedPayload['error_type'] ?? '') === 'permission',
            $operatorDenied['body']
        );
        $this->assertNull($operatorDeniedPayload['data'] ?? null, $operatorDenied['body']);
        $this->assertNotSame('', trim((string)($operatorDeniedPayload['request_id'] ?? '')), $operatorDenied['body']);

        $username = 'integration_user_' . bin2hex(random_bytes(4));
        $roleId = self::resolveRoleId('data_entry');
        $create = self::request('POST', '/api/users/create.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], [
            'full_name' => 'Integration User',
            'username' => $username,
            'email' => $username . '@local.test',
            'role_id' => $roleId,
            'password' => 'Integration#Pass2026',
            'preferred_language' => 'ar',
            'preferred_theme' => 'system',
            'preferred_direction' => 'auto',
        ]);
        $this->assertSame(200, $create['status'], $create['body']);
        $createPayload = json_decode($create['body'], true);
        $this->assertIsArray($createPayload);
        $this->assertTrue((bool)($createPayload['success'] ?? false), $create['body']);
        $this->assertNull($createPayload['error'] ?? null, $create['body']);
        $this->assertNull($createPayload['error_type'] ?? null, $create['body']);
        $this->assertNotSame('', trim((string)($createPayload['request_id'] ?? '')), $create['body']);
        $this->assertIsArray($createPayload['data'] ?? null, $create['body']);
        $this->assertSame(
            (string)($createPayload['message'] ?? ''),
            (string)($createPayload['data']['message'] ?? ''),
            $create['body']
        );

        if (!(self::$db instanceof PDO)) {
            $this->fail('Database handle is not available');
        }
        $stmt = self::$db->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $createdUserId = (int)($stmt->fetchColumn() ?: 0);
        $this->assertGreaterThan(0, $createdUserId, $create['body']);
        self::$createdUserIds[] = $createdUserId;

        $updatedUsername = $username . '_u';
        $update = self::request('POST', '/api/users/update.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], [
            'user_id' => $createdUserId,
            'full_name' => 'Integration User Updated',
            'username' => $updatedUsername,
            'email' => $updatedUsername . '@local.test',
            'role_id' => $roleId,
            'preferred_language' => 'en',
            'preferred_theme' => 'light',
            'preferred_direction' => 'ltr',
        ]);
        $this->assertSame(200, $update['status'], $update['body']);
        $updatePayload = json_decode($update['body'], true);
        $this->assertIsArray($updatePayload);
        $this->assertTrue((bool)($updatePayload['success'] ?? false), $update['body']);
        $this->assertNull($updatePayload['error'] ?? null, $update['body']);
        $this->assertNull($updatePayload['error_type'] ?? null, $update['body']);
        $this->assertNotSame('', trim((string)($updatePayload['request_id'] ?? '')), $update['body']);
        $this->assertIsArray($updatePayload['data'] ?? null, $update['body']);
        $this->assertSame(
            (string)($updatePayload['message'] ?? ''),
            (string)($updatePayload['data']['message'] ?? ''),
            $update['body']
        );

        $delete = self::request('POST', '/api/users/delete.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], [
            'user_id' => $createdUserId,
        ]);
        $this->assertSame(200, $delete['status'], $delete['body']);
        $deletePayload = json_decode($delete['body'], true);
        $this->assertIsArray($deletePayload);
        $this->assertTrue((bool)($deletePayload['success'] ?? false), $delete['body']);
        $this->assertNull($deletePayload['error'] ?? null, $delete['body']);
        $this->assertNull($deletePayload['error_type'] ?? null, $delete['body']);
        $this->assertNotSame('', trim((string)($deletePayload['request_id'] ?? '')), $delete['body']);
        $this->assertIsArray($deletePayload['data'] ?? null, $delete['body']);
        $this->assertSame(
            (string)($deletePayload['message'] ?? ''),
            (string)($deletePayload['data']['message'] ?? ''),
            $delete['body']
        );

        self::$createdUserIds = array_values(array_filter(
            self::$createdUserIds,
            static fn(int $id): bool => $id !== $createdUserId
        ));
    }

    public function testBankAndSupplierCrudUsesCompatEnvelopeAndPermissionBoundaries(): void
    {
        $bankForbidden = self::request('GET', '/api/create-bank.php', [
            'Accept: application/json',
        ]);
        $this->assertSame(401, $bankForbidden['status'], $bankForbidden['body']);
        $bankForbiddenPayload = json_decode($bankForbidden['body'], true);
        $this->assertIsArray($bankForbiddenPayload);
        $this->assertSame(false, $bankForbiddenPayload['success'] ?? null, $bankForbidden['body']);
        $this->assertSame('Unauthorized', (string)($bankForbiddenPayload['error'] ?? ''), $bankForbidden['body']);
        $this->assertTrue(
            !isset($bankForbiddenPayload['error_type']) || (string)($bankForbiddenPayload['error_type'] ?? '') === 'permission',
            $bankForbidden['body']
        );

        $supplierForbidden = self::request('GET', '/api/create-supplier.php', [
            'Accept: application/json',
        ]);
        $this->assertSame(401, $supplierForbidden['status'], $supplierForbidden['body']);
        $supplierForbiddenPayload = json_decode($supplierForbidden['body'], true);
        $this->assertIsArray($supplierForbiddenPayload);
        $this->assertSame(false, $supplierForbiddenPayload['success'] ?? null, $supplierForbidden['body']);
        $this->assertSame('Unauthorized', (string)($supplierForbiddenPayload['error'] ?? ''), $supplierForbidden['body']);
        $this->assertTrue(
            !isset($supplierForbiddenPayload['error_type']) || (string)($supplierForbiddenPayload['error_type'] ?? '') === 'permission',
            $supplierForbidden['body']
        );

        $bankSuffix = strtoupper(bin2hex(random_bytes(3)));
        $bankCreate = self::request('POST', '/api/create-bank.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], [
            'arabic_name' => 'بنك تكاملي ' . $bankSuffix,
            'english_name' => 'Integration Bank ' . $bankSuffix,
            'short_name' => 'IB' . $bankSuffix,
            'aliases' => ['INT BANK ' . $bankSuffix],
            'department' => 'Integration',
            'address_line1' => 'Integration Street',
            'contact_email' => 'bank-' . strtolower($bankSuffix) . '@local.test',
        ]);
        $this->assertSame(200, $bankCreate['status'], $bankCreate['body']);
        $bankCreatePayload = json_decode($bankCreate['body'], true);
        $this->assertIsArray($bankCreatePayload);
        $this->assertTrue((bool)($bankCreatePayload['success'] ?? false), $bankCreate['body']);
        $this->assertNull($bankCreatePayload['error'] ?? null, $bankCreate['body']);
        $this->assertNull($bankCreatePayload['error_type'] ?? null, $bankCreate['body']);
        $this->assertNotSame('', trim((string)($bankCreatePayload['request_id'] ?? '')), $bankCreate['body']);
        $this->assertIsArray($bankCreatePayload['data'] ?? null, $bankCreate['body']);
        $bankId = (int)($bankCreatePayload['bank_id'] ?? 0);
        $this->assertGreaterThan(0, $bankId, $bankCreate['body']);
        $this->assertSame($bankId, (int)($bankCreatePayload['data']['bank_id'] ?? 0), $bankCreate['body']);
        self::$createdBankIds[] = $bankId;

        $bankUpdate = self::request('POST', '/api/update_bank.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], [
            'id' => $bankId,
            'arabic_name' => 'بنك تكاملي محدث ' . $bankSuffix,
            'english_name' => 'Integration Bank Updated ' . $bankSuffix,
            'short_name' => 'IB' . $bankSuffix,
            'department' => 'Integration Updated',
            'address_line1' => 'Integration Avenue',
            'contact_email' => 'bank-updated-' . strtolower($bankSuffix) . '@local.test',
        ]);
        $this->assertSame(200, $bankUpdate['status'], $bankUpdate['body']);
        $bankUpdatePayload = json_decode($bankUpdate['body'], true);
        $this->assertIsArray($bankUpdatePayload);
        $this->assertTrue((bool)($bankUpdatePayload['success'] ?? false), $bankUpdate['body']);
        $this->assertNull($bankUpdatePayload['error'] ?? null, $bankUpdate['body']);
        $this->assertNull($bankUpdatePayload['error_type'] ?? null, $bankUpdate['body']);
        $this->assertNotSame('', trim((string)($bankUpdatePayload['request_id'] ?? '')), $bankUpdate['body']);
        $this->assertIsArray($bankUpdatePayload['data'] ?? null, $bankUpdate['body']);
        $this->assertSame(
            (bool)($bankUpdatePayload['updated'] ?? false),
            (bool)($bankUpdatePayload['data']['updated'] ?? false),
            $bankUpdate['body']
        );

        $bankDelete = self::request('POST', '/api/delete_bank.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], [
            'id' => $bankId,
        ]);
        $this->assertSame(200, $bankDelete['status'], $bankDelete['body']);
        $bankDeletePayload = json_decode($bankDelete['body'], true);
        $this->assertIsArray($bankDeletePayload);
        $this->assertTrue((bool)($bankDeletePayload['success'] ?? false), $bankDelete['body']);
        $this->assertNull($bankDeletePayload['error'] ?? null, $bankDelete['body']);
        $this->assertNull($bankDeletePayload['error_type'] ?? null, $bankDelete['body']);
        $this->assertNotSame('', trim((string)($bankDeletePayload['request_id'] ?? '')), $bankDelete['body']);
        self::$createdBankIds = array_values(array_filter(
            self::$createdBankIds,
            static fn(int $id): bool => $id !== $bankId
        ));

        $supplierSuffix = strtoupper(bin2hex(random_bytes(3)));
        $supplierCreate = self::request('POST', '/api/create-supplier.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], [
            'official_name' => 'Integration Supplier ' . $supplierSuffix,
            'english_name' => 'Integration Supplier ' . $supplierSuffix,
            'is_confirmed' => 1,
        ]);
        $this->assertSame(200, $supplierCreate['status'], $supplierCreate['body']);
        $supplierCreatePayload = json_decode($supplierCreate['body'], true);
        $this->assertIsArray($supplierCreatePayload);
        $this->assertTrue((bool)($supplierCreatePayload['success'] ?? false), $supplierCreate['body']);
        $this->assertNull($supplierCreatePayload['error'] ?? null, $supplierCreate['body']);
        $this->assertNull($supplierCreatePayload['error_type'] ?? null, $supplierCreate['body']);
        $this->assertNotSame('', trim((string)($supplierCreatePayload['request_id'] ?? '')), $supplierCreate['body']);
        $this->assertIsArray($supplierCreatePayload['data'] ?? null, $supplierCreate['body']);
        $supplierId = (int)($supplierCreatePayload['supplier_id'] ?? 0);
        $this->assertGreaterThan(0, $supplierId, $supplierCreate['body']);
        $this->assertSame($supplierId, (int)($supplierCreatePayload['data']['supplier_id'] ?? 0), $supplierCreate['body']);
        self::$createdSupplierIds[] = $supplierId;

        $supplierUpdate = self::request('POST', '/api/update_supplier.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], [
            'id' => $supplierId,
            'official_name' => 'Integration Supplier Updated ' . $supplierSuffix,
            'english_name' => 'Integration Supplier Updated ' . $supplierSuffix,
            'is_confirmed' => 1,
        ]);
        $this->assertSame(200, $supplierUpdate['status'], $supplierUpdate['body']);
        $supplierUpdatePayload = json_decode($supplierUpdate['body'], true);
        $this->assertIsArray($supplierUpdatePayload);
        $this->assertTrue((bool)($supplierUpdatePayload['success'] ?? false), $supplierUpdate['body']);
        $this->assertNull($supplierUpdatePayload['error'] ?? null, $supplierUpdate['body']);
        $this->assertNull($supplierUpdatePayload['error_type'] ?? null, $supplierUpdate['body']);
        $this->assertNotSame('', trim((string)($supplierUpdatePayload['request_id'] ?? '')), $supplierUpdate['body']);
        $this->assertIsArray($supplierUpdatePayload['data'] ?? null, $supplierUpdate['body']);
        $this->assertSame(
            (bool)($supplierUpdatePayload['updated'] ?? false),
            (bool)($supplierUpdatePayload['data']['updated'] ?? false),
            $supplierUpdate['body']
        );

        $supplierDelete = self::request('POST', '/api/delete_supplier.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], [
            'id' => $supplierId,
        ]);
        $this->assertSame(200, $supplierDelete['status'], $supplierDelete['body']);
        $supplierDeletePayload = json_decode($supplierDelete['body'], true);
        $this->assertIsArray($supplierDeletePayload);
        $this->assertTrue((bool)($supplierDeletePayload['success'] ?? false), $supplierDelete['body']);
        $this->assertNull($supplierDeletePayload['error'] ?? null, $supplierDelete['body']);
        $this->assertNull($supplierDeletePayload['error_type'] ?? null, $supplierDelete['body']);
        $this->assertNotSame('', trim((string)($supplierDeletePayload['request_id'] ?? '')), $supplierDelete['body']);
        self::$createdSupplierIds = array_values(array_filter(
            self::$createdSupplierIds,
            static fn(int $id): bool => $id !== $supplierId
        ));
    }

    public function testCreateGuaranteeAndConvertToRealUseCompatEnvelope(): void
    {
        $convertGuest = self::request('GET', '/api/convert-to-real.php', [
            'Accept: application/json',
        ]);
        $this->assertSame(401, $convertGuest['status'], $convertGuest['body']);
        $convertGuestPayload = json_decode($convertGuest['body'], true);
        $this->assertIsArray($convertGuestPayload);
        $this->assertSame(false, $convertGuestPayload['success'] ?? null, $convertGuest['body']);
        $this->assertSame('Unauthorized', (string)($convertGuestPayload['error'] ?? ''), $convertGuest['body']);
        $this->assertTrue(
            !isset($convertGuestPayload['error_type']) || (string)($convertGuestPayload['error_type'] ?? '') === 'permission',
            $convertGuest['body']
        );
        $this->assertNull($convertGuestPayload['data'] ?? null, $convertGuest['body']);
        $this->assertNotSame('', trim((string)($convertGuestPayload['request_id'] ?? '')), $convertGuest['body']);

        $unique = strtoupper(bin2hex(random_bytes(4)));
        $guaranteeNumber = 'INT-G-' . $unique;
        $create = self::request('POST', '/api/create-guarantee.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], [
            'guarantee_number' => $guaranteeNumber,
            'supplier' => 'Integration Supplier ' . $unique,
            'bank' => 'Integration Bank ' . $unique,
            'amount' => '12345.67',
            'contract_number' => 'INT-CN-' . $unique,
            'expiry_date' => date('Y-m-d', strtotime('+120 days')),
            'issue_date' => date('Y-m-d', strtotime('-10 days')),
            'type' => 'Initial',
            'comment' => 'integration create guarantee flow',
            'related_to' => 'contract',
        ]);
        $this->assertSame(200, $create['status'], $create['body']);
        $createPayload = json_decode($create['body'], true);
        $this->assertIsArray($createPayload);
        $this->assertTrue((bool)($createPayload['success'] ?? false), $create['body']);
        $this->assertNull($createPayload['error'] ?? null, $create['body']);
        $this->assertNull($createPayload['error_type'] ?? null, $create['body']);
        $this->assertNotSame('', trim((string)($createPayload['request_id'] ?? '')), $create['body']);
        $this->assertIsArray($createPayload['data'] ?? null, $create['body']);
        $guaranteeId = (int)($createPayload['id'] ?? 0);
        $this->assertGreaterThan(0, $guaranteeId, $create['body']);
        $this->assertSame($guaranteeId, (int)($createPayload['data']['id'] ?? 0), $create['body']);
        $this->assertSame(
            (string)($createPayload['message'] ?? ''),
            (string)($createPayload['data']['message'] ?? ''),
            $create['body']
        );
        self::$fixtureGuaranteeIds[] = $guaranteeId;

        if (!(self::$db instanceof PDO)) {
            $this->fail('Database handle is not available');
        }

        $markStmt = self::$db->prepare('UPDATE guarantees SET is_test_data = 1, test_batch_id = ?, test_note = ? WHERE id = ?');
        $markStmt->execute(['integration_convert_batch', 'integration convert flow', $guaranteeId]);

        $missing = self::request('POST', '/api/convert-to-real.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], []);
        $this->assertSame(400, $missing['status'], $missing['body']);
        $missingPayload = json_decode($missing['body'], true);
        $this->assertIsArray($missingPayload);
        $this->assertSame(false, $missingPayload['success'] ?? null, $missing['body']);
        $this->assertSame('Missing guarantee_id', (string)($missingPayload['error'] ?? ''), $missing['body']);
        $this->assertSame('validation', (string)($missingPayload['error_type'] ?? ''), $missing['body']);
        $this->assertNull($missingPayload['data'] ?? null, $missing['body']);
        $this->assertNotSame('', trim((string)($missingPayload['request_id'] ?? '')), $missing['body']);

        $convert = self::request('POST', '/api/convert-to-real.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], [
            'guarantee_id' => $guaranteeId,
        ]);
        $this->assertSame(200, $convert['status'], $convert['body']);
        $convertPayload = json_decode($convert['body'], true);
        $this->assertIsArray($convertPayload);
        $this->assertTrue((bool)($convertPayload['success'] ?? false), $convert['body']);
        $this->assertNull($convertPayload['error'] ?? null, $convert['body']);
        $this->assertNull($convertPayload['error_type'] ?? null, $convert['body']);
        $this->assertNotSame('', trim((string)($convertPayload['request_id'] ?? '')), $convert['body']);
        $this->assertIsArray($convertPayload['data'] ?? null, $convert['body']);
        $this->assertSame(
            (string)($convertPayload['message'] ?? ''),
            (string)($convertPayload['data']['message'] ?? ''),
            $convert['body']
        );

        $verifyStmt = self::$db->prepare('SELECT is_test_data, test_batch_id, test_note FROM guarantees WHERE id = ? LIMIT 1');
        $verifyStmt->execute([$guaranteeId]);
        $verify = $verifyStmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($verify);
        $this->assertSame(0, (int)($verify['is_test_data'] ?? 1), json_encode($verify, JSON_UNESCAPED_UNICODE));
        $this->assertNull($verify['test_batch_id'] ?? null, json_encode($verify, JSON_UNESCAPED_UNICODE));
        $this->assertNull($verify['test_note'] ?? null, json_encode($verify, JSON_UNESCAPED_UNICODE));
    }

    public function testCommitBatchDraftUsesCompatEnvelopeAndPermissionBoundaries(): void
    {
        $forbidden = self::request('GET', '/api/commit-batch-draft.php', [
            'Accept: application/json',
        ]);
        $this->assertSame(401, $forbidden['status'], $forbidden['body']);
        $forbiddenPayload = json_decode($forbidden['body'], true);
        $this->assertIsArray($forbiddenPayload);
        $this->assertSame(false, $forbiddenPayload['success'] ?? null, $forbidden['body']);
        $this->assertSame('Unauthorized', (string)($forbiddenPayload['error'] ?? ''), $forbidden['body']);
        $this->assertTrue(
            !isset($forbiddenPayload['error_type']) || (string)($forbiddenPayload['error_type'] ?? '') === 'permission',
            $forbidden['body']
        );
        $this->assertNull($forbiddenPayload['data'] ?? null, $forbidden['body']);
        $this->assertNotSame('', trim((string)($forbiddenPayload['request_id'] ?? '')), $forbidden['body']);

        $invalid = self::request('POST', '/api/commit-batch-draft.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], [
            'guarantees' => [],
        ]);
        $this->assertSame(400, $invalid['status'], $invalid['body']);
        $invalidPayload = json_decode($invalid['body'], true);
        $this->assertIsArray($invalidPayload);
        $this->assertSame(false, $invalidPayload['success'] ?? null, $invalid['body']);
        $this->assertSame('بيانات غير مكتملة', (string)($invalidPayload['error'] ?? ''), $invalid['body']);
        $this->assertSame('validation', (string)($invalidPayload['error_type'] ?? ''), $invalid['body']);
        $this->assertNull($invalidPayload['data'] ?? null, $invalid['body']);
        $this->assertNotSame('', trim((string)($invalidPayload['request_id'] ?? '')), $invalid['body']);

        if (!(self::$db instanceof PDO)) {
            $this->fail('Database handle is not available');
        }

        $fixture = self::createLifecycleFixture();
        $draftId = (int)$fixture['guarantee_id'];
        $newGuaranteeNumber = 'INT-COMMIT-' . strtoupper(bin2hex(random_bytes(4)));
        $newExpiry = date('Y-m-d', strtotime('+150 days'));

        $commit = self::request('POST', '/api/commit-batch-draft.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], [
            'draft_id' => $draftId,
            'guarantees' => [[
                'guarantee_number' => $newGuaranteeNumber,
                'supplier' => 'Integration Draft Supplier',
                'bank' => 'Integration Draft Bank',
                'amount' => '5555.90',
                'contract_number' => 'INT-COMMIT-CN-' . strtoupper(bin2hex(random_bytes(3))),
                'expiry_date' => $newExpiry,
                'type' => 'INITIAL',
                'comment' => 'integration commit batch draft flow',
            ]],
        ]);
        $this->assertSame(200, $commit['status'], $commit['body']);
        $commitPayload = json_decode($commit['body'], true);
        $this->assertIsArray($commitPayload);
        $this->assertTrue((bool)($commitPayload['success'] ?? false), $commit['body']);
        $this->assertNull($commitPayload['error'] ?? null, $commit['body']);
        $this->assertNull($commitPayload['error_type'] ?? null, $commit['body']);
        $this->assertNotSame('', trim((string)($commitPayload['request_id'] ?? '')), $commit['body']);
        $this->assertIsArray($commitPayload['data'] ?? null, $commit['body']);
        $this->assertSame(
            (string)($commitPayload['message'] ?? ''),
            (string)($commitPayload['data']['message'] ?? ''),
            $commit['body']
        );
        $this->assertSame($draftId, (int)($commitPayload['redirect_id'] ?? 0), $commit['body']);
        $this->assertSame($draftId, (int)($commitPayload['data']['redirect_id'] ?? 0), $commit['body']);

        $rowStmt = self::$db->prepare('SELECT guarantee_number, raw_data FROM guarantees WHERE id = ? LIMIT 1');
        $rowStmt->execute([$draftId]);
        $row = $rowStmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertSame($newGuaranteeNumber, (string)($row['guarantee_number'] ?? ''), json_encode($row, JSON_UNESCAPED_UNICODE));
        $raw = json_decode((string)($row['raw_data'] ?? '{}'), true);
        $this->assertIsArray($raw);
        $this->assertSame($newGuaranteeNumber, (string)($raw['bg_number'] ?? ''), json_encode($raw, JSON_UNESCAPED_UNICODE));
        $this->assertSame($newExpiry, (string)($raw['expiry_date'] ?? ''), json_encode($raw, JSON_UNESCAPED_UNICODE));
    }

    public function testMergeSuppliersUsesCompatEnvelope(): void
    {
        $guest = self::request('GET', '/api/merge-suppliers.php', [
            'Accept: application/json',
        ]);
        $this->assertSame(401, $guest['status'], $guest['body']);
        $guestPayload = json_decode($guest['body'], true);
        $this->assertIsArray($guestPayload);
        $this->assertSame(false, $guestPayload['success'] ?? null, $guest['body']);
        $this->assertSame('Unauthorized', (string)($guestPayload['error'] ?? ''), $guest['body']);
        $this->assertTrue(
            !isset($guestPayload['error_type']) || (string)($guestPayload['error_type'] ?? '') === 'permission',
            $guest['body']
        );
        $this->assertNull($guestPayload['data'] ?? null, $guest['body']);
        $this->assertNotSame('', trim((string)($guestPayload['request_id'] ?? '')), $guest['body']);

        $invalid = self::request('POST', '/api/merge-suppliers.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], []);
        $this->assertSame(400, $invalid['status'], $invalid['body']);
        $invalidPayload = json_decode($invalid['body'], true);
        $this->assertIsArray($invalidPayload);
        $this->assertSame(false, $invalidPayload['success'] ?? null, $invalid['body']);
        $this->assertSame('Ids and Target IDs are required', (string)($invalidPayload['error'] ?? ''), $invalid['body']);
        $this->assertSame('validation', (string)($invalidPayload['error_type'] ?? ''), $invalid['body']);
        $this->assertNull($invalidPayload['data'] ?? null, $invalid['body']);
        $this->assertNotSame('', trim((string)($invalidPayload['request_id'] ?? '')), $invalid['body']);

        $suffix = strtoupper(bin2hex(random_bytes(4)));
        $sourceCreate = self::request('POST', '/api/create-supplier.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], [
            'official_name' => 'Integration Merge Source ' . $suffix,
            'english_name' => 'Integration Merge Source ' . $suffix,
            'is_confirmed' => 1,
        ]);
        $this->assertSame(200, $sourceCreate['status'], $sourceCreate['body']);
        $sourcePayload = json_decode($sourceCreate['body'], true);
        $this->assertIsArray($sourcePayload);
        $sourceId = (int)($sourcePayload['supplier_id'] ?? 0);
        $this->assertGreaterThan(0, $sourceId, $sourceCreate['body']);
        self::$createdSupplierIds[] = $sourceId;

        $targetCreate = self::request('POST', '/api/create-supplier.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], [
            'official_name' => 'Integration Merge Target ' . $suffix,
            'english_name' => 'Integration Merge Target ' . $suffix,
            'is_confirmed' => 1,
        ]);
        $this->assertSame(200, $targetCreate['status'], $targetCreate['body']);
        $targetPayload = json_decode($targetCreate['body'], true);
        $this->assertIsArray($targetPayload);
        $targetId = (int)($targetPayload['supplier_id'] ?? 0);
        $this->assertGreaterThan(0, $targetId, $targetCreate['body']);
        self::$createdSupplierIds[] = $targetId;

        $merge = self::request('POST', '/api/merge-suppliers.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], [
            'source_id' => $sourceId,
            'target_id' => $targetId,
        ]);
        $this->assertSame(200, $merge['status'], $merge['body']);
        $mergePayload = json_decode($merge['body'], true);
        $this->assertIsArray($mergePayload);
        $this->assertTrue((bool)($mergePayload['success'] ?? false), $merge['body']);
        $this->assertNull($mergePayload['error'] ?? null, $merge['body']);
        $this->assertNull($mergePayload['error_type'] ?? null, $merge['body']);
        $this->assertNotSame('', trim((string)($mergePayload['request_id'] ?? '')), $merge['body']);
        $this->assertIsArray($mergePayload['data'] ?? null, $merge['body']);
        $this->assertTrue((bool)($mergePayload['data']['success'] ?? false), $merge['body']);

        if (!(self::$db instanceof PDO)) {
            $this->fail('Database handle is not available');
        }
        $sourceExistsStmt = self::$db->prepare('SELECT id FROM suppliers WHERE id = ? LIMIT 1');
        $sourceExistsStmt->execute([$sourceId]);
        $this->assertFalse((bool)$sourceExistsStmt->fetchColumn(), 'source supplier should be deleted after merge');

        $targetExistsStmt = self::$db->prepare('SELECT id FROM suppliers WHERE id = ? LIMIT 1');
        $targetExistsStmt->execute([$targetId]);
        $this->assertSame($targetId, (int)($targetExistsStmt->fetchColumn() ?: 0), 'target supplier should remain after merge');

        self::$createdSupplierIds = array_values(array_filter(
            self::$createdSupplierIds,
            static fn(int $id): bool => $id !== $sourceId
        ));
    }

    public function testLearningDataAndActionUseCompatEnvelope(): void
    {
        $guest = self::request('GET', '/api/learning-data.php', [
            'Accept: application/json',
        ]);
        $this->assertSame(401, $guest['status'], $guest['body']);
        $guestPayload = json_decode($guest['body'], true);
        $this->assertIsArray($guestPayload);
        $this->assertSame(false, $guestPayload['success'] ?? null, $guest['body']);
        $this->assertSame('Unauthorized', (string)($guestPayload['error'] ?? ''), $guest['body']);
        $this->assertTrue(
            !isset($guestPayload['error_type']) || (string)($guestPayload['error_type'] ?? '') === 'permission',
            $guest['body']
        );
        $this->assertNull($guestPayload['data'] ?? null, $guest['body']);
        $this->assertNotSame('', trim((string)($guestPayload['request_id'] ?? '')), $guest['body']);

        $list = self::request('GET', '/api/learning-data.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ]);
        $this->assertSame(200, $list['status'], $list['body']);
        $listPayload = json_decode($list['body'], true);
        $this->assertIsArray($listPayload);
        $this->assertTrue((bool)($listPayload['success'] ?? false), $list['body']);
        $this->assertNull($listPayload['error'] ?? null, $list['body']);
        $this->assertNull($listPayload['error_type'] ?? null, $list['body']);
        $this->assertNotSame('', trim((string)($listPayload['request_id'] ?? '')), $list['body']);
        $this->assertIsArray($listPayload['data'] ?? null, $list['body']);
        $this->assertIsArray($listPayload['confirmations'] ?? null, $list['body']);
        $this->assertIsArray($listPayload['rejections'] ?? null, $list['body']);
        $this->assertSame(
            count($listPayload['confirmations'] ?? []),
            count($listPayload['data']['confirmations'] ?? []),
            $list['body']
        );

        $methodDenied = self::request('GET', '/api/learning-action.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ]);
        $this->assertSame(405, $methodDenied['status'], $methodDenied['body']);
        $methodDeniedPayload = json_decode($methodDenied['body'], true);
        $this->assertIsArray($methodDeniedPayload);
        $this->assertSame(false, $methodDeniedPayload['success'] ?? null, $methodDenied['body']);
        $this->assertSame('Method not allowed', (string)($methodDeniedPayload['error'] ?? ''), $methodDenied['body']);
        $this->assertSame('validation', (string)($methodDeniedPayload['error_type'] ?? ''), $methodDenied['body']);
        $this->assertNull($methodDeniedPayload['data'] ?? null, $methodDenied['body']);
        $this->assertNotSame('', trim((string)($methodDeniedPayload['request_id'] ?? '')), $methodDenied['body']);

        $invalid = self::request('POST', '/api/learning-action.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], []);
        $this->assertSame(400, $invalid['status'], $invalid['body']);
        $invalidPayload = json_decode($invalid['body'], true);
        $this->assertIsArray($invalidPayload);
        $this->assertSame(false, $invalidPayload['success'] ?? null, $invalid['body']);
        $this->assertSame('Missing parameters', (string)($invalidPayload['error'] ?? ''), $invalid['body']);
        $this->assertSame('validation', (string)($invalidPayload['error_type'] ?? ''), $invalid['body']);
        $this->assertNull($invalidPayload['data'] ?? null, $invalid['body']);
        $this->assertNotSame('', trim((string)($invalidPayload['request_id'] ?? '')), $invalid['body']);

        if (!(self::$db instanceof PDO)) {
            $this->fail('Database handle is not available');
        }

        $supplierId = (int)(self::$db->query('SELECT id FROM suppliers ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);
        $this->assertGreaterThan(0, $supplierId, 'Learning fixture requires at least one supplier');

        $rawName = 'Integration Learning ' . strtoupper(bin2hex(random_bytes(4)));
        $insert = self::$db->prepare(
            'INSERT INTO learning_confirmations
                (raw_supplier_name, normalized_supplier_name, supplier_id, action, created_at, updated_at)
             VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        $insert->execute([
            $rawName,
            strtolower($rawName),
            $supplierId,
            'confirm',
        ]);
        $learningId = (int)self::$db->lastInsertId();
        $this->assertGreaterThan(0, $learningId);
        self::$learningConfirmationIds[] = $learningId;

        $delete = self::request('POST', '/api/learning-action.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], [
            'id' => $learningId,
            'action' => 'delete',
        ]);
        $this->assertSame(200, $delete['status'], $delete['body']);
        $deletePayload = json_decode($delete['body'], true);
        $this->assertIsArray($deletePayload);
        $this->assertTrue((bool)($deletePayload['success'] ?? false), $delete['body']);
        $this->assertNull($deletePayload['error'] ?? null, $delete['body']);
        $this->assertNull($deletePayload['error_type'] ?? null, $delete['body']);
        $this->assertNotSame('', trim((string)($deletePayload['request_id'] ?? '')), $delete['body']);
        $this->assertIsArray($deletePayload['data'] ?? null, $delete['body']);
        $this->assertSame(
            (string)($deletePayload['message'] ?? ''),
            (string)($deletePayload['data']['message'] ?? ''),
            $delete['body']
        );

        $verifyStmt = self::$db->prepare('SELECT id FROM learning_confirmations WHERE id = ? LIMIT 1');
        $verifyStmt->execute([$learningId]);
        $this->assertFalse((bool)$verifyStmt->fetchColumn(), 'learning confirmation row should be deleted');

        self::$learningConfirmationIds = array_values(array_filter(
            self::$learningConfirmationIds,
            static fn(int $id): bool => $id !== $learningId
        ));
    }

    public function testMatchingOverridesUsesCompatEnvelope(): void
    {
        $guest = self::request('GET', '/api/matching-overrides.php?limit=5', [
            'Accept: application/json',
        ]);
        $this->assertSame(401, $guest['status'], $guest['body']);
        $guestPayload = json_decode($guest['body'], true);
        $this->assertIsArray($guestPayload);
        $this->assertSame(false, $guestPayload['success'] ?? null, $guest['body']);
        $this->assertSame('Unauthorized', (string)($guestPayload['error'] ?? ''), $guest['body']);
        $this->assertTrue(
            !isset($guestPayload['error_type']) || (string)($guestPayload['error_type'] ?? '') === 'permission',
            $guest['body']
        );
        $this->assertNull($guestPayload['data'] ?? null, $guest['body']);
        $this->assertNotSame('', trim((string)($guestPayload['request_id'] ?? '')), $guest['body']);

        $list = self::request('GET', '/api/matching-overrides.php?limit=5', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ]);
        $this->assertSame(200, $list['status'], $list['body']);
        $listPayload = json_decode($list['body'], true);
        $this->assertIsArray($listPayload);
        $this->assertTrue((bool)($listPayload['success'] ?? false), $list['body']);
        $this->assertNull($listPayload['error'] ?? null, $list['body']);
        $this->assertNull($listPayload['error_type'] ?? null, $list['body']);
        $this->assertNotSame('', trim((string)($listPayload['request_id'] ?? '')), $list['body']);
        $this->assertIsArray($listPayload['items'] ?? null, $list['body']);
        $this->assertIsArray($listPayload['data'] ?? null, $list['body']);
        $this->assertSame(
            count($listPayload['items'] ?? []),
            count($listPayload['data']['items'] ?? []),
            $list['body']
        );

        $invalid = self::request('POST', '/api/matching-overrides.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], [
            'raw_name' => 'Integration Missing Supplier',
        ]);
        $this->assertSame(400, $invalid['status'], $invalid['body']);
        $invalidPayload = json_decode($invalid['body'], true);
        $this->assertIsArray($invalidPayload);
        $this->assertSame(false, $invalidPayload['success'] ?? null, $invalid['body']);
        $this->assertSame('supplier_id مطلوب', (string)($invalidPayload['error'] ?? ''), $invalid['body']);
        $this->assertSame('validation', (string)($invalidPayload['error_type'] ?? ''), $invalid['body']);
        $this->assertNull($invalidPayload['data'] ?? null, $invalid['body']);
        $this->assertNotSame('', trim((string)($invalidPayload['request_id'] ?? '')), $invalid['body']);

        if (!(self::$db instanceof PDO)) {
            $this->fail('Database handle is not available');
        }

        $supplierId = (int)(self::$db->query('SELECT id FROM suppliers ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);
        $this->assertGreaterThan(0, $supplierId, 'Matching override fixture requires at least one supplier');

        $rawName = 'Integration Override ' . strtoupper(bin2hex(random_bytes(4)));
        $create = self::request('POST', '/api/matching-overrides.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], [
            'raw_name' => $rawName,
            'supplier_id' => $supplierId,
            'reason' => 'integration-test',
            'is_active' => true,
        ]);
        $this->assertSame(200, $create['status'], $create['body']);
        $createPayload = json_decode($create['body'], true);
        $this->assertIsArray($createPayload);
        $this->assertTrue((bool)($createPayload['success'] ?? false), $create['body']);
        $this->assertNull($createPayload['error'] ?? null, $create['body']);
        $this->assertNull($createPayload['error_type'] ?? null, $create['body']);
        $this->assertNotSame('', trim((string)($createPayload['request_id'] ?? '')), $create['body']);
        $this->assertIsArray($createPayload['item'] ?? null, $create['body']);
        $this->assertIsArray($createPayload['data'] ?? null, $create['body']);
        $overrideId = (int)($createPayload['item']['id'] ?? 0);
        $this->assertGreaterThan(0, $overrideId, $create['body']);
        self::$matchingOverrideIds[] = $overrideId;
        $this->assertSame(
            $overrideId,
            (int)($createPayload['data']['item']['id'] ?? 0),
            $create['body']
        );

        $delete = self::request('DELETE', '/api/matching-overrides.php?id=' . $overrideId, [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ]);
        $this->assertSame(200, $delete['status'], $delete['body']);
        $deletePayload = json_decode($delete['body'], true);
        $this->assertIsArray($deletePayload);
        $this->assertTrue((bool)($deletePayload['success'] ?? false), $delete['body']);
        $this->assertNull($deletePayload['error'] ?? null, $delete['body']);
        $this->assertNull($deletePayload['error_type'] ?? null, $delete['body']);
        $this->assertNotSame('', trim((string)($deletePayload['request_id'] ?? '')), $delete['body']);
        $this->assertIsArray($deletePayload['data'] ?? null, $delete['body']);
        $this->assertTrue((bool)($deletePayload['deleted'] ?? false), $delete['body']);
        $this->assertSame(
            (bool)($deletePayload['deleted'] ?? false),
            (bool)($deletePayload['data']['deleted'] ?? false),
            $delete['body']
        );

        $verifyStmt = self::$db->prepare('SELECT id FROM supplier_overrides WHERE id = ? LIMIT 1');
        $verifyStmt->execute([$overrideId]);
        $this->assertFalse((bool)$verifyStmt->fetchColumn(), 'matching override row should be deleted');

        self::$matchingOverrideIds = array_values(array_filter(
            self::$matchingOverrideIds,
            static fn(int $id): bool => $id !== $overrideId
        ));
    }

    public function testImportMatchingOverridesUsesCompatEnvelope(): void
    {
        $guest = self::request('GET', '/api/import_matching_overrides.php', [
            'Accept: application/json',
        ]);
        $this->assertSame(401, $guest['status'], $guest['body']);
        $guestPayload = json_decode($guest['body'], true);
        $this->assertIsArray($guestPayload);
        $this->assertSame(false, $guestPayload['success'] ?? null, $guest['body']);
        $this->assertSame('Unauthorized', (string)($guestPayload['error'] ?? ''), $guest['body']);
        $this->assertTrue(
            !isset($guestPayload['error_type']) || (string)($guestPayload['error_type'] ?? '') === 'permission',
            $guest['body']
        );
        $this->assertNull($guestPayload['data'] ?? null, $guest['body']);
        $this->assertNotSame('', trim((string)($guestPayload['request_id'] ?? '')), $guest['body']);

        $methodDenied = self::request('GET', '/api/import_matching_overrides.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ]);
        $this->assertSame(405, $methodDenied['status'], $methodDenied['body']);
        $methodDeniedPayload = json_decode($methodDenied['body'], true);
        $this->assertIsArray($methodDeniedPayload);
        $this->assertSame(false, $methodDeniedPayload['success'] ?? null, $methodDenied['body']);
        $this->assertSame('Method Not Allowed', (string)($methodDeniedPayload['error'] ?? ''), $methodDenied['body']);
        $this->assertSame('validation', (string)($methodDeniedPayload['error_type'] ?? ''), $methodDenied['body']);
        $this->assertNull($methodDeniedPayload['data'] ?? null, $methodDenied['body']);
        $this->assertNotSame('', trim((string)($methodDeniedPayload['request_id'] ?? '')), $methodDenied['body']);

        $invalid = self::request('POST', '/api/import_matching_overrides.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], []);
        $this->assertSame(400, $invalid['status'], $invalid['body']);
        $invalidPayload = json_decode($invalid['body'], true);
        $this->assertIsArray($invalidPayload);
        $this->assertSame(false, $invalidPayload['success'] ?? null, $invalid['body']);
        $this->assertSame('File upload failed', (string)($invalidPayload['error'] ?? ''), $invalid['body']);
        $this->assertSame('validation', (string)($invalidPayload['error_type'] ?? ''), $invalid['body']);
        $this->assertNull($invalidPayload['data'] ?? null, $invalid['body']);
        $this->assertNotSame('', trim((string)($invalidPayload['request_id'] ?? '')), $invalid['body']);

        if (!(self::$db instanceof PDO)) {
            $this->fail('Database handle is not available');
        }

        $supplierId = (int)(self::$db->query('SELECT id FROM suppliers ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);
        $this->assertGreaterThan(0, $supplierId, 'Matching import fixture requires at least one supplier');

        $rawName = 'Integration Import Override ' . strtoupper(bin2hex(random_bytes(4)));
        $fileRows = [[
            'raw_name' => $rawName,
            'supplier_id' => $supplierId,
            'reason' => 'integration-import',
            'is_active' => 1,
        ]];
        $fileContent = json_encode($fileRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertNotFalse($fileContent);

        $import = self::requestMultipart(
            '/api/import_matching_overrides.php',
            [
                'Accept: application/json',
                'Authorization: Bearer ' . self::$adminToken,
            ],
            [],
            [
                'field_name' => 'file',
                'filename' => 'matching_overrides_integration.json',
                'content_type' => 'application/json',
                'content' => (string)$fileContent,
            ]
        );
        $this->assertSame(200, $import['status'], $import['body']);
        $importPayload = json_decode($import['body'], true);
        $this->assertIsArray($importPayload);
        $this->assertTrue((bool)($importPayload['success'] ?? false), $import['body']);
        $this->assertNull($importPayload['error'] ?? null, $import['body']);
        $this->assertNull($importPayload['error_type'] ?? null, $import['body']);
        $this->assertNotSame('', trim((string)($importPayload['request_id'] ?? '')), $import['body']);
        $this->assertIsArray($importPayload['stats'] ?? null, $import['body']);
        $this->assertIsArray($importPayload['data'] ?? null, $import['body']);
        $this->assertSame(
            (string)($importPayload['message'] ?? ''),
            (string)($importPayload['data']['message'] ?? ''),
            $import['body']
        );
        $this->assertGreaterThanOrEqual(1, (int)($importPayload['stats']['inserted'] ?? 0), $import['body']);

        $verifyStmt = self::$db->prepare('SELECT id FROM supplier_overrides WHERE raw_name = ? ORDER BY id DESC LIMIT 1');
        $verifyStmt->execute([$rawName]);
        $overrideId = (int)($verifyStmt->fetchColumn() ?: 0);
        $this->assertGreaterThan(0, $overrideId, 'imported override should exist in database');
        self::$matchingOverrideIds[] = $overrideId;
    }

    public function testImportBanksUsesCompatEnvelope(): void
    {
        $guest = self::request('GET', '/api/import_banks.php', [
            'Accept: application/json',
        ]);
        $this->assertSame(401, $guest['status'], $guest['body']);
        $guestPayload = json_decode($guest['body'], true);
        $this->assertIsArray($guestPayload);
        $this->assertSame(false, $guestPayload['success'] ?? null, $guest['body']);
        $this->assertSame('Unauthorized', (string)($guestPayload['error'] ?? ''), $guest['body']);
        $this->assertTrue(
            !isset($guestPayload['error_type']) || (string)($guestPayload['error_type'] ?? '') === 'permission',
            $guest['body']
        );
        $this->assertNull($guestPayload['data'] ?? null, $guest['body']);
        $this->assertNotSame('', trim((string)($guestPayload['request_id'] ?? '')), $guest['body']);

        $methodDenied = self::request('GET', '/api/import_banks.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ]);
        $this->assertSame(405, $methodDenied['status'], $methodDenied['body']);
        $methodDeniedPayload = json_decode($methodDenied['body'], true);
        $this->assertIsArray($methodDeniedPayload);
        $this->assertSame(false, $methodDeniedPayload['success'] ?? null, $methodDenied['body']);
        $this->assertSame('Method Not Allowed', (string)($methodDeniedPayload['error'] ?? ''), $methodDenied['body']);
        $this->assertSame('validation', (string)($methodDeniedPayload['error_type'] ?? ''), $methodDenied['body']);
        $this->assertNull($methodDeniedPayload['data'] ?? null, $methodDenied['body']);
        $this->assertNotSame('', trim((string)($methodDeniedPayload['request_id'] ?? '')), $methodDenied['body']);

        $invalid = self::request('POST', '/api/import_banks.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], []);
        $this->assertSame(400, $invalid['status'], $invalid['body']);
        $invalidPayload = json_decode($invalid['body'], true);
        $this->assertIsArray($invalidPayload);
        $this->assertSame(false, $invalidPayload['success'] ?? null, $invalid['body']);
        $this->assertSame('File upload failed', (string)($invalidPayload['error'] ?? ''), $invalid['body']);
        $this->assertSame('validation', (string)($invalidPayload['error_type'] ?? ''), $invalid['body']);
        $this->assertNull($invalidPayload['data'] ?? null, $invalid['body']);
        $this->assertNotSame('', trim((string)($invalidPayload['request_id'] ?? '')), $invalid['body']);

        if (!(self::$db instanceof PDO)) {
            $this->fail('Database handle is not available');
        }

        $suffix = strtoupper(bin2hex(random_bytes(4)));
        $arabicName = 'بنك تكامل ' . $suffix;
        $aliasName = 'تكامل-بنك-' . $suffix;
        $fileRows = [[
            'arabic_name' => $arabicName,
            'english_name' => 'Integration Bank ' . $suffix,
            'short_name' => 'INT' . substr($suffix, 0, 4),
            'department' => 'Integration Ops',
            'address_line1' => 'P.O. Box 1000',
            'contact_email' => 'bank.' . strtolower($suffix) . '@example.test',
            'aliases' => [$aliasName],
        ]];
        $fileContent = json_encode($fileRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertNotFalse($fileContent);

        $import = self::requestMultipart(
            '/api/import_banks.php',
            [
                'Accept: application/json',
                'Authorization: Bearer ' . self::$adminToken,
            ],
            [],
            [
                'field_name' => 'file',
                'filename' => 'banks_integration.json',
                'content_type' => 'application/json',
                'content' => (string)$fileContent,
            ]
        );
        $this->assertSame(200, $import['status'], $import['body']);
        $importPayload = json_decode($import['body'], true);
        $this->assertIsArray($importPayload);
        $this->assertTrue((bool)($importPayload['success'] ?? false), $import['body']);
        $this->assertNull($importPayload['error'] ?? null, $import['body']);
        $this->assertNull($importPayload['error_type'] ?? null, $import['body']);
        $this->assertNotSame('', trim((string)($importPayload['request_id'] ?? '')), $import['body']);
        $this->assertIsArray($importPayload['data'] ?? null, $import['body']);
        $this->assertSame(
            (string)($importPayload['message'] ?? ''),
            (string)($importPayload['data']['message'] ?? ''),
            $import['body']
        );
        $this->assertGreaterThanOrEqual(1, (int)($importPayload['inserted'] ?? 0), $import['body']);
        $this->assertSame(
            (int)($importPayload['inserted'] ?? 0),
            (int)($importPayload['data']['inserted'] ?? -1),
            $import['body']
        );

        $bankStmt = self::$db->prepare('SELECT id FROM banks WHERE arabic_name = ? ORDER BY id DESC LIMIT 1');
        $bankStmt->execute([$arabicName]);
        $bankId = (int)($bankStmt->fetchColumn() ?: 0);
        $this->assertGreaterThan(0, $bankId, 'imported bank should exist in database');
        self::$createdBankIds[] = $bankId;

        $aliasStmt = self::$db->prepare('SELECT alternative_name FROM bank_alternative_names WHERE bank_id = ? AND alternative_name = ? LIMIT 1');
        $aliasStmt->execute([$bankId, $aliasName]);
        $this->assertSame($aliasName, (string)($aliasStmt->fetchColumn() ?: ''), 'imported bank alias should exist');
    }

    public function testImportSuppliersUsesCompatEnvelope(): void
    {
        $guest = self::request('GET', '/api/import_suppliers.php', [
            'Accept: application/json',
        ]);
        $this->assertSame(401, $guest['status'], $guest['body']);
        $guestPayload = json_decode($guest['body'], true);
        $this->assertIsArray($guestPayload);
        $this->assertSame(false, $guestPayload['success'] ?? null, $guest['body']);
        $this->assertSame('Unauthorized', (string)($guestPayload['error'] ?? ''), $guest['body']);
        $this->assertTrue(
            !isset($guestPayload['error_type']) || (string)($guestPayload['error_type'] ?? '') === 'permission',
            $guest['body']
        );
        $this->assertNull($guestPayload['data'] ?? null, $guest['body']);
        $this->assertNotSame('', trim((string)($guestPayload['request_id'] ?? '')), $guest['body']);

        $methodDenied = self::request('GET', '/api/import_suppliers.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ]);
        $this->assertSame(405, $methodDenied['status'], $methodDenied['body']);
        $methodDeniedPayload = json_decode($methodDenied['body'], true);
        $this->assertIsArray($methodDeniedPayload);
        $this->assertSame(false, $methodDeniedPayload['success'] ?? null, $methodDenied['body']);
        $this->assertSame('Method Not Allowed', (string)($methodDeniedPayload['error'] ?? ''), $methodDenied['body']);
        $this->assertSame('validation', (string)($methodDeniedPayload['error_type'] ?? ''), $methodDenied['body']);
        $this->assertNull($methodDeniedPayload['data'] ?? null, $methodDenied['body']);
        $this->assertNotSame('', trim((string)($methodDeniedPayload['request_id'] ?? '')), $methodDenied['body']);

        $invalid = self::request('POST', '/api/import_suppliers.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], []);
        $this->assertSame(400, $invalid['status'], $invalid['body']);
        $invalidPayload = json_decode($invalid['body'], true);
        $this->assertIsArray($invalidPayload);
        $this->assertSame(false, $invalidPayload['success'] ?? null, $invalid['body']);
        $this->assertSame('File upload failed', (string)($invalidPayload['error'] ?? ''), $invalid['body']);
        $this->assertSame('validation', (string)($invalidPayload['error_type'] ?? ''), $invalid['body']);
        $this->assertNull($invalidPayload['data'] ?? null, $invalid['body']);
        $this->assertNotSame('', trim((string)($invalidPayload['request_id'] ?? '')), $invalid['body']);

        if (!(self::$db instanceof PDO)) {
            $this->fail('Database handle is not available');
        }

        $suffix = strtoupper(bin2hex(random_bytes(4)));
        $officialName = 'مورد تكامل ' . $suffix;
        $fileRows = [[
            'official_name' => $officialName,
            'english_name' => 'Integration Supplier ' . $suffix,
            'is_confirmed' => 1,
        ]];
        $fileContent = json_encode($fileRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertNotFalse($fileContent);

        $import = self::requestMultipart(
            '/api/import_suppliers.php',
            [
                'Accept: application/json',
                'Authorization: Bearer ' . self::$adminToken,
            ],
            [],
            [
                'field_name' => 'file',
                'filename' => 'suppliers_integration.json',
                'content_type' => 'application/json',
                'content' => (string)$fileContent,
            ]
        );
        $this->assertSame(200, $import['status'], $import['body']);
        $importPayload = json_decode($import['body'], true);
        $this->assertIsArray($importPayload);
        $this->assertTrue((bool)($importPayload['success'] ?? false), $import['body']);
        $this->assertNull($importPayload['error'] ?? null, $import['body']);
        $this->assertNull($importPayload['error_type'] ?? null, $import['body']);
        $this->assertNotSame('', trim((string)($importPayload['request_id'] ?? '')), $import['body']);
        $this->assertIsArray($importPayload['data'] ?? null, $import['body']);
        $this->assertSame(
            (string)($importPayload['message'] ?? ''),
            (string)($importPayload['data']['message'] ?? ''),
            $import['body']
        );
        $this->assertGreaterThanOrEqual(1, (int)($importPayload['inserted'] ?? 0), $import['body']);
        $this->assertSame(
            (int)($importPayload['inserted'] ?? 0),
            (int)($importPayload['data']['inserted'] ?? -1),
            $import['body']
        );

        $supplierStmt = self::$db->prepare('SELECT id FROM suppliers WHERE official_name = ? ORDER BY id DESC LIMIT 1');
        $supplierStmt->execute([$officialName]);
        $supplierId = (int)($supplierStmt->fetchColumn() ?: 0);
        $this->assertGreaterThan(0, $supplierId, 'imported supplier should exist in database');
        self::$createdSupplierIds[] = $supplierId;
    }

    public function testImportExcelEndpointUsesCompatEnvelope(): void
    {
        $guest = self::request('GET', '/api/import.php', [
            'Accept: application/json',
        ]);
        $this->assertSame(401, $guest['status'], $guest['body']);
        $guestPayload = json_decode($guest['body'], true);
        $this->assertIsArray($guestPayload);
        $this->assertSame(false, $guestPayload['success'] ?? null, $guest['body']);
        $this->assertSame('Unauthorized', (string)($guestPayload['error'] ?? ''), $guest['body']);
        $this->assertTrue(
            !isset($guestPayload['error_type']) || (string)($guestPayload['error_type'] ?? '') === 'permission',
            $guest['body']
        );
        $this->assertNull($guestPayload['data'] ?? null, $guest['body']);
        $this->assertNotSame('', trim((string)($guestPayload['request_id'] ?? '')), $guest['body']);

        $methodDenied = self::request('GET', '/api/import.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ]);
        $this->assertSame(405, $methodDenied['status'], $methodDenied['body']);
        $methodDeniedPayload = json_decode($methodDenied['body'], true);
        $this->assertIsArray($methodDeniedPayload);
        $this->assertSame(false, $methodDeniedPayload['success'] ?? null, $methodDenied['body']);
        $this->assertSame('Method Not Allowed', (string)($methodDeniedPayload['error'] ?? ''), $methodDenied['body']);
        $this->assertSame('validation', (string)($methodDeniedPayload['error_type'] ?? ''), $methodDenied['body']);
        $this->assertNull($methodDeniedPayload['data'] ?? null, $methodDenied['body']);
        $this->assertNotSame('', trim((string)($methodDeniedPayload['request_id'] ?? '')), $methodDenied['body']);

        $missingFile = self::request('POST', '/api/import.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], []);
        $this->assertSame(400, $missingFile['status'], $missingFile['body']);
        $missingFilePayload = json_decode($missingFile['body'], true);
        $this->assertIsArray($missingFilePayload);
        $this->assertSame(false, $missingFilePayload['success'] ?? null, $missingFile['body']);
        $this->assertSame('لم يتم استلام الملف أو حدث خطأ في الرفع', (string)($missingFilePayload['error'] ?? ''), $missingFile['body']);
        $this->assertSame('validation', (string)($missingFilePayload['error_type'] ?? ''), $missingFile['body']);
        $this->assertNull($missingFilePayload['data'] ?? null, $missingFile['body']);
        $this->assertNotSame('', trim((string)($missingFilePayload['request_id'] ?? '')), $missingFile['body']);

        $badExtension = self::requestMultipart(
            '/api/import.php',
            [
                'Accept: application/json',
                'Authorization: Bearer ' . self::$adminToken,
            ],
            [],
            [
                'field_name' => 'file',
                'filename' => 'integration-import.txt',
                'content_type' => 'text/plain',
                'content' => 'integration-test',
            ]
        );
        $this->assertSame(400, $badExtension['status'], $badExtension['body']);
        $badExtensionPayload = json_decode($badExtension['body'], true);
        $this->assertIsArray($badExtensionPayload);
        $this->assertSame(false, $badExtensionPayload['success'] ?? null, $badExtension['body']);
        $this->assertSame('نوع الملف غير مسموح. يجب أن يكون ملف Excel (.xlsx أو .xls)', (string)($badExtensionPayload['error'] ?? ''), $badExtension['body']);
        $this->assertSame('validation', (string)($badExtensionPayload['error_type'] ?? ''), $badExtension['body']);
        $this->assertNull($badExtensionPayload['data'] ?? null, $badExtension['body']);
        $this->assertNotSame('', trim((string)($badExtensionPayload['request_id'] ?? '')), $badExtension['body']);
    }

    public function testImportEmailUsesCompatEnvelope(): void
    {
        $guest = self::request('GET', '/api/import-email.php', [
            'Accept: application/json',
        ]);
        $this->assertSame(401, $guest['status'], $guest['body']);
        $guestPayload = json_decode($guest['body'], true);
        $this->assertIsArray($guestPayload);
        $this->assertSame(false, $guestPayload['success'] ?? null, $guest['body']);
        $this->assertSame('Unauthorized', (string)($guestPayload['error'] ?? ''), $guest['body']);
        $this->assertTrue(
            !isset($guestPayload['error_type']) || (string)($guestPayload['error_type'] ?? '') === 'permission',
            $guest['body']
        );
        $this->assertNull($guestPayload['data'] ?? null, $guest['body']);
        $this->assertNotSame('', trim((string)($guestPayload['request_id'] ?? '')), $guest['body']);

        $methodDenied = self::request('GET', '/api/import-email.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ]);
        $this->assertSame(405, $methodDenied['status'], $methodDenied['body']);
        $methodDeniedPayload = json_decode($methodDenied['body'], true);
        $this->assertIsArray($methodDeniedPayload);
        $this->assertSame(false, $methodDeniedPayload['success'] ?? null, $methodDenied['body']);
        $this->assertSame('Method Not Allowed', (string)($methodDeniedPayload['error'] ?? ''), $methodDenied['body']);
        $this->assertSame('validation', (string)($methodDeniedPayload['error_type'] ?? ''), $methodDenied['body']);
        $this->assertNull($methodDeniedPayload['data'] ?? null, $methodDenied['body']);
        $this->assertNotSame('', trim((string)($methodDeniedPayload['request_id'] ?? '')), $methodDenied['body']);

        $invalidMissingFile = self::request('POST', '/api/import-email.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], []);
        $this->assertSame(400, $invalidMissingFile['status'], $invalidMissingFile['body']);
        $invalidMissingFilePayload = json_decode($invalidMissingFile['body'], true);
        $this->assertIsArray($invalidMissingFilePayload);
        $this->assertSame(false, $invalidMissingFilePayload['success'] ?? null, $invalidMissingFile['body']);
        $this->assertSame('No valid file uploaded', (string)($invalidMissingFilePayload['error'] ?? ''), $invalidMissingFile['body']);
        $this->assertSame('validation', (string)($invalidMissingFilePayload['error_type'] ?? ''), $invalidMissingFile['body']);
        $this->assertNull($invalidMissingFilePayload['data'] ?? null, $invalidMissingFile['body']);
        $this->assertNotSame('', trim((string)($invalidMissingFilePayload['request_id'] ?? '')), $invalidMissingFile['body']);

        $invalidExtension = self::requestMultipart(
            '/api/import-email.php',
            [
                'Accept: application/json',
                'Authorization: Bearer ' . self::$adminToken,
            ],
            [],
            [
                'field_name' => 'email_file',
                'filename' => 'integration-email.txt',
                'content_type' => 'text/plain',
                'content' => 'integration-test',
            ]
        );
        $this->assertSame(400, $invalidExtension['status'], $invalidExtension['body']);
        $invalidExtensionPayload = json_decode($invalidExtension['body'], true);
        $this->assertIsArray($invalidExtensionPayload);
        $this->assertSame(false, $invalidExtensionPayload['success'] ?? null, $invalidExtension['body']);
        $this->assertSame('Only .msg files are supported', (string)($invalidExtensionPayload['error'] ?? ''), $invalidExtension['body']);
        $this->assertSame('validation', (string)($invalidExtensionPayload['error_type'] ?? ''), $invalidExtension['body']);
        $this->assertNull($invalidExtensionPayload['data'] ?? null, $invalidExtension['body']);
        $this->assertNotSame('', trim((string)($invalidExtensionPayload['request_id'] ?? '')), $invalidExtension['body']);
    }

    public function testManualEntryUsesCompatEnvelopeForMethodAndValidation(): void
    {
        $guest = self::request('GET', '/api/manual-entry.php', [
            'Accept: application/json',
        ]);
        $this->assertSame(401, $guest['status'], $guest['body']);
        $guestPayload = json_decode($guest['body'], true);
        $this->assertIsArray($guestPayload);
        $this->assertSame(false, $guestPayload['success'] ?? null, $guest['body']);
        $this->assertSame('Unauthorized', (string)($guestPayload['error'] ?? ''), $guest['body']);
        $this->assertTrue(
            !isset($guestPayload['error_type']) || (string)($guestPayload['error_type'] ?? '') === 'permission',
            $guest['body']
        );
        $this->assertNull($guestPayload['data'] ?? null, $guest['body']);

        $methodDenied = self::request('GET', '/api/manual-entry.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ]);
        $this->assertSame(405, $methodDenied['status'], $methodDenied['body']);
        $methodDeniedPayload = json_decode($methodDenied['body'], true);
        $this->assertIsArray($methodDeniedPayload);
        $this->assertSame(false, $methodDeniedPayload['success'] ?? null, $methodDenied['body']);
        $this->assertSame('Method not allowed', (string)($methodDeniedPayload['error'] ?? ''), $methodDenied['body']);
        $this->assertSame('validation', (string)($methodDeniedPayload['error_type'] ?? ''), $methodDenied['body']);
        $this->assertNull($methodDeniedPayload['data'] ?? null, $methodDenied['body']);
        $this->assertNotSame('', trim((string)($methodDeniedPayload['request_id'] ?? '')), $methodDenied['body']);

        $invalid = self::request('POST', '/api/manual-entry.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], []);
        $this->assertSame(400, $invalid['status'], $invalid['body']);
        $invalidPayload = json_decode($invalid['body'], true);
        $this->assertIsArray($invalidPayload);
        $this->assertSame(false, $invalidPayload['success'] ?? null, $invalid['body']);
        $this->assertSame('بيانات غير صالحة', (string)($invalidPayload['error'] ?? ''), $invalid['body']);
        $this->assertSame('validation', (string)($invalidPayload['error_type'] ?? ''), $invalid['body']);
        $this->assertNull($invalidPayload['data'] ?? null, $invalid['body']);
        $this->assertNotSame('', trim((string)($invalidPayload['request_id'] ?? '')), $invalid['body']);
    }

    public function testSuggestionsLearningUsesCompatEnvelopeForValidation(): void
    {
        $guest = self::request('GET', '/api/suggestions-learning.php', [
            'Accept: application/json',
        ]);
        $this->assertSame(401, $guest['status'], $guest['body']);
        $guestPayload = json_decode($guest['body'], true);
        $this->assertIsArray($guestPayload);
        $this->assertSame(false, $guestPayload['success'] ?? null, $guest['body']);
        $this->assertSame('Unauthorized', (string)($guestPayload['error'] ?? ''), $guest['body']);
        $this->assertTrue(
            !isset($guestPayload['error_type']) || (string)($guestPayload['error_type'] ?? '') === 'permission',
            $guest['body']
        );
        $this->assertNull($guestPayload['data'] ?? null, $guest['body']);

        $missingRaw = self::request('GET', '/api/suggestions-learning.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ]);
        $this->assertSame(400, $missingRaw['status'], $missingRaw['body']);
        $missingRawPayload = json_decode($missingRaw['body'], true);
        $this->assertIsArray($missingRawPayload);
        $this->assertSame(false, $missingRawPayload['success'] ?? null, $missingRaw['body']);
        $this->assertSame('raw parameter required', (string)($missingRawPayload['error'] ?? ''), $missingRaw['body']);
        $this->assertSame('validation', (string)($missingRawPayload['error_type'] ?? ''), $missingRaw['body']);
        $this->assertNull($missingRawPayload['data'] ?? null, $missingRaw['body']);
        $this->assertNotSame('', trim((string)($missingRawPayload['request_id'] ?? '')), $missingRaw['body']);
    }

    public function testExportEndpointsKeepRawPayloadAndRespectAuthBoundaries(): void
    {
        $guestBanks = self::request('GET', '/api/export_banks.php', [
            'Accept: application/json',
        ]);
        $this->assertSame(401, $guestBanks['status'], $guestBanks['body']);
        $guestBanksPayload = json_decode($guestBanks['body'], true);
        $this->assertIsArray($guestBanksPayload);
        $this->assertSame(false, $guestBanksPayload['success'] ?? null, $guestBanks['body']);
        $this->assertSame('Unauthorized', (string)($guestBanksPayload['error'] ?? ''), $guestBanks['body']);
        $this->assertNull($guestBanksPayload['data'] ?? null, $guestBanks['body']);

        $exportBanks = self::request('GET', '/api/export_banks.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ]);
        $this->assertSame(200, $exportBanks['status'], $exportBanks['body']);
        $banksPayload = json_decode($exportBanks['body'], true);
        $this->assertIsArray($banksPayload, $exportBanks['body']);
        $this->assertArrayNotHasKey('success', $banksPayload, 'export banks success payload should remain raw list');

        $guestSuppliers = self::request('GET', '/api/export_suppliers.php', [
            'Accept: application/json',
        ]);
        $this->assertSame(401, $guestSuppliers['status'], $guestSuppliers['body']);
        $guestSuppliersPayload = json_decode($guestSuppliers['body'], true);
        $this->assertIsArray($guestSuppliersPayload);
        $this->assertSame(false, $guestSuppliersPayload['success'] ?? null, $guestSuppliers['body']);
        $this->assertSame('Unauthorized', (string)($guestSuppliersPayload['error'] ?? ''), $guestSuppliers['body']);
        $this->assertNull($guestSuppliersPayload['data'] ?? null, $guestSuppliers['body']);

        $exportSuppliers = self::request('GET', '/api/export_suppliers.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ]);
        $this->assertSame(200, $exportSuppliers['status'], $exportSuppliers['body']);
        $suppliersPayload = json_decode($exportSuppliers['body'], true);
        $this->assertIsArray($suppliersPayload, $exportSuppliers['body']);
        $this->assertArrayNotHasKey('success', $suppliersPayload, 'export suppliers success payload should remain raw list');

        $guestOverrides = self::request('GET', '/api/export_matching_overrides.php', [
            'Accept: application/json',
        ]);
        $this->assertSame(401, $guestOverrides['status'], $guestOverrides['body']);
        $guestOverridesPayload = json_decode($guestOverrides['body'], true);
        $this->assertIsArray($guestOverridesPayload);
        $this->assertSame(false, $guestOverridesPayload['success'] ?? null, $guestOverrides['body']);
        $this->assertSame('Unauthorized', (string)($guestOverridesPayload['error'] ?? ''), $guestOverrides['body']);
        $this->assertNull($guestOverridesPayload['data'] ?? null, $guestOverrides['body']);

        $exportOverrides = self::request('GET', '/api/export_matching_overrides.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ]);
        $this->assertSame(200, $exportOverrides['status'], $exportOverrides['body']);
        $overridesPayload = json_decode($exportOverrides['body'], true);
        $this->assertIsArray($overridesPayload, $exportOverrides['body']);
        $this->assertArrayNotHasKey('success', $overridesPayload, 'export overrides success payload should remain raw list');
    }

    public function testSmartPasteConfidenceUsesCompatEnvelope(): void
    {
        $guest = self::request('GET', '/api/smart-paste-confidence.php', [
            'Accept: application/json',
        ]);
        $this->assertSame(401, $guest['status'], $guest['body']);
        $guestPayload = json_decode($guest['body'], true);
        $this->assertIsArray($guestPayload);
        $this->assertSame(false, $guestPayload['success'] ?? null, $guest['body']);
        $this->assertSame('Unauthorized', (string)($guestPayload['error'] ?? ''), $guest['body']);
        $this->assertTrue(
            !isset($guestPayload['error_type']) || (string)($guestPayload['error_type'] ?? '') === 'permission',
            $guest['body']
        );
        $this->assertNull($guestPayload['data'] ?? null, $guest['body']);
        $this->assertNotSame('', trim((string)($guestPayload['request_id'] ?? '')), $guest['body']);

        $methodDenied = self::request('GET', '/api/smart-paste-confidence.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ]);
        $this->assertSame(405, $methodDenied['status'], $methodDenied['body']);
        $methodDeniedPayload = json_decode($methodDenied['body'], true);
        $this->assertIsArray($methodDeniedPayload);
        $this->assertSame(false, $methodDeniedPayload['success'] ?? null, $methodDenied['body']);
        $this->assertSame('Method not allowed', (string)($methodDeniedPayload['error'] ?? ''), $methodDenied['body']);
        $this->assertSame('validation', (string)($methodDeniedPayload['error_type'] ?? ''), $methodDenied['body']);
        $this->assertNull($methodDeniedPayload['data'] ?? null, $methodDenied['body']);
        $this->assertNotSame('', trim((string)($methodDeniedPayload['request_id'] ?? '')), $methodDenied['body']);

        $invalid = self::request('POST', '/api/smart-paste-confidence.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], []);
        $this->assertSame(400, $invalid['status'], $invalid['body']);
        $invalidPayload = json_decode($invalid['body'], true);
        $this->assertIsArray($invalidPayload);
        $this->assertSame(false, $invalidPayload['success'] ?? null, $invalid['body']);
        $this->assertSame('No text provided', (string)($invalidPayload['error'] ?? ''), $invalid['body']);
        $this->assertSame('validation', (string)($invalidPayload['error_type'] ?? ''), $invalid['body']);
        $this->assertNull($invalidPayload['data'] ?? null, $invalid['body']);
        $this->assertNotSame('', trim((string)($invalidPayload['request_id'] ?? '')), $invalid['body']);

        $ok = self::request('POST', '/api/smart-paste-confidence.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], [
            'text' => 'العميل شركة النهضة يرغب بإصدار ضمان جديد',
        ]);
        $this->assertSame(200, $ok['status'], $ok['body']);
        $okPayload = json_decode($ok['body'], true);
        $this->assertIsArray($okPayload);
        $this->assertTrue((bool)($okPayload['success'] ?? false), $ok['body']);
        $this->assertNull($okPayload['error'] ?? null, $ok['body']);
        $this->assertNull($okPayload['error_type'] ?? null, $ok['body']);
        $this->assertNotSame('', trim((string)($okPayload['request_id'] ?? '')), $ok['body']);
        $this->assertIsArray($okPayload['data'] ?? null, $ok['body']);
        $this->assertIsArray($okPayload['data']['confidence_thresholds'] ?? null, $ok['body']);
        $this->assertArrayHasKey('supplier', $okPayload['data'], $ok['body']);
    }

    public function testParsePasteEndpointsUseCompatEnvelopeForValidationErrors(): void
    {
        $guestLegacy = self::request('GET', '/api/parse-paste.php', [
            'Accept: application/json',
        ]);
        $this->assertSame(401, $guestLegacy['status'], $guestLegacy['body']);
        $guestLegacyPayload = json_decode($guestLegacy['body'], true);
        $this->assertIsArray($guestLegacyPayload);
        $this->assertSame(false, $guestLegacyPayload['success'] ?? null, $guestLegacy['body']);
        $this->assertSame('Unauthorized', (string)($guestLegacyPayload['error'] ?? ''), $guestLegacy['body']);
        $this->assertTrue(
            !isset($guestLegacyPayload['error_type']) || (string)($guestLegacyPayload['error_type'] ?? '') === 'permission',
            $guestLegacy['body']
        );
        $this->assertNull($guestLegacyPayload['data'] ?? null, $guestLegacy['body']);
        $this->assertNotSame('', trim((string)($guestLegacyPayload['request_id'] ?? '')), $guestLegacy['body']);

        $legacyInvalid = self::request('POST', '/api/parse-paste.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], []);
        $this->assertSame(400, $legacyInvalid['status'], $legacyInvalid['body']);
        $legacyInvalidPayload = json_decode($legacyInvalid['body'], true);
        $this->assertIsArray($legacyInvalidPayload);
        $this->assertSame(false, $legacyInvalidPayload['success'] ?? null, $legacyInvalid['body']);
        $this->assertSame('لم يتم إدخال أي نص للتحليل', (string)($legacyInvalidPayload['error'] ?? ''), $legacyInvalid['body']);
        $this->assertSame('validation', (string)($legacyInvalidPayload['error_type'] ?? ''), $legacyInvalid['body']);
        $this->assertNull($legacyInvalidPayload['data'] ?? null, $legacyInvalid['body']);
        $this->assertIsArray($legacyInvalidPayload['extracted'] ?? null, $legacyInvalid['body']);
        $this->assertIsArray($legacyInvalidPayload['field_status'] ?? null, $legacyInvalid['body']);
        $this->assertIsArray($legacyInvalidPayload['confidence'] ?? null, $legacyInvalid['body']);
        $this->assertNotSame('', trim((string)($legacyInvalidPayload['request_id'] ?? '')), $legacyInvalid['body']);

        $guestV2 = self::request('GET', '/api/parse-paste-v2.php', [
            'Accept: application/json',
        ]);
        $this->assertSame(401, $guestV2['status'], $guestV2['body']);
        $guestV2Payload = json_decode($guestV2['body'], true);
        $this->assertIsArray($guestV2Payload);
        $this->assertSame(false, $guestV2Payload['success'] ?? null, $guestV2['body']);
        $this->assertSame('Unauthorized', (string)($guestV2Payload['error'] ?? ''), $guestV2['body']);
        $this->assertTrue(
            !isset($guestV2Payload['error_type']) || (string)($guestV2Payload['error_type'] ?? '') === 'permission',
            $guestV2['body']
        );
        $this->assertNull($guestV2Payload['data'] ?? null, $guestV2['body']);
        $this->assertNotSame('', trim((string)($guestV2Payload['request_id'] ?? '')), $guestV2['body']);

        $v2Invalid = self::request('POST', '/api/parse-paste-v2.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], []);
        $this->assertSame(400, $v2Invalid['status'], $v2Invalid['body']);
        $v2InvalidPayload = json_decode($v2Invalid['body'], true);
        $this->assertIsArray($v2InvalidPayload);
        $this->assertSame(false, $v2InvalidPayload['success'] ?? null, $v2Invalid['body']);
        $this->assertSame('لم يتم إدخال أي نص للتحليل', (string)($v2InvalidPayload['error'] ?? ''), $v2Invalid['body']);
        $this->assertSame('validation', (string)($v2InvalidPayload['error_type'] ?? ''), $v2Invalid['body']);
        $this->assertNull($v2InvalidPayload['data'] ?? null, $v2Invalid['body']);
        $this->assertIsArray($v2InvalidPayload['extracted'] ?? null, $v2Invalid['body']);
        $this->assertIsArray($v2InvalidPayload['field_status'] ?? null, $v2Invalid['body']);
        $this->assertIsArray($v2InvalidPayload['confidence'] ?? null, $v2Invalid['body']);
        $this->assertNotSame('', trim((string)($v2InvalidPayload['request_id'] ?? '')), $v2Invalid['body']);
    }

    public function testHistoryLegacyEndpointUsesCompatEnvelope(): void
    {
        $guest = self::request('GET', '/api/history.php', [
            'Accept: application/json',
        ]);
        $this->assertSame(401, $guest['status'], $guest['body']);
        $guestPayload = json_decode($guest['body'], true);
        $this->assertIsArray($guestPayload);
        $this->assertSame(false, $guestPayload['success'] ?? null, $guest['body']);
        $this->assertSame('Unauthorized', (string)($guestPayload['error'] ?? ''), $guest['body']);
        $this->assertTrue(
            !isset($guestPayload['error_type']) || (string)($guestPayload['error_type'] ?? '') === 'permission',
            $guest['body']
        );
        $this->assertNull($guestPayload['data'] ?? null, $guest['body']);
        $this->assertNotSame('', trim((string)($guestPayload['request_id'] ?? '')), $guest['body']);

        $retired = self::request('GET', '/api/history.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ]);
        $this->assertSame(410, $retired['status'], $retired['body']);
        $retiredPayload = json_decode($retired['body'], true);
        $this->assertIsArray($retiredPayload);
        $this->assertSame(false, $retiredPayload['success'] ?? null, $retired['body']);
        $this->assertSame('api/history.php retired', (string)($retiredPayload['error'] ?? ''), $retired['body']);
        $this->assertSame('validation', (string)($retiredPayload['error_type'] ?? ''), $retired['body']);
        $this->assertNull($retiredPayload['data'] ?? null, $retired['body']);
        $this->assertNotSame('', trim((string)($retiredPayload['request_id'] ?? '')), $retired['body']);
        $this->assertSame('LEGACY_ENDPOINT_RETIRED', (string)($retiredPayload['code'] ?? ''), $retired['body']);
        $this->assertIsArray($retiredPayload['replacement'] ?? null, $retired['body']);
        $this->assertSame('/api/get-timeline.php', (string)($retiredPayload['replacement']['timeline'] ?? ''), $retired['body']);
        $this->assertSame('/api/get-history-snapshot.php', (string)($retiredPayload['replacement']['snapshot'] ?? ''), $retired['body']);
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
        $this->assertNull($postPayload['error'] ?? null, $post['body']);
        $this->assertNotSame('', trim((string)($postPayload['request_id'] ?? '')), $post['body']);
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
        $this->assertNull($listPayload['error'] ?? null, $list['body']);
        $this->assertNotSame('', trim((string)($listPayload['request_id'] ?? '')), $list['body']);

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

    public function testGetCurrentStateUsesCompatEnvelope(): void
    {
        $invalid = self::request(
            'GET',
            '/api/get-current-state.php',
            [
                'Accept: application/json',
                'Authorization: Bearer ' . self::$adminToken,
            ]
        );
        $this->assertSame(400, $invalid['status'], $invalid['body']);
        $invalidPayload = json_decode($invalid['body'], true);
        $this->assertIsArray($invalidPayload);
        $this->assertSame(false, $invalidPayload['success'] ?? null, $invalid['body']);
        $this->assertSame('validation', (string)($invalidPayload['error_type'] ?? ''), $invalid['body']);
        $this->assertNull($invalidPayload['data'] ?? null, $invalid['body']);
        $this->assertNotSame('', trim((string)($invalidPayload['request_id'] ?? '')), $invalid['body']);

        $guaranteeId = self::requireGuaranteeId();
        $valid = self::request(
            'GET',
            '/api/get-current-state.php?id=' . $guaranteeId,
            [
                'Accept: application/json',
                'Authorization: Bearer ' . self::$adminToken,
            ]
        );
        $this->assertSame(200, $valid['status'], $valid['body']);
        $validPayload = json_decode($valid['body'], true);
        $this->assertIsArray($validPayload);
        $this->assertTrue((bool)($validPayload['success'] ?? false), $valid['body']);
        $this->assertNull($validPayload['error'] ?? null, $valid['body']);
        $this->assertNull($validPayload['error_type'] ?? null, $valid['body']);
        $this->assertNotSame('', trim((string)($validPayload['request_id'] ?? '')), $valid['body']);
        $this->assertIsArray($validPayload['data'] ?? null, $valid['body']);
        $this->assertIsArray($validPayload['data']['snapshot'] ?? null, $valid['body']);
        $this->assertSame(
            (string)($validPayload['snapshot']['guarantee_number'] ?? ''),
            (string)($validPayload['data']['snapshot']['guarantee_number'] ?? ''),
            $valid['body']
        );
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
        $this->assertTrue(
            !isset($payload['error_type']) || (string)($payload['error_type'] ?? '') === 'permission',
            $response['body']
        );
        $this->assertNull($payload['data'] ?? null, $response['body']);
        $this->assertNotSame('', trim((string)($payload['request_id'] ?? '')), $response['body']);
        $this->assertTrue(
            !isset($payload['error_type']) || (string)$payload['error_type'] === 'permission',
            $response['body']
        );
        $this->assertNull($payload['data'] ?? null, $response['body']);
        $this->assertNotSame('', trim((string)($payload['request_id'] ?? '')), $response['body']);

        $decisionAfter = self::decisionSnapshotForGuarantee($guaranteeId);
        $historyCountAfter = self::historyCountForGuarantee($guaranteeId);

        $this->assertSame($decisionBefore['supplier_id'] ?? null, $decisionAfter['supplier_id'] ?? null, 'Invisible save-and-next must not mutate supplier_id.');
        $this->assertSame($decisionBefore['bank_id'] ?? null, $decisionAfter['bank_id'] ?? null, 'Invisible save-and-next must not mutate bank_id.');
        $this->assertSame((string)($decisionBefore['status'] ?? ''), (string)($decisionAfter['status'] ?? ''), 'Invisible save-and-next must not mutate status.');
        $this->assertSame((string)($decisionBefore['decision_source'] ?? ''), (string)($decisionAfter['decision_source'] ?? ''), 'Invisible save-and-next must not mutate decision source.');
        $this->assertSame($historyCountBefore, $historyCountAfter, 'Invisible save-and-next must not write timeline events.');
    }

    public function testWorkflowAdvanceMissingGuaranteeIdUsesUnifiedCompatEnvelope(): void
    {
        $response = self::request('POST', '/api/workflow-advance.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], []);

        $this->assertSame(400, $response['status'], $response['body']);
        $payload = json_decode($response['body'], true);
        $this->assertIsArray($payload);
        $this->assertSame(false, $payload['success'] ?? null, $response['body']);
        $this->assertSame('Missing guarantee_id', (string)($payload['error'] ?? ''), $response['body']);
        $this->assertSame('validation', (string)($payload['error_type'] ?? ''), $response['body']);
        $this->assertNull($payload['data'] ?? null, $response['body']);
        $this->assertNotSame('', trim((string)($payload['request_id'] ?? '')), $response['body']);
    }

    public function testUpdateGuaranteeUsesUnifiedCompatEnvelope(): void
    {
        if (!(self::$db instanceof PDO)) {
            $this->fail('Database handle is not available');
        }

        $fixture = self::createLifecycleFixture();
        $guaranteeId = (int)$fixture['guarantee_id'];
        $guaranteeNumber = self::guaranteeNumberForGuarantee($guaranteeId);
        $raw = self::rawDataForGuarantee($guaranteeId);

        $response = self::request('POST', '/api/update-guarantee.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], [
            'guarantee_id' => $guaranteeId,
            'guarantee_number' => $guaranteeNumber,
            'supplier' => (string)($raw['supplier'] ?? 'Integration Fixture Supplier'),
            'bank' => (string)($raw['bank'] ?? 'Integration Fixture Bank'),
            'amount' => (string)($raw['amount'] ?? '15325.75'),
            'contract_number' => (string)($raw['contract_number'] ?? 'INT-CONTRACT-DEFAULT'),
            'expiry_date' => (string)($raw['expiry_date'] ?? date('Y-m-d', strtotime('+90 days'))),
            'issue_date' => (string)($raw['issue_date'] ?? date('Y-m-d', strtotime('-30 days'))),
            'type' => (string)($raw['type'] ?? 'Initial'),
            'related_to' => (string)($raw['related_to'] ?? 'contract'),
            'comment' => '[integration] compat envelope update',
        ]);

        $this->assertSame(200, $response['status'], $response['body']);
        $payload = json_decode($response['body'], true);
        $this->assertNotNull($payload, $response['body']);
        $this->assertIsArray($payload);
        $this->assertTrue((bool)($payload['success'] ?? false), $response['body']);
        $this->assertNull($payload['error'] ?? null, $response['body']);
        $this->assertNotSame('', trim((string)($payload['request_id'] ?? '')), $response['body']);
        $this->assertIsArray($payload['data'] ?? null, $response['body']);
        $this->assertSame((string)($payload['message'] ?? ''), (string)($payload['data']['message'] ?? ''), $response['body']);
        $this->assertArrayHasKey('break_glass', $payload);
        $this->assertSame($payload['break_glass'] ?? null, $payload['data']['break_glass'] ?? null, $response['body']);
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
        $this->assertTrue(
            !isset($payload['error_type']) || (string)($payload['error_type'] ?? '') === 'permission',
            $response['body']
        );
        $this->assertNull($payload['data'] ?? null, $response['body']);
        $this->assertNotSame('', trim((string)($payload['request_id'] ?? '')), $response['body']);

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
        $this->assertNull($submitPayload['error'] ?? null, $submit['body']);
        $this->assertIsArray($submitPayload['data'] ?? null, $submit['body']);
        $requestId = (int)($submitPayload['request_id'] ?? 0);
        $this->assertGreaterThan(0, $requestId, $submit['body']);
        $this->assertSame($requestId, (int)($submitPayload['data']['request_id'] ?? 0), $submit['body']);
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
        $this->assertSame('validation', (string)($selfApprovePayload['error_type'] ?? ''), $selfApprove['body']);
        $this->assertNull($selfApprovePayload['data'] ?? null, $selfApprove['body']);
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
        $this->assertNull($approvePayload['error'] ?? null, $approve['body']);
        $this->assertIsArray($approvePayload['data'] ?? null, $approve['body']);

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
        $this->assertNull($listPayload['error'] ?? null, $list['body']);
        $this->assertNotSame('', trim((string)($listPayload['request_id'] ?? '')), $list['body']);

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
        $extendDecision = self::decisionStateForGuarantee($guaranteeId);
        $this->assertSame('extension', (string)($extendDecision['active_action'] ?? ''), json_encode($extendDecision, JSON_UNESCAPED_UNICODE));
        $this->assertSame('draft', (string)($extendDecision['workflow_step'] ?? ''), json_encode($extendDecision, JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, (int)($extendDecision['signatures_received'] ?? -1), json_encode($extendDecision, JSON_UNESCAPED_UNICODE));

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
        $reduceDecision = self::decisionStateForGuarantee($guaranteeId);
        $this->assertSame('reduction', (string)($reduceDecision['active_action'] ?? ''), json_encode($reduceDecision, JSON_UNESCAPED_UNICODE));
        $this->assertSame('draft', (string)($reduceDecision['workflow_step'] ?? ''), json_encode($reduceDecision, JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, (int)($reduceDecision['signatures_received'] ?? -1), json_encode($reduceDecision, JSON_UNESCAPED_UNICODE));

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

    public function testRoleBasedWorkflowChainFromDataEntryToSigned(): void
    {
        if (!(self::$db instanceof PDO)) {
            $this->fail('Database handle is not available');
        }

        $fixture = self::createLifecycleFixture();
        $guaranteeId = (int)$fixture['guarantee_id'];

        $auditor = self::provisionUserForRole('data_auditor', 'integration_auditor_', 'Integration Auditor', 'integration-auditor-token');
        $analyst = self::provisionUserForRole('analyst', 'integration_analyst_', 'Integration Analyst', 'integration-analyst-token');
        $signatory = self::provisionUserForRole('signatory', 'integration_signatory_', 'Integration Signatory', 'integration-signatory-token');

        $startAction = self::request('POST', '/api/extend.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$operatorToken, // data_entry
        ], [
            'guarantee_id' => $guaranteeId,
            'decided_by' => self::$operatorUsername !== '' ? self::$operatorUsername : 'integration_operator',
        ]);
        $this->assertSame(200, $startAction['status'], $startAction['body']);
        $startPayload = json_decode($startAction['body'], true);
        $this->assertIsArray($startPayload);
        $this->assertTrue((bool)($startPayload['success'] ?? false), $startAction['body']);

        $decision = self::decisionStateForGuarantee($guaranteeId);
        $this->assertSame('ready', (string)($decision['status'] ?? ''));
        $this->assertSame('extension', (string)($decision['active_action'] ?? ''));
        $this->assertSame('draft', (string)($decision['workflow_step'] ?? ''));
        $this->assertSame(0, (int)($decision['signatures_received'] ?? -1));

        $auditorAdvance = self::request('POST', '/api/workflow-advance.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . (string)$auditor['token'],
        ], ['guarantee_id' => $guaranteeId]);
        $this->assertSame(200, $auditorAdvance['status'], $auditorAdvance['body']);
        $auditorPayload = json_decode($auditorAdvance['body'], true);
        $this->assertTrue((bool)($auditorPayload['success'] ?? false), $auditorAdvance['body']);
        $this->assertSame('audited', (string)($auditorPayload['data']['workflow_step'] ?? ''), $auditorAdvance['body']);

        $analystAdvance = self::request('POST', '/api/workflow-advance.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . (string)$analyst['token'],
        ], ['guarantee_id' => $guaranteeId]);
        $this->assertSame(200, $analystAdvance['status'], $analystAdvance['body']);
        $analystPayload = json_decode($analystAdvance['body'], true);
        $this->assertTrue((bool)($analystPayload['success'] ?? false), $analystAdvance['body']);
        $this->assertSame('analyzed', (string)($analystPayload['data']['workflow_step'] ?? ''), $analystAdvance['body']);

        $supervisorAdvance = self::request('POST', '/api/workflow-advance.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$supervisorToken,
        ], ['guarantee_id' => $guaranteeId]);
        $this->assertSame(200, $supervisorAdvance['status'], $supervisorAdvance['body']);
        $supervisorPayload = json_decode($supervisorAdvance['body'], true);
        $this->assertTrue((bool)($supervisorPayload['success'] ?? false), $supervisorAdvance['body']);
        $this->assertSame('supervised', (string)($supervisorPayload['data']['workflow_step'] ?? ''), $supervisorAdvance['body']);

        $approverAdvance = self::request('POST', '/api/workflow-advance.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$approverToken,
        ], ['guarantee_id' => $guaranteeId]);
        $this->assertSame(200, $approverAdvance['status'], $approverAdvance['body']);
        $approverPayload = json_decode($approverAdvance['body'], true);
        $this->assertTrue((bool)($approverPayload['success'] ?? false), $approverAdvance['body']);
        $this->assertSame('approved', (string)($approverPayload['data']['workflow_step'] ?? ''), $approverAdvance['body']);

        $signatoryAdvance = self::request('POST', '/api/workflow-advance.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . (string)$signatory['token'],
        ], ['guarantee_id' => $guaranteeId]);
        $this->assertSame(200, $signatoryAdvance['status'], $signatoryAdvance['body']);
        $signatoryPayload = json_decode($signatoryAdvance['body'], true);
        $this->assertTrue((bool)($signatoryPayload['success'] ?? false), $signatoryAdvance['body']);
        $this->assertSame('signed', (string)($signatoryPayload['data']['workflow_step'] ?? ''), $signatoryAdvance['body']);

        $finalDecision = self::decisionStateForGuarantee($guaranteeId);
        $this->assertSame('ready', (string)($finalDecision['status'] ?? ''));
        $this->assertSame('extension', (string)($finalDecision['active_action'] ?? ''));
        $this->assertSame('signed', (string)($finalDecision['workflow_step'] ?? ''));
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
        $this->assertNull($validPayload['error_type'] ?? null, $valid['body']);
        $this->assertNotSame('', trim((string)($validPayload['request_id'] ?? '')), $valid['body']);
        $this->assertSame(0, (int)($validPayload['data']['saved_count'] ?? -1), $valid['body']);
        $this->assertSame((int)($validPayload['saved_count'] ?? -1), (int)($validPayload['data']['saved_count'] ?? -2), $valid['body']);

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
        $this->assertSame('validation', (string)($invalidPayload['error_type'] ?? ''), $invalid['body']);
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
        $this->assertNotSame('', trim((string)($payload['request_id'] ?? '')), $response['body']);
        $this->assertNotSame('break_glass_direct', (string)($payload['mode'] ?? ''));
        $this->assertIsArray($payload['data'] ?? null, $response['body']);
        $this->assertSame((string)($payload['mode'] ?? ''), (string)($payload['data']['mode'] ?? ''), $response['body']);

        if ((string)($payload['mode'] ?? '') === 'undo_request') {
            $requestId = (int)($payload['request_id'] ?? 0);
            $this->assertGreaterThan(0, $requestId, $response['body']);
            $this->assertSame($requestId, (int)($payload['data']['request_id'] ?? 0), $response['body']);
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
        $this->assertSame('validation', (string)($withoutTicketPayload['error_type'] ?? ''), $withoutTicket['body']);
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
        $this->assertIsArray($withTicketPayload['data'] ?? null, $withTicket['body']);
        $this->assertSame((string)($withTicketPayload['mode'] ?? ''), (string)($withTicketPayload['data']['mode'] ?? ''), $withTicket['body']);

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
        self::grantUserPermissionBySlug(self::$supervisorUserId, 'batch_full_operations_override');

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
        $this->assertNull($payload['error'] ?? null, $response['body']);
        $this->assertNotSame('', trim((string)($payload['request_id'] ?? '')), $response['body']);
        $this->assertIsArray($payload['data'] ?? null, $response['body']);
        $this->assertTrue((bool)($payload['data']['success'] ?? false), $response['body']);

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
            'integration-job',
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
        $this->assertNull($openPayload['error'] ?? null, $open['body']);
        $this->assertNotSame('', trim((string)($openPayload['request_id'] ?? '')), $open['body']);

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
        $this->assertNull($resolvePayload['error'] ?? null, $resolve['body']);
        $this->assertNotSame('', trim((string)($resolvePayload['request_id'] ?? '')), $resolve['body']);
        $this->assertIsArray($resolvePayload['data'] ?? null, $resolve['body']);

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
        $this->assertNull($resolvedPayload['error'] ?? null, $resolved['body']);
        $this->assertNotSame('', trim((string)($resolvedPayload['request_id'] ?? '')), $resolved['body']);
    }

    public function testNotificationsInboxListAndMarkReadUseCompatEnvelope(): void
    {
        $notificationId = NotificationService::create(
            'integration_test',
            'Integration Notification',
            'Notification created by EnterpriseApiFlowsTest',
            null,
            ['suite' => 'EnterpriseApiFlowsTest']
        );
        self::$notificationIds[] = $notificationId;

        $list = self::request(
            'GET',
            '/api/notifications.php?unread=1&limit=30',
            [
                'Accept: application/json',
                'Authorization: Bearer ' . self::$adminToken,
            ]
        );
        $this->assertSame(200, $list['status'], $list['body']);
        $listPayload = json_decode($list['body'], true);
        $this->assertIsArray($listPayload);
        $this->assertTrue((bool)($listPayload['success'] ?? false), $list['body']);
        $this->assertNull($listPayload['error'] ?? null, $list['body']);
        $this->assertNotSame('', trim((string)($listPayload['request_id'] ?? '')), $list['body']);

        $rows = is_array($listPayload['data'] ?? null) ? $listPayload['data'] : [];
        $rowIds = array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $rows);
        $this->assertContains($notificationId, $rowIds, $list['body']);

        $markRead = self::request('POST', '/api/notifications.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], [
            'action' => 'mark_read',
            'notification_id' => $notificationId,
        ]);
        $this->assertSame(200, $markRead['status'], $markRead['body']);
        $markReadPayload = json_decode($markRead['body'], true);
        $this->assertIsArray($markReadPayload);
        $this->assertTrue((bool)($markReadPayload['success'] ?? false), $markRead['body']);
        $this->assertNull($markReadPayload['error'] ?? null, $markRead['body']);
        $this->assertNotSame('', trim((string)($markReadPayload['request_id'] ?? '')), $markRead['body']);
        $this->assertIsArray($markReadPayload['data'] ?? null, $markRead['body']);
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
        $forbiddenPayload = json_decode($forbidden['body'], true);
        $this->assertIsArray($forbiddenPayload);
        $this->assertSame(false, $forbiddenPayload['success'] ?? null, $forbidden['body']);
        $this->assertSame('Permission Denied', (string)($forbiddenPayload['error'] ?? ''), $forbidden['body']);
        $this->assertTrue(
            !isset($forbiddenPayload['error_type']) || (string)($forbiddenPayload['error_type'] ?? '') === 'permission',
            $forbidden['body']
        );
        $this->assertNull($forbiddenPayload['data'] ?? null, $forbidden['body']);
        $this->assertNotSame('', trim((string)($forbiddenPayload['request_id'] ?? '')), $forbidden['body']);

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
        $this->assertNull($allowedPayload['error'] ?? null, $allowed['body']);
        $this->assertNotSame('', trim((string)($allowedPayload['request_id'] ?? '')), $allowed['body']);

        $data = $allowedPayload['data'] ?? null;
        $this->assertIsArray($data);
        $this->assertArrayHasKey('generated_at', $data);
        $this->assertArrayHasKey('counters', $data);
        $this->assertIsArray($data['counters']);
        $this->assertArrayHasKey('open_dead_letters', $data['counters']);
        $this->assertArrayHasKey('scheduler_failures_24h', $data['counters']);
    }

    public function testUsersListRequiresManageUsersAndUsesCompatEnvelope(): void
    {
        $forbidden = self::request(
            'GET',
            '/api/users/list.php',
            [
                'Accept: application/json',
                'Authorization: Bearer ' . self::$operatorToken,
            ]
        );
        $this->assertSame(403, $forbidden['status'], $forbidden['body']);
        $forbiddenPayload = json_decode($forbidden['body'], true);
        $this->assertIsArray($forbiddenPayload);
        $this->assertSame(false, $forbiddenPayload['success'] ?? null, $forbidden['body']);
        $this->assertSame('Permission Denied', (string)($forbiddenPayload['error'] ?? ''), $forbidden['body']);
        $this->assertTrue(
            !isset($forbiddenPayload['error_type']) || (string)($forbiddenPayload['error_type'] ?? '') === 'permission',
            $forbidden['body']
        );
        $this->assertNull($forbiddenPayload['data'] ?? null, $forbidden['body']);
        $this->assertNotSame('', trim((string)($forbiddenPayload['request_id'] ?? '')), $forbidden['body']);

        $allowed = self::request(
            'GET',
            '/api/users/list.php',
            [
                'Accept: application/json',
                'Authorization: Bearer ' . self::$adminToken,
            ]
        );
        $this->assertSame(200, $allowed['status'], $allowed['body']);
        $allowedPayload = json_decode($allowed['body'], true);
        $this->assertIsArray($allowedPayload);
        $this->assertTrue((bool)($allowedPayload['success'] ?? false), $allowed['body']);
        $this->assertNull($allowedPayload['error'] ?? null, $allowed['body']);
        $this->assertNull($allowedPayload['error_type'] ?? null, $allowed['body']);
        $this->assertNotSame('', trim((string)($allowedPayload['request_id'] ?? '')), $allowed['body']);
        $this->assertIsArray($allowedPayload['data'] ?? null, $allowed['body']);
        $this->assertIsArray($allowedPayload['users'] ?? null, $allowed['body']);
        $this->assertIsArray($allowedPayload['roles'] ?? null, $allowed['body']);
        $this->assertIsArray($allowedPayload['permissions'] ?? null, $allowed['body']);
        $this->assertIsArray($allowedPayload['overrides'] ?? null, $allowed['body']);
        $this->assertIsArray($allowedPayload['permission_catalog'] ?? null, $allowed['body']);
        $this->assertSame(
            count($allowedPayload['users'] ?? []),
            count($allowedPayload['data']['users'] ?? []),
            $allowed['body']
        );
    }

    public function testSettingsEndpointsUseCompatEnvelopeAndPermissionBoundaries(): void
    {
        $settingsForbidden = self::request(
            'GET',
            '/api/settings.php',
            [
                'Accept: application/json',
                'Authorization: Bearer ' . self::$operatorToken,
            ]
        );
        $this->assertSame(403, $settingsForbidden['status'], $settingsForbidden['body']);
        $settingsForbiddenPayload = json_decode($settingsForbidden['body'], true);
        $this->assertIsArray($settingsForbiddenPayload);
        $this->assertSame(false, $settingsForbiddenPayload['success'] ?? null, $settingsForbidden['body']);
        $this->assertSame('Permission Denied', (string)($settingsForbiddenPayload['error'] ?? ''), $settingsForbidden['body']);
        $this->assertTrue(
            !isset($settingsForbiddenPayload['error_type']) || (string)($settingsForbiddenPayload['error_type'] ?? '') === 'permission',
            $settingsForbidden['body']
        );
        $this->assertNull($settingsForbiddenPayload['data'] ?? null, $settingsForbidden['body']);
        $this->assertNotSame('', trim((string)($settingsForbiddenPayload['request_id'] ?? '')), $settingsForbidden['body']);

        $settingsAllowed = self::request(
            'GET',
            '/api/settings.php',
            [
                'Accept: application/json',
                'Authorization: Bearer ' . self::$adminToken,
            ]
        );
        $this->assertSame(200, $settingsAllowed['status'], $settingsAllowed['body']);
        $settingsAllowedPayload = json_decode($settingsAllowed['body'], true);
        $this->assertIsArray($settingsAllowedPayload);
        $this->assertTrue((bool)($settingsAllowedPayload['success'] ?? false), $settingsAllowed['body']);
        $this->assertNull($settingsAllowedPayload['error'] ?? null, $settingsAllowed['body']);
        $this->assertNull($settingsAllowedPayload['error_type'] ?? null, $settingsAllowed['body']);
        $this->assertNotSame('', trim((string)($settingsAllowedPayload['request_id'] ?? '')), $settingsAllowed['body']);
        $this->assertIsArray($settingsAllowedPayload['data'] ?? null, $settingsAllowed['body']);
        $this->assertIsArray($settingsAllowedPayload['settings'] ?? null, $settingsAllowed['body']);
        $this->assertIsArray($settingsAllowedPayload['data']['settings'] ?? null, $settingsAllowed['body']);

        $settingsAuditForbidden = self::request(
            'GET',
            '/api/settings-audit.php?limit=5',
            [
                'Accept: application/json',
                'Authorization: Bearer ' . self::$operatorToken,
            ]
        );
        $this->assertSame(403, $settingsAuditForbidden['status'], $settingsAuditForbidden['body']);
        $settingsAuditForbiddenPayload = json_decode($settingsAuditForbidden['body'], true);
        $this->assertIsArray($settingsAuditForbiddenPayload);
        $this->assertSame(false, $settingsAuditForbiddenPayload['success'] ?? null, $settingsAuditForbidden['body']);
        $this->assertSame('Permission Denied', (string)($settingsAuditForbiddenPayload['error'] ?? ''), $settingsAuditForbidden['body']);
        $this->assertTrue(
            !isset($settingsAuditForbiddenPayload['error_type']) || (string)($settingsAuditForbiddenPayload['error_type'] ?? '') === 'permission',
            $settingsAuditForbidden['body']
        );
        $this->assertNull($settingsAuditForbiddenPayload['data'] ?? null, $settingsAuditForbidden['body']);
        $this->assertNotSame('', trim((string)($settingsAuditForbiddenPayload['request_id'] ?? '')), $settingsAuditForbidden['body']);

        $settingsAuditAllowed = self::request(
            'GET',
            '/api/settings-audit.php?limit=5',
            [
                'Accept: application/json',
                'Authorization: Bearer ' . self::$adminToken,
            ]
        );
        $this->assertSame(200, $settingsAuditAllowed['status'], $settingsAuditAllowed['body']);
        $settingsAuditAllowedPayload = json_decode($settingsAuditAllowed['body'], true);
        $this->assertIsArray($settingsAuditAllowedPayload);
        $this->assertTrue((bool)($settingsAuditAllowedPayload['success'] ?? false), $settingsAuditAllowed['body']);
        $this->assertNull($settingsAuditAllowedPayload['error'] ?? null, $settingsAuditAllowed['body']);
        $this->assertNull($settingsAuditAllowedPayload['error_type'] ?? null, $settingsAuditAllowed['body']);
        $this->assertNotSame('', trim((string)($settingsAuditAllowedPayload['request_id'] ?? '')), $settingsAuditAllowed['body']);
        $this->assertIsArray($settingsAuditAllowedPayload['data'] ?? null, $settingsAuditAllowed['body']);

        $settingsAuditMethod = self::request(
            'POST',
            '/api/settings-audit.php?limit=5',
            [
                'Accept: application/json',
                'Authorization: Bearer ' . self::$adminToken,
            ],
            []
        );
        $this->assertSame(405, $settingsAuditMethod['status'], $settingsAuditMethod['body']);
        $settingsAuditMethodPayload = json_decode($settingsAuditMethod['body'], true);
        $this->assertIsArray($settingsAuditMethodPayload);
        $this->assertSame(false, $settingsAuditMethodPayload['success'] ?? null, $settingsAuditMethod['body']);
        $this->assertSame('Method not allowed', (string)($settingsAuditMethodPayload['error'] ?? ''), $settingsAuditMethod['body']);
        $this->assertSame('validation', (string)($settingsAuditMethodPayload['error_type'] ?? ''), $settingsAuditMethod['body']);
        $this->assertNull($settingsAuditMethodPayload['data'] ?? null, $settingsAuditMethod['body']);
        $this->assertNotSame('', trim((string)($settingsAuditMethodPayload['request_id'] ?? '')), $settingsAuditMethod['body']);
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
        $forbiddenPayload = json_decode($forbidden['body'], true);
        $this->assertIsArray($forbiddenPayload);
        $this->assertSame(false, $forbiddenPayload['success'] ?? null, $forbidden['body']);
        $this->assertSame('Permission Denied', (string)($forbiddenPayload['error'] ?? ''), $forbidden['body']);
        $this->assertTrue(
            !isset($forbiddenPayload['error_type']) || (string)($forbiddenPayload['error_type'] ?? '') === 'permission',
            $forbidden['body']
        );
        $this->assertNull($forbiddenPayload['data'] ?? null, $forbidden['body']);
        $this->assertNotSame('', trim((string)($forbiddenPayload['request_id'] ?? '')), $forbidden['body']);

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
        $this->assertNull($payload['error'] ?? null, $allowed['body']);
        $this->assertNotSame('', trim((string)($payload['request_id'] ?? '')), $allowed['body']);

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
        $this->assertSame($requestId, (string)($payload['request_id'] ?? ''), $response['body']);
    }

    public function testUserPreferencesUsesCompatEnvelopeForReadAndValidationErrors(): void
    {
        $guest = self::request('GET', '/api/user-preferences.php', [
            'Accept: application/json',
        ]);
        $this->assertSame(401, $guest['status'], $guest['body']);
        $guestPayload = json_decode($guest['body'], true);
        $this->assertIsArray($guestPayload);
        $this->assertSame(false, $guestPayload['success'] ?? null, $guest['body']);
        $this->assertSame('Unauthorized', (string)($guestPayload['error'] ?? ''), $guest['body']);
        $this->assertTrue(
            !isset($guestPayload['error_type']) || (string)($guestPayload['error_type'] ?? '') === 'permission',
            $guest['body']
        );
        $this->assertNull($guestPayload['data'] ?? null, $guest['body']);
        $this->assertNotSame('', trim((string)($guestPayload['request_id'] ?? '')), $guest['body']);

        $read = self::request('GET', '/api/user-preferences.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ]);
        $this->assertSame(200, $read['status'], $read['body']);
        $readPayload = json_decode($read['body'], true);
        $this->assertIsArray($readPayload);
        $this->assertTrue((bool)($readPayload['success'] ?? false), $read['body']);
        $this->assertNull($readPayload['error'] ?? null, $read['body']);
        $this->assertNull($readPayload['error_type'] ?? null, $read['body']);
        $this->assertNotSame('', trim((string)($readPayload['request_id'] ?? '')), $read['body']);
        $this->assertIsArray($readPayload['data'] ?? null, $read['body']);
        $this->assertSame(
            (string)($readPayload['preferences']['language'] ?? ''),
            (string)($readPayload['data']['preferences']['language'] ?? ''),
            $read['body']
        );

        $invalid = self::request('POST', '/api/user-preferences.php', [
            'Accept: application/json',
            'Authorization: Bearer ' . self::$adminToken,
        ], [
            'language' => 'zz',
        ]);
        $this->assertSame(422, $invalid['status'], $invalid['body']);
        $invalidPayload = json_decode($invalid['body'], true);
        $this->assertIsArray($invalidPayload);
        $this->assertSame(false, $invalidPayload['success'] ?? null, $invalid['body']);
        $this->assertSame('language must be ar or en', (string)($invalidPayload['error'] ?? ''), $invalid['body']);
        $this->assertSame('validation', (string)($invalidPayload['error_type'] ?? ''), $invalid['body']);
        $this->assertNull($invalidPayload['data'] ?? null, $invalid['body']);
        $this->assertNotSame('', trim((string)($invalidPayload['request_id'] ?? '')), $invalid['body']);
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

    private static function purgeLeakedIntegrationUsers(): void
    {
        if (!(self::$db instanceof PDO)) {
            return;
        }

        $ids = self::$db
            ->query("SELECT id FROM users WHERE username LIKE 'integration_%'")
            ?->fetchAll(PDO::FETCH_COLUMN);
        if (!is_array($ids) || $ids === []) {
            return;
        }

        $userIds = array_values(array_map('intval', $ids));
        $in = implode(',', array_fill(0, count($userIds), '?'));

        // Best-effort cleanup for leaked rows from interrupted test runs.
        try {
            $stmt = self::$db->prepare("DELETE FROM api_access_tokens WHERE user_id IN ({$in})");
            $stmt->execute($userIds);
        } catch (Throwable $e) {
            // Non-blocking (table may not exist on partial schemas).
        }

        try {
            $stmt = self::$db->prepare("DELETE FROM user_permissions WHERE user_id IN ({$in})");
            $stmt->execute($userIds);
        } catch (Throwable $e) {
            // Non-blocking.
        }

        $stmt = self::$db->prepare("DELETE FROM users WHERE id IN ({$in})");
        $stmt->execute($userIds);
    }

    private static function quarantineLeakedIntegrationGuarantees(): void
    {
        if (!(self::$db instanceof PDO)) {
            return;
        }

        // If a previous integration run was interrupted, guarantees created by
        // testCreateGuaranteeAndConvertToRealUseCompatEnvelope can leak as real rows.
        // Reclassify them as test data before the suite starts.
        $quarantineBatch = 'integration_quarantine_' . gmdate('Ymd_His');
        $stmt = self::$db->prepare(
            "UPDATE guarantees
             SET is_test_data = 1,
                 test_batch_id = ?,
                 test_note = 'auto-quarantined leaked integration artifact'
             WHERE COALESCE(is_test_data, 0) = 0
               AND guarantee_number LIKE 'INT-G-%'
               AND CAST(raw_data AS TEXT) LIKE '%integration create guarantee flow%'"
        );
        $stmt->execute([$quarantineBatch]);
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
        self::$createdUserIds[] = $userId;
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

    private static function grantUserPermissionBySlug(int $userId, string $permissionSlug): void
    {
        if (!(self::$db instanceof PDO)) {
            throw new RuntimeException('Database handle is not available');
        }
        if ($userId <= 0) {
            throw new RuntimeException('Invalid user id while granting permission: ' . $permissionSlug);
        }

        $permissionId = self::resolvePermissionId($permissionSlug);

        $delete = self::$db->prepare('DELETE FROM user_permissions WHERE user_id = ? AND permission_id = ?');
        $delete->execute([$userId, $permissionId]);

        $insert = self::$db->prepare(
            "INSERT INTO user_permissions (user_id, permission_id, override_type) VALUES (?, ?, 'allow')"
        );
        $insert->execute([$userId, $permissionId]);
    }

    private static function resolvePermissionId(string $permissionSlug): int
    {
        if (!(self::$db instanceof PDO)) {
            throw new RuntimeException('Database handle is not available');
        }

        $stmt = self::$db->prepare('SELECT id FROM permissions WHERE slug = ? LIMIT 1');
        $stmt->execute([$permissionSlug]);
        $permissionId = $stmt->fetchColumn();
        if ($permissionId) {
            return (int)$permissionId;
        }

        if ($permissionSlug === 'batch_full_operations_override') {
            $insert = self::$db->prepare(
                'INSERT INTO permissions (name, slug, description) VALUES (?, ?, ?) ON CONFLICT (slug) DO NOTHING'
            );
            $insert->execute([
                'استثناء عمليات الدفعات الكاملة',
                'batch_full_operations_override',
                'Allow non-default roles to access full batch operation surfaces',
            ]);
            $stmt = self::$db->prepare('SELECT id FROM permissions WHERE slug = ? LIMIT 1');
            $stmt->execute([$permissionSlug]);
            $permissionId = $stmt->fetchColumn();
            if ($permissionId) {
                return (int)$permissionId;
            }
        }

        throw new RuntimeException('Permission slug not found for integration bootstrap: ' . $permissionSlug);
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
                (guarantee_id, status, is_locked, supplier_id, bank_id, decision_source, decided_by, created_at, updated_at, workflow_step, active_action, signatures_received)
             VALUES (?, 'ready', ?, ?, ?, 'manual', 'integration_admin', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 'approved', 'extension', 2)"
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
            'SELECT status, is_locked, locked_reason, active_action, workflow_step, signatures_received
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
