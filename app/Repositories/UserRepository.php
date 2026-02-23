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
