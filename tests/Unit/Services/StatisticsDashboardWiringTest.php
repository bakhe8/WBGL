<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class StatisticsDashboardWiringTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = realpath(__DIR__ . '/../../../') ?: dirname(__DIR__, 3);
    }

    public function testStatisticsViewUsesDashboardServiceForOverview(): void
    {
        $view = $this->readFile('views/statistics.php');
        $service = $this->readFile('app/Services/StatisticsDashboardService.php');

        $this->assertStringContainsString('use App\\Services\\StatisticsDashboardService;', $view);
        $this->assertStringContainsString('StatisticsDashboardService::fetchOverview(', $view);
        $this->assertStringContainsString('StatisticsDashboardService::calculateEfficiencyRatio(', $view);
        $this->assertStringContainsString('StatisticsDashboardService::fetchBatchAndSupplierBlocks(', $view);
        $this->assertStringContainsString('StatisticsDashboardService::fetchTimePerformanceBlocks(', $view);
        $this->assertStringContainsString('StatisticsDashboardService::fetchExpirationActionBlocks(', $view);
        $this->assertStringContainsString('StatisticsDashboardService::fetchAiLearningBlocks(', $view);
        $this->assertStringContainsString('StatisticsDashboardService::fetchFinancialTypeBlocks(', $view);
        $this->assertStringContainsString('StatisticsDashboardService::fetchUrgentList(', $view);
        $this->assertStringNotContainsString('$db->query(', $view);
        $this->assertStringNotContainsString('$db->prepare(', $view);

        $this->assertStringContainsString('final class StatisticsDashboardService', $service);
        $this->assertStringContainsString('public static function fetchOverview(', $service);
        $this->assertStringContainsString('public static function calculateEfficiencyRatio(', $service);
        $this->assertStringContainsString('public static function fetchBatchAndSupplierBlocks(', $service);
        $this->assertStringContainsString('public static function fetchTimePerformanceBlocks(', $service);
        $this->assertStringContainsString('public static function fetchExpirationActionBlocks(', $service);
        $this->assertStringContainsString('public static function fetchAiLearningBlocks(', $service);
        $this->assertStringContainsString('public static function fetchFinancialTypeBlocks(', $service);
        $this->assertStringContainsString('public static function fetchUrgentList(', $service);
    }

    private function readFile(string $relativePath): string
    {
        $path = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $this->assertFileExists($path, 'Missing required file: ' . $relativePath);
        return (string)file_get_contents($path);
    }
}
