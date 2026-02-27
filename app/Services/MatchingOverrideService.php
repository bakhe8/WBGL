<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SupplierOverrideRepository;
use App\Support\Database;
use App\Support\Normalizer;
use PDO;
use RuntimeException;

class MatchingOverrideService
{
    private PDO $db;
    private SupplierOverrideRepository $repo;
    private Normalizer $normalizer;

    public function __construct(
        ?PDO $db = null,
        ?SupplierOverrideRepository $repo = null,
        ?Normalizer $normalizer = null
    ) {
        $this->db = $db ?? Database::connect();
        $this->repo = $repo ?? new SupplierOverrideRepository($this->db);
        $this->normalizer = $normalizer ?? new Normalizer();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(int $limit = 200, bool $activeOnly = false): array
    {
        return $this->repo->list($limit, $activeOnly);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function exportRows(int $limit = 5000): array
    {
        $rows = $this->repo->list($limit, false);
        return array_map(static function (array $row): array {
            return [
                'id' => (int)$row['id'],
                'raw_name' => (string)$row['raw_name'],
                'normalized_name' => (string)$row['normalized_name'],
                'supplier_id' => (int)$row['supplier_id'],
                'supplier_official_name' => (string)($row['supplier_official_name'] ?? ''),
                'reason' => $row['reason'] ?? null,
                'is_active' => (int)$row['is_active'],
                'created_by' => $row['created_by'] ?? null,
                'updated_by' => $row['updated_by'] ?? null,
                'created_at' => $row['created_at'] ?? null,
                'updated_at' => $row['updated_at'] ?? null,
            ];
        }, $rows);
    }

    /**
     * @param array<int, mixed> $rows
     * @return array<string, mixed>
     */
    public function importRows(array $rows, string $actor = 'النظام'): array
    {
        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rows as $idx => $row) {
            $lineNo = $idx + 1;
            if (!is_array($row)) {
                $skipped++;
                $errors[] = "Line {$lineNo}: invalid row format";
                continue;
            }

            $rawName = trim((string)($row['raw_name'] ?? ''));
            if ($rawName === '') {
                $skipped++;
                $errors[] = "Line {$lineNo}: raw_name is required";
                continue;
            }

            $supplierId = $this->resolveSupplierId($row);
            if ($supplierId <= 0) {
                $skipped++;
                $errors[] = "Line {$lineNo}: supplier_id not found";
                continue;
            }

            $reason = array_key_exists('reason', $row) ? trim((string)$row['reason']) : null;
            $reason = $reason === '' ? null : $reason;

            $isActiveRaw = $row['is_active'] ?? 1;
            $isActive = in_array($isActiveRaw, [1, '1', true, 'true', 'yes'], true);

            try {
                $normalized = $this->normalizer->normalizeSupplierName($rawName);
                $existing = $this->repo->findByNormalized($normalized, false);

                $this->createOrUpdate($rawName, $supplierId, $reason, $isActive, $actor);

                if ($existing) {
                    $updated++;
                } else {
                    $inserted++;
                }
            } catch (RuntimeException $e) {
                $skipped++;
                $errors[] = "Line {$lineNo}: " . $e->getMessage();
            }
        }

        return [
            'inserted' => $inserted,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Create or update override by normalized name.
     *
     * @return array<string, mixed>
     */
    public function createOrUpdate(
        string $rawName,
        int $supplierId,
        ?string $reason = null,
        bool $isActive = true,
        string $actor = 'النظام'
    ): array {
        $rawName = trim($rawName);
        if ($rawName === '') {
            throw new RuntimeException('اسم المطابقة البديل مطلوب');
        }

        $normalized = $this->normalizer->normalizeSupplierName($rawName);
        if ($normalized === '') {
            throw new RuntimeException('تعذر توليد الاسم المطبع');
        }

        $this->assertSupplierExists($supplierId);

        $id = $this->repo->upsert(
            $rawName,
            $normalized,
            $supplierId,
            $reason,
            $isActive,
            $actor
        );

        $row = $this->repo->findById($id);
        if (!$row) {
            throw new RuntimeException('فشل تحميل override بعد الحفظ');
        }
        return $row;
    }

    /**
     * Update override by ID.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateById(int $id, array $payload, string $actor = 'النظام'): array
    {
        $current = $this->repo->findById($id);
        if (!$current) {
            throw new RuntimeException('الـ override غير موجود');
        }

        $rawName = isset($payload['raw_name']) ? trim((string)$payload['raw_name']) : (string)$current['raw_name'];
        if ($rawName === '') {
            throw new RuntimeException('اسم المطابقة البديل مطلوب');
        }

        $normalized = $this->normalizer->normalizeSupplierName($rawName);
        if ($normalized === '') {
            throw new RuntimeException('تعذر توليد الاسم المطبع');
        }

        $supplierId = isset($payload['supplier_id']) ? (int)$payload['supplier_id'] : (int)$current['supplier_id'];
        $this->assertSupplierExists($supplierId);

        $reason = array_key_exists('reason', $payload) ? (string)$payload['reason'] : (($current['reason'] ?? null) !== null ? (string)$current['reason'] : null);
        $isActive = array_key_exists('is_active', $payload)
            ? ((int)$payload['is_active'] === 1 || $payload['is_active'] === true || $payload['is_active'] === '1')
            : ((int)$current['is_active'] === 1);

        $this->repo->updateById(
            $id,
            $rawName,
            $normalized,
            $supplierId,
            $reason,
            $isActive,
            $actor
        );

        $row = $this->repo->findById($id);
        if (!$row) {
            throw new RuntimeException('فشل تحميل override بعد التحديث');
        }
        return $row;
    }

    public function deleteById(int $id): bool
    {
        return $this->repo->deleteById($id);
    }

    private function assertSupplierExists(int $supplierId): void
    {
        if ($supplierId <= 0) {
            throw new RuntimeException('supplier_id غير صالح');
        }

        $stmt = $this->db->prepare('SELECT id FROM suppliers WHERE id = ? LIMIT 1');
        $stmt->execute([$supplierId]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('المورد غير موجود');
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resolveSupplierId(array $row): int
    {
        if (isset($row['supplier_id']) && is_numeric($row['supplier_id'])) {
            return (int)$row['supplier_id'];
        }

        $name = trim((string)($row['supplier_official_name'] ?? ''));
        if ($name === '') {
            return 0;
        }

        $stmt = $this->db->prepare('SELECT id FROM suppliers WHERE official_name = ? LIMIT 1');
        $stmt->execute([$name]);
        return (int)($stmt->fetchColumn() ?: 0);
    }
}
