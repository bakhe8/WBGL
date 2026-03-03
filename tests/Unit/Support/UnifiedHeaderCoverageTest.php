<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class UnifiedHeaderCoverageTest extends TestCase
{
    private function projectRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    public function testRootIndexUsesUnifiedHeader(): void
    {
        $indexPath = $this->projectRoot() . '/index.php';
        $this->assertFileExists($indexPath);

        $content = (string) file_get_contents($indexPath);
        $this->assertStringContainsString("partials/unified-header.php", $content);
    }

    public function testAllOperationalViewsUseUnifiedHeader(): void
    {
        $viewsDir = $this->projectRoot() . '/views';
        $this->assertDirectoryExists($viewsDir);

        $excluded = [
            'batch-print.php',     // print layout (intentional no app header)
            'confidence-demo.php', // demo page
            'edit_guarantee.php',  // redirect shim
            'index.php',           // legacy sandbox page
            'login.php',           // auth entry page
        ];

        $viewFiles = glob($viewsDir . '/*.php') ?: [];
        $this->assertNotEmpty($viewFiles, 'No view files found under /views.');

        foreach ($viewFiles as $path) {
            $name = basename($path);
            if (in_array($name, $excluded, true)) {
                continue;
            }

            $content = (string) file_get_contents($path);
            $this->assertStringContainsString(
                'unified-header.php',
                $content,
                "View [$name] must include unified-header.php."
            );
        }
    }
}

