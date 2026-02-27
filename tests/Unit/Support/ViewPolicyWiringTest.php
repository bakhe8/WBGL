<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ViewPolicyWiringTest extends TestCase
{
    public function testProtectedViewsUseCentralizedGuard(): void
    {
        $root = realpath(__DIR__ . '/../../../');
        $this->assertNotFalse($root);

        $expected = [
            'views/batches.php',
            'views/batch-detail.php',
            'views/batch-print.php',
            'views/confidence-demo.php',
            'views/maintenance.php',
            'views/settings.php',
            'views/statistics.php',
            'views/users.php',
        ];

        foreach ($expected as $relative) {
            $path = $root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $this->assertFileExists($path);
            $content = (string)file_get_contents($path);
            $needle = "ViewPolicy::guardView('" . basename($relative) . "')";
            $this->assertStringContainsString($needle, $content, 'Missing guard in ' . $relative);
        }
    }
}
