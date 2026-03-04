<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ApiContractGuardWiringTest extends TestCase
{
    private string $root;

    /**
        * @var array<int, string>
        */
    private array $manualJsonOutputAllowList = [
        'api/_bootstrap.php',
        'api/export_banks.php',
        'api/export_suppliers.php',
        'api/export_matching_overrides.php',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
    }

    public function testApiEndpointsAvoidDirectJsonEchoOutsideApprovedFiles(): void
    {
        $apiRoot = $this->root . DIRECTORY_SEPARATOR . 'api';
        $files = $this->listPhpFiles($apiRoot);
        $this->assertNotEmpty($files, 'No API files discovered under api/');

        foreach ($files as $absolutePath) {
            $relativePath = $this->toRelativePath($absolutePath);
            $contents = (string)file_get_contents($absolutePath);

            if (in_array($relativePath, $this->manualJsonOutputAllowList, true)) {
                continue;
            }

            $this->assertStringNotContainsString(
                'echo json_encode(',
                $contents,
                'Direct JSON echo is forbidden; use wbgl_api_compat_success/fail: ' . $relativePath
            );
        }
    }

    public function testGlobalCsrfSkipFlagIsRestrictedToLoginAndLogoutEndpoints(): void
    {
        $expected = [
            'api/login.php',
            'api/logout.php',
        ];

        $files = $this->listPhpFiles($this->root . DIRECTORY_SEPARATOR . 'api');
        $actual = [];

        foreach ($files as $absolutePath) {
            $contents = (string)file_get_contents($absolutePath);
            if (str_contains($contents, "define('WBGL_API_SKIP_GLOBAL_CSRF'")) {
                $actual[] = $this->toRelativePath($absolutePath);
            }
        }

        sort($expected);
        sort($actual);

        $this->assertSame($expected, $actual, 'CSRF global skip flag must stay restricted to login/logout only.');
    }

    /**
     * @return array<int, string>
     */
    private function listPhpFiles(string $root): array
    {
        $result = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }
            if (!$item->isFile()) {
                continue;
            }
            if (strtolower($item->getExtension()) !== 'php') {
                continue;
            }
            $result[] = $item->getPathname();
        }

        sort($result);
        return $result;
    }

    private function toRelativePath(string $absolutePath): string
    {
        $relative = str_replace($this->root . DIRECTORY_SEPARATOR, '', $absolutePath);
        return str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    }
}
