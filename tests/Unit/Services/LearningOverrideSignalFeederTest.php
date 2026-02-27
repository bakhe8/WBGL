<?php

declare(strict_types=1);

use App\Repositories\SupplierOverrideRepository;
use App\Services\Learning\Feeders\OverrideSignalFeeder;
use App\Services\MatchingOverrideService;
use App\Support\Database;
use App\Support\Normalizer;
use PHPUnit\Framework\TestCase;

final class LearningOverrideSignalFeederTest extends TestCase
{
    private array $supplierIds = [];
    private array $overrideIds = [];

    protected function setUp(): void
    {
        if (!$this->hasTable('supplier_overrides')) {
            $this->markTestSkipped('supplier_overrides table is not available');
        }
    }

    protected function tearDown(): void
    {
        $db = Database::connect();
        foreach ($this->overrideIds as $id) {
            $db->prepare('DELETE FROM supplier_overrides WHERE id = ?')->execute([$id]);
        }
        foreach ($this->supplierIds as $id) {
            $db->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$id]);
        }
        $this->overrideIds = [];
        $this->supplierIds = [];
    }

    public function testOverrideSignalIsProducedFromSupplierOverridesTable(): void
    {
        $supplierId = $this->createSupplier('Signal Override Supplier');
        $service = new MatchingOverrideService();

        $saved = $service->createOrUpdate(
            'مؤسسة اشارة',
            $supplierId,
            'unit_test_signal',
            true,
            'phpunit'
        );
        $this->overrideIds[] = (int)$saved['id'];

        $normalizer = new Normalizer();
        $normalized = $normalizer->normalizeSupplierName('مؤسسة اشارة');

        $feeder = new OverrideSignalFeeder(new SupplierOverrideRepository());
        $signals = $feeder->getSignals($normalized);

        $this->assertCount(1, $signals);
        $this->assertSame('override_exact', $signals[0]->signal_type);
        $this->assertSame($supplierId, $signals[0]->supplier_id);
        $this->assertSame('override', $signals[0]->metadata['source'] ?? null);
    }

    private function createSupplier(string $officialName): int
    {
        $db = Database::connect();
        $normalizer = new Normalizer();
        $normalized = $normalizer->normalizeSupplierName($officialName);

        $stmt = $db->prepare('
            INSERT INTO suppliers (official_name, normalized_name, is_confirmed, created_at)
            VALUES (?, ?, 1, CURRENT_TIMESTAMP)
        ');
        $stmt->execute([$officialName, $normalized]);
        $id = (int)$db->lastInsertId();
        $this->supplierIds[] = $id;
        return $id;
    }

    private function hasTable(string $table): bool
    {
        $db = Database::connect();
        $stmt = $db->prepare(
            "SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = ? LIMIT 1"
        );
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    }
}
