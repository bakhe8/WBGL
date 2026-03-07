<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ParseEndpointsConfidenceWiringTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3);
    }

    public function testParsePasteEndpointsUseSharedConfidenceGuard(): void
    {
        $v1 = $this->readFile('api/parse-paste.php');
        $v2 = $this->readFile('api/parse-paste-v2.php');

        $this->assertStringContainsString("require __DIR__ . '/parse-paste-v2.php';", $v1);
        $this->assertStringContainsString('ParseResponseConfidenceGuard::strengthen', $v2);
    }

    public function testParsePasteV2NoLongerRecalculatesConfidenceInline(): void
    {
        $v2 = $this->readFile('api/parse-paste-v2.php');

        $this->assertStringNotContainsString('$calculator = new ConfidenceCalculator();', $v2);
        $this->assertStringNotContainsString('calculateSupplierConfidence(', $v2);
        $this->assertStringNotContainsString('calculateBankConfidence(', $v2);
    }

    private function readFile(string $relativePath): string
    {
        $path = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $this->assertFileExists($path, 'Missing required file: ' . $relativePath);
        return (string)file_get_contents($path);
    }
}
