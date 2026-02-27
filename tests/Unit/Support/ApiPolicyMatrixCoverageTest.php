<?php

declare(strict_types=1);

use App\Support\ApiPolicyMatrix;
use PHPUnit\Framework\TestCase;

final class ApiPolicyMatrixCoverageTest extends TestCase
{
    public function testMatrixCoversAllApiEndpoints(): void
    {
        $root = realpath(__DIR__ . '/../../../');
        $this->assertNotFalse($root);

        $apiFiles = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/api'));
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            if (strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if ($relative === 'api/_bootstrap.php') {
                continue;
            }
            $apiFiles[] = $relative;
        }

        sort($apiFiles);
        $matrix = ApiPolicyMatrix::all();
        $matrixEndpoints = array_keys($matrix);
        sort($matrixEndpoints);

        $missingFromMatrix = array_values(array_diff($apiFiles, $matrixEndpoints));
        $missingFromFiles = array_values(array_diff($matrixEndpoints, $apiFiles));

        $this->assertSame([], $missingFromMatrix, 'API files missing from matrix: ' . json_encode($missingFromMatrix));
        $this->assertSame([], $missingFromFiles, 'Matrix references missing files: ' . json_encode($missingFromFiles));
    }

    public function testMatrixRulesMatchEndpointGuards(): void
    {
        $root = realpath(__DIR__ . '/../../../');
        $this->assertNotFalse($root);

        foreach (ApiPolicyMatrix::all() as $endpoint => $rule) {
            $path = $root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $endpoint);
            $this->assertFileExists($path, 'Missing endpoint file: ' . $endpoint);

            $content = (string)file_get_contents($path);
            $auth = $rule['auth'] ?? 'none';
            $permission = $rule['permission'] ?? null;

            $this->assertContains($auth, ['public', 'login', 'permission'], 'Unknown auth type in matrix for ' . $endpoint);

            if ($auth === 'permission') {
                $this->assertIsString($permission);
                $this->assertNotSame('', trim((string)$permission));
                $expected = "wbgl_api_require_permission('{$permission}')";
                $this->assertStringContainsString($expected, $content, 'Permission mismatch for ' . $endpoint);
                continue;
            }

            if ($auth === 'login') {
                $this->assertStringContainsString(
                    'wbgl_api_require_login()',
                    $content,
                    'Login guard missing for ' . $endpoint
                );
                continue;
            }

            $this->assertStringNotContainsString(
                'wbgl_api_require_permission(',
                $content,
                'Public endpoint should not require permission guard: ' . $endpoint
            );
        }
    }
}
