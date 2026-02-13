<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Models\Supplier;
use App\Support\Database;
use PDO;

class SupplierRepository
{
    /**
     * @return array<int, array{id:int, official_name:string, display_name:?string, normalized_name:string}>
     */
    public function findAllByNormalized(string $normalizedName): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, official_name, display_name, normalized_name, supplier_normalized_key FROM suppliers WHERE normalized_name = :n');
        $stmt->execute(['n' => $normalizedName]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<int, array{id:int, official_name:string, normalized_name:string}>
     */
    public function allNormalized(): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->query('SELECT id, official_name, normalized_name, supplier_normalized_key FROM suppliers');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<int, array{id:int, official_name:string, normalized_name:string}>
     */
    public function search(string $normalizedLike): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, official_name, normalized_name, supplier_normalized_key FROM suppliers WHERE normalized_name LIKE :q');
        $stmt->execute(['q' => "%{$normalizedLike}%"]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function update(int $id, array $data): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('UPDATE suppliers SET official_name=:o, display_name=:d, normalized_name=:n, supplier_normalized_key=:k WHERE id=:id');
        $stmt->execute([
            'o' => $data['official_name'],
            'd' => $data['display_name'] ?? null,
            'n' => $data['normalized_name'],
            'k' => $data['supplier_normalized_key'] ?? null,
            'id' => $id,
        ]);
    }

    public function delete(int $id): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('DELETE FROM suppliers WHERE id=:id');
        $stmt->execute(['id' => $id]);
    }

    public function find(int $id): ?Supplier
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM suppliers WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return $this->map($row);
    }

    public function findByNormalizedName(string $normalizedName): ?Supplier
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM suppliers WHERE normalized_name = :n LIMIT 1');
        $stmt->execute(['n' => $normalizedName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return $this->map($row);
    }

    public function create(array $data): Supplier
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO suppliers (official_name, display_name, normalized_name, supplier_normalized_key, is_confirmed) VALUES (:o, :d, :n, :k, :c)');
        $stmt->execute([
            'o' => $data['official_name'],
            'd' => $data['display_name'] ?? null,
            'n' => $data['normalized_name'],
            'k' => $data['supplier_normalized_key'] ?? null,
            'c' => $data['is_confirmed'] ?? 0,
        ]);
        $id = (int) $pdo->lastInsertId();
        return new Supplier($id, $data['official_name'], $data['display_name'] ?? null, $data['normalized_name'], $data['supplier_normalized_key'] ?? null, (int) ($data['is_confirmed'] ?? 0), date('c'));
    }

    public function findByNormalizedKey(string $key): ?Supplier
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM suppliers WHERE supplier_normalized_key = :k LIMIT 1');
        $stmt->execute(['k' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->map($row) : null;
    }

    /**
     * Get all suppliers (for fuzzy matching)
     * Used by FuzzySignalFeeder
     * 
     * @return array<int, array{id:int, official_name:string, normalized_name:string, english_name:?string}>
     */
    public function getAllSuppliers(): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->query('SELECT id, official_name, display_name as english_name, normalized_name FROM suppliers');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find suppliers by entity anchor (contains)
     * Used by AnchorSignalFeeder
     * 
     * @param string $anchor Entity anchor to search for
     * @return array<int, array{id:int, official_name:string, normalized_name:string}>
     */
    public function findByAnchor(string $anchor): array
    {
        $pdo = Database::connection();
        // Search in normalized_name for suppliers containing the anchor
        $stmt = $pdo->prepare('SELECT id, official_name, normalized_name FROM suppliers WHERE normalized_name LIKE :anchor');
        $stmt->execute(['anchor' => "%{$anchor}%"]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Count how many suppliers contain this anchor
     * Used by AnchorSignalFeeder for uniqueness calculation
     * 
     * @param string $anchor Entity anchor
     * @return int Count of suppliers containing this anchor
     */
    public function countSuppliersWithAnchor(string $anchor): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM suppliers WHERE normalized_name LIKE :anchor');
        $stmt->execute(['anchor' => "%{$anchor}%"]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Find supplier by ID (returns array, not Supplier object)
     * Used by SuggestionFormatter
     * 
     * @param int $id
     * @return array|null
     */
    public function findById(int $id): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, official_name, display_name as english_name, normalized_name FROM suppliers WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function map(array $row): Supplier
    {
        return new Supplier(
            (int) $row['id'],
            $row['official_name'],
            $row['display_name'] ?? null,
            $row['normalized_name'],
            $row['supplier_normalized_key'] ?? null,
            (int) $row['is_confirmed'],
            $row['created_at'] ?? null,
        );
    }
}
