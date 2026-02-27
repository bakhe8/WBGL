<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;
use PDO;

/**
 * SupplierOverrideRepository
 * 
 * Manages manual overrides for supplier matching (table: supplier_overrides)
 */
class SupplierOverrideRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(int $limit = 200, bool $activeOnly = false): array
    {
        $sql = "
            SELECT so.*, s.official_name AS supplier_official_name
            FROM supplier_overrides so
            JOIN suppliers s ON s.id = so.supplier_id
        ";
        if ($activeOnly) {
            $sql .= " WHERE so.is_active = 1";
        }
        $sql .= " ORDER BY so.updated_at DESC, so.id DESC LIMIT ?";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([max(1, $limit)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT so.*, s.official_name AS supplier_official_name
            FROM supplier_overrides so
            JOIN suppliers s ON s.id = so.supplier_id
            WHERE so.id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByNormalized(string $normalizedName, bool $activeOnly = true): ?array
    {
        $sql = "
            SELECT so.*, s.official_name AS supplier_official_name
            FROM supplier_overrides so
            JOIN suppliers s ON s.id = so.supplier_id
            WHERE so.normalized_name = ?
        ";
        if ($activeOnly) {
            $sql .= " AND so.is_active = 1";
        }
        $sql .= " ORDER BY so.id DESC LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$normalizedName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function upsert(
        string $rawName,
        string $normalizedName,
        int $supplierId,
        ?string $reason = null,
        bool $isActive = true,
        string $actor = 'النظام'
    ): int {
        $existing = $this->findByNormalized($normalizedName, false);

        if ($existing) {
            $stmt = $this->db->prepare("
                UPDATE supplier_overrides
                SET raw_name = ?,
                    supplier_id = ?,
                    reason = ?,
                    is_active = ?,
                    updated_by = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([
                $rawName,
                $supplierId,
                $reason,
                $isActive ? 1 : 0,
                $actor,
                (int)$existing['id'],
            ]);
            return (int)$existing['id'];
        }

        $stmt = $this->db->prepare("
            INSERT INTO supplier_overrides
            (raw_name, normalized_name, supplier_id, reason, is_active, created_by, updated_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $rawName,
            $normalizedName,
            $supplierId,
            $reason,
            $isActive ? 1 : 0,
            $actor,
            $actor,
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function updateById(
        int $id,
        string $rawName,
        string $normalizedName,
        int $supplierId,
        ?string $reason = null,
        bool $isActive = true,
        string $actor = 'النظام'
    ): bool {
        $stmt = $this->db->prepare("
            UPDATE supplier_overrides
            SET raw_name = ?,
                normalized_name = ?,
                supplier_id = ?,
                reason = ?,
                is_active = ?,
                updated_by = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([
            $rawName,
            $normalizedName,
            $supplierId,
            $reason,
            $isActive ? 1 : 0,
            $actor,
            $id,
        ]);
        return $stmt->rowCount() > 0;
    }

    public function deleteById(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM supplier_overrides WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }
}
