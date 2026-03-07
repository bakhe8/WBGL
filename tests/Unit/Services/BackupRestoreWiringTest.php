<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BackupRestoreWiringTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = realpath(__DIR__ . '/../../../') ?: dirname(__DIR__, 3);
    }

    public function testBackupScriptWiresPgDumpAndRetention(): void
    {
        $content = $this->read('app/Scripts/backup-database.php');
        $this->assertStringContainsString('pg_dump', $content);
        $this->assertStringContainsString('--retention-days=', $content);
        $this->assertStringContainsString('wbgl_backup_apply_retention', $content);
    }

    public function testRestoreDrillScriptWiresPgRestoreLifecycle(): void
    {
        $content = $this->read('app/Scripts/restore-drill.php');
        $this->assertStringContainsString('dropdb', $content);
        $this->assertStringContainsString('createdb', $content);
        $this->assertStringContainsString('pg_restore', $content);
        $this->assertStringContainsString('source_counts', $content);
        $this->assertStringContainsString('restored_counts', $content);
    }

    public function testDrProcedureReferencesBackupAndRestoreScripts(): void
    {
        $doc = $this->read('Docs/WBGL-DR-DRILL-PROCEDURE-AR.md');
        $this->assertStringContainsString('app/Scripts/backup-database.php', $doc);
        $this->assertStringContainsString('app/Scripts/restore-drill.php', $doc);
    }

    private function read(string $relativePath): string
    {
        $path = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $this->assertFileExists($path, 'Missing required file: ' . $relativePath);
        return (string)file_get_contents($path);
    }
}
