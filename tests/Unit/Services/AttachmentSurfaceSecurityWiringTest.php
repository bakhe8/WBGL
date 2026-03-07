<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AttachmentSurfaceSecurityWiringTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = realpath(__DIR__ . '/../../../') ?: dirname(__DIR__, 3);
    }

    public function testApiMatrixIncludesProtectedFileEndpoints(): void
    {
        $matrix = $this->read('app/Support/ApiPolicyMatrix.php');
        $this->assertStringContainsString("'api/attachment-file.php' => ['auth' => 'permission', 'permission' => 'attachments_view']", $matrix);
        $this->assertStringContainsString("'api/evidence-file.php' => ['auth' => 'permission', 'permission' => 'import_excel']", $matrix);
    }

    public function testServerRouterBlocksDirectStorageAndPublicUploadsAccess(): void
    {
        $router = $this->read('server.php');
        $this->assertStringContainsString("'/storage/'", $router);
        $this->assertStringContainsString("'/public/uploads/'", $router);
        $this->assertStringContainsString("'/uploads/'", $router);
    }

    public function testIndexAttachmentLinksUseProtectedApi(): void
    {
        $index = $this->read('index.php');
        $this->assertStringContainsString('/api/attachment-file.php?id=', $index);
        $this->assertStringNotContainsString('/V3/storage/', $index);
    }

    public function testImportEvidenceMovesAwayFromPublicUploadsFlow(): void
    {
        $emailImport = $this->read('app/Services/Import/EmailImportService.php');
        $saveImport = $this->read('api/save-import.php');

        $this->assertStringContainsString('/storage/uploads/temp/', $emailImport);
        $this->assertStringContainsString('/api/evidence-file.php?temp_path=', $emailImport);
        $this->assertStringContainsString('wbgl_extract_temp_path_from_evidence_reference', $saveImport);
        $this->assertStringContainsString('attachments/guarantees/', $saveImport);
    }

    private function read(string $relativePath): string
    {
        $path = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $this->assertFileExists($path, 'Missing required file: ' . $relativePath);
        return (string)file_get_contents($path);
    }
}
