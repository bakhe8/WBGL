<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Role;
use PDO;

/**
 * RoleRepository
 */
class RoleRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function find(int $id): ?Role
    {
        $stmt = $this->db->prepare("SELECT * FROM roles WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrate($row) : null;
    }

    public function findBySlug(string $slug): ?Role
    {
        $stmt = $this->db->prepare("SELECT * FROM roles WHERE slug = ?");
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrate($row) : null;
    }

    public function all(): array
    {
        $stmt = $this->db->query("SELECT * FROM roles ORDER BY id ASC");
        $roles = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $roles[] = $this->hydrate($row);
        }
        return $roles;
    }

    public function getPermissions(int $roleId): array
    {
        $stmt = $this->db->prepare("
            SELECT p.slug
            FROM permissions p
            JOIN role_permissions rp ON p.id = rp.permission_id
            WHERE rp.role_id = ?
        ");
        $stmt->execute([$roleId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function hydrate(array $row): Role
    {
        return new Role(
            (int)$row['id'],
            $row['name'],
            $row['slug'],
            $row['description'] ?? null,
            $row['created_at']
        );
    }
}
