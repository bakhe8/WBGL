<?php

declare(strict_types=1);

use App\Services\MatchingOverrideService;
use App\Support\Database;
use App\Support\Normalizer;
use PHPUnit\Framework\TestCase;

final class MatchingOverrideServiceTest extends TestCase
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

    public function testCreateOrUpdateListAndDeleteOverride(): void
    {
        $service = new MatchingOverrideService();
        $supplierA = $this->createSupplier('Override Supplier A');
        $supplierB = $this->createSupplier('Override Supplier B');

        $created = $service->createOrUpdate(
            'مؤسسة تجريبية',
            $supplierA,
            'unit_test',
            true,
            'phpunit'
        );

        $this->assertGreaterThan(0, (int)$created['id']);
        $this->assertSame($supplierA, (int)$created['supplier_id']);
        $this->assertSame('مؤسسة تجريبية', (string)$created['raw_name']);
        $this->overrideIds[] = (int)$created['id'];

        $updated = $service->updateById((int)$created['id'], [
            'raw_name' => 'مؤسسة تجريبية محدثة',
            'supplier_id' => $supplierB,
            'reason' => 'updated_reason',
            'is_active' => 1,
        ], 'phpunit_update');

        $this->assertSame($supplierB, (int)$updated['supplier_id']);
        $this->assertSame('مؤسسة تجريبية محدثة', (string)$updated['raw_name']);
        $this->assertSame('updated_reason', (string)$updated['reason']);

        $items = $service->list(100, false);
        $itemIds = array_map(static fn(array $row): int => (int)$row['id'], $items);
        $this->assertContains((int)$created['id'], $itemIds);

        $deleted = $service->deleteById((int)$created['id']);
        $this->assertTrue($deleted);
        $this->overrideIds = array_values(array_filter(
            $this->overrideIds,
            static fn(int $id): bool => $id !== (int)$created['id']
        ));
    }

    public function testImportRowsCreatesUpdatesAndSkipsInvalidRows(): void
    {
        $service = new MatchingOverrideService();
        $supplierA = $this->createSupplier('Import Override Supplier A');
        $supplierB = $this->createSupplier('Import Override Supplier B');

        $seed = $service->createOrUpdate('نص مبدئي', $supplierA, 'seed', true, 'phpunit_seed');
        $this->overrideIds[] = (int)$seed['id'];

        $result = $service->importRows([
            [
                'raw_name' => 'نص مبدئي', // should update existing by normalized name
                'supplier_id' => $supplierB,
                'reason' => 'updated_by_import',
                'is_active' => 1,
            ],
            [
                'raw_name' => 'نص جديد',
                'supplier_id' => $supplierA,
                'reason' => 'inserted_by_import',
                'is_active' => 1,
            ],
            [
                'raw_name' => '', // invalid
                'supplier_id' => $supplierA,
            ],
        ], 'phpunit_import');

        $this->assertSame(1, (int)$result['inserted']);
        $this->assertSame(1, (int)$result['updated']);
        $this->assertSame(1, (int)$result['skipped']);

        $all = $service->list(100, false);
        foreach ($all as $row) {
            if (($row['reason'] ?? '') === 'inserted_by_import') {
                $this->overrideIds[] = (int)$row['id'];
            }
        }
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
