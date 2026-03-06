<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PerformanceScalabilityWiringTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = realpath(__DIR__ . '/../../../') ?: dirname(__DIR__, 3);
    }

    public function testNavigationIndexLookupIsOffsetFree(): void
    {
        $navigation = $this->readFile('app/Services/NavigationService.php');

        $this->assertStringContainsString('private static function countUpToId', $navigation);
        $this->assertStringContainsString('private static function fetchIdRange', $navigation);
        $this->assertStringContainsString('private static function firstIdAtOrAfter', $navigation);
        $this->assertStringNotContainsString('OFFSET :offset', $navigation);
    }

    public function testSchemaInspectorCacheIsUsedAcrossRuntimeServices(): void
    {
        $schemaInspector = $this->readFile('app/Support/SchemaInspector.php');
        $notificationService = $this->readFile('app/Services/NotificationService.php');
        $operationalMetrics = $this->readFile('app/Services/OperationalMetricsService.php');
        $statisticsDashboard = $this->readFile('app/Services/StatisticsDashboardService.php');
        $auditTrail = $this->readFile('app/Services/AuditTrailService.php');
        $historyArchive = $this->readFile('app/Services/HistoryArchiveService.php');

        $this->assertStringContainsString('final class SchemaInspector', $schemaInspector);
        $this->assertStringContainsString('public static function tableExists', $schemaInspector);
        $this->assertStringContainsString('public static function columnExists', $schemaInspector);

        $this->assertStringContainsString('use App\Support\SchemaInspector;', $notificationService);
        $this->assertStringContainsString('SchemaInspector::tableExists', $notificationService);
        $this->assertStringContainsString('SchemaInspector::columnExists', $notificationService);

        $this->assertStringContainsString('use App\Support\SchemaInspector;', $operationalMetrics);
        $this->assertStringContainsString('SchemaInspector::tableExists', $operationalMetrics);

        $this->assertStringContainsString('use App\Support\SchemaInspector;', $statisticsDashboard);
        $this->assertStringContainsString('SchemaInspector::tableExists', $statisticsDashboard);

        $this->assertStringContainsString('use App\Support\SchemaInspector;', $auditTrail);
        $this->assertStringContainsString('SchemaInspector::tableExists', $auditTrail);

        $this->assertStringContainsString('use App\Support\SchemaInspector;', $historyArchive);
        $this->assertStringContainsString('SchemaInspector::tableExists', $historyArchive);
    }

    public function testFuzzyFeederUsesCandidatePrefilterAndCheapLengthGate(): void
    {
        $feeder = $this->readFile('app/Services/Learning/Feeders/FuzzySignalFeeder.php');
        $supplierRepo = $this->readFile('app/Repositories/SupplierRepository.php');

        $this->assertStringContainsString('extractDistinctiveTokens', $feeder);
        $this->assertStringContainsString('getFuzzyCandidatesByTokens', $feeder);
        $this->assertStringContainsString('$maxPossibleSimilarity', $feeder);
        $this->assertStringContainsString('if ($maxPossibleSimilarity < self::MIN_SIMILARITY)', $feeder);

        $this->assertStringContainsString('public function getFuzzyCandidatesByTokens', $supplierRepo);
        $this->assertStringContainsString('LIMIT :candidate_limit', $supplierRepo);
    }

    public function testCriticalApiContractAndSafetyWiringRemainInPlace(): void
    {
        $workflowAdvance = $this->readFile('api/workflow-advance.php');
        $bootstrap = $this->readFile('api/_bootstrap.php');
        $settingsApi = $this->readFile('api/settings.php');

        $this->assertStringContainsString('wbgl_api_compat_fail(409, \'No further stages available\'', $workflowAdvance);
        $this->assertStringContainsString('TransactionBoundary::run', $workflowAdvance);

        $this->assertStringContainsString('$publicMessage = \'حدث خطأ داخلي. استخدم رقم الطلب للمتابعة.\';', $bootstrap);
        $this->assertStringContainsString('wbgl_settings_redact_sensitive', $settingsApi);
    }

    private function readFile(string $relativePath): string
    {
        $path = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $this->assertFileExists($path, 'Missing required file: ' . $relativePath);
        return (string)file_get_contents($path);
    }
}

