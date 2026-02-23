<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\User;
use PDO;

/**
 * UserRepository
 */
class UserRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function find(int $id): ?User
    {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrate($row) : null;
    }

    public function findByUsername(string $username): ?User
    {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrate($row) : null;
    }

    public function create(User $user): User
    {
        $stmt = $this->db->prepare("
            INSERT INTO users (username, password_hash, full_name, email, role_id)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $user->username,
            $user->passwordHash,
            $user->fullName,
            $user->email,
            $user->roleId
        ]);

        $id = (int)$this->db->lastInsertId();
        return $this->find($id);
    }

    public function updateLastLogin(int $userId): void
    {
        $stmt = $this->db->prepare("UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$userId]);
    }

    public function update(User $user): bool
    {
        $stmt = $this->db->prepare("
            UPDATE users
            SET username = ?,
                password_hash = ?,
                full_name = ?,
                email = ?,
                role_id = ?
            WHERE id = ?
        ");
        return $stmt->execute([
            $user->username,
            $user->passwordHash,
            $user->fullName,
            $user->email,
            $user->roleId,
            $user->id
        ]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM users WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Get granular overrides for a user
     */
    public function getPermissionsOverrides(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT p.id, p.slug, up.override_type
            FROM user_permissions up
            JOIN permissions p ON p.id = up.permission_id
            WHERE up.user_id = ?
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Sync granular overrides for a user
     */
    public function syncPermissionsOverrides(int $userId, array $overrides): void
    {
        $this->db->beginTransaction();
        try {
            // Clear existing
            $stmt = $this->db->prepare("DELETE FROM user_permissions WHERE user_id = ?");
            $stmt->execute([$userId]);

            // Insert new
            $insert = $this->db->prepare("
                INSERT INTO user_permissions (user_id, permission_id, override_type)
                VALUES (?, ?, ?)
            ");

            foreach ($overrides as $ov) {
                if (isset($ov['permission_id']) && isset($ov['type'])) {
                    $insert->execute([$userId, $ov['permission_id'], $ov['type']]);
                }
            }

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function hydrate(array $row): User
    {
        return new User(
            (int)$row['id'],
            $row['username'],
            $row['password_hash'],
            $row['full_name'],
            $row['email'] ?? null,
            isset($row['role_id']) ? (int)$row['role_id'] : null,
            $row['last_login'] ?? null,
            $row['created_at']
        );
    }
}
