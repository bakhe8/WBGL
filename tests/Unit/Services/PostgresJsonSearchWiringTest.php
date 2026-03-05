<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PostgresJsonSearchWiringTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = realpath(__DIR__ . '/../../../') ?: dirname(__DIR__, 3);
    }

    public function testNavigationSearchCastsRawDataToTextForLike(): void
    {
        $path = $this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Services' . DIRECTORY_SEPARATOR . 'NavigationService.php';
        $this->assertFileExists($path);
        $content = (string)file_get_contents($path);

        $this->assertStringContainsString('g.raw_data::text LIKE :search_any', $content);
        $this->assertStringNotContainsString('g.raw_data LIKE :search_any', $content);
    }

    public function testLearningRepositoryCastsRawDataToTextForLike(): void
    {
        $path = $this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Repositories' . DIRECTORY_SEPARATOR . 'LearningRepository.php';
        $this->assertFileExists($path);
        $content = (string)file_get_contents($path);

        $this->assertStringContainsString('g.raw_data::text LIKE ?', $content);
        $this->assertStringNotContainsString('g.raw_data LIKE ?', $content);
    }
}
