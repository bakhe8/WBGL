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

    /**
     * @return int[]
     */
    public function getPermissionIds(int $roleId): array
    {
        $stmt = $this->db->prepare('SELECT permission_id FROM role_permissions WHERE role_id = ? ORDER BY permission_id ASC');
        $stmt->execute([$roleId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function create(string $name, string $slug, ?string $description = null, array $permissionIds = []): Role
    {
        $stmt = $this->db->prepare('INSERT INTO roles (name, slug, description) VALUES (?, ?, ?) RETURNING id');
        $stmt->execute([$name, $slug, $description]);

        $roleId = (int)$stmt->fetchColumn();
        $this->syncPermissions($roleId, $permissionIds);

        $role = $this->find($roleId);
        if (!$role) {
            throw new \RuntimeException('Failed to create role.');
        }
        return $role;
    }

    public function updateRole(int $id, string $name, string $slug, ?string $description = null, array $permissionIds = []): ?Role
    {
        $stmt = $this->db->prepare('UPDATE roles SET name = ?, slug = ?, description = ? WHERE id = ?');
        $stmt->execute([$name, $slug, $description, $id]);

        $this->syncPermissions($id, $permissionIds);
        return $this->find($id);
    }

    public function deleteRole(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM roles WHERE id = ?');
        return $stmt->execute([$id]);
    }

    public function countUsersByRole(int $roleId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM users WHERE role_id = ?');
        $stmt->execute([$roleId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * @param int[] $permissionIds
     */
    public function syncPermissions(int $roleId, array $permissionIds): void
    {
        $permissionIds = array_values(array_unique(array_filter(array_map('intval', $permissionIds), static function (int $id): bool {
            return $id > 0;
        })));

        $this->db->beginTransaction();
        try {
            $delete = $this->db->prepare('DELETE FROM role_permissions WHERE role_id = ?');
            $delete->execute([$roleId]);

            if (!empty($permissionIds)) {
                $insert = $this->db->prepare('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)');
                foreach ($permissionIds as $permissionId) {
                    $insert->execute([$roleId, $permissionId]);
                }
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
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
