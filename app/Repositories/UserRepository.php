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
        $columns = ['username', 'password_hash', 'full_name', 'email', 'role_id'];
        $values = [
            $user->username,
            $user->passwordHash,
            $user->fullName,
            $user->email,
            $user->roleId,
        ];

        if ($this->hasPreferredLanguageColumn()) {
            $columns[] = 'preferred_language';
            $values[] = $this->normalizeLanguage($user->preferredLanguage);
        }
        if ($this->hasPreferredThemeColumn()) {
            $columns[] = 'preferred_theme';
            $values[] = $this->normalizeTheme($user->preferredTheme);
        }
        if ($this->hasPreferredDirectionColumn()) {
            $columns[] = 'preferred_direction';
            $values[] = $this->normalizeDirection($user->preferredDirection);
        }

        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $sql = 'INSERT INTO users (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);

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
        $sets = [
            'username = ?',
            'password_hash = ?',
            'full_name = ?',
            'email = ?',
            'role_id = ?',
        ];
        $values = [
            $user->username,
            $user->passwordHash,
            $user->fullName,
            $user->email,
            $user->roleId,
        ];

        if ($this->hasPreferredLanguageColumn()) {
            $sets[] = 'preferred_language = ?';
            $values[] = $this->normalizeLanguage($user->preferredLanguage);
        }
        if ($this->hasPreferredThemeColumn()) {
            $sets[] = 'preferred_theme = ?';
            $values[] = $this->normalizeTheme($user->preferredTheme);
        }
        if ($this->hasPreferredDirectionColumn()) {
            $sets[] = 'preferred_direction = ?';
            $values[] = $this->normalizeDirection($user->preferredDirection);
        }

        $values[] = $user->id;

        $sql = 'UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($values);
    }

    public function updatePreferredLanguage(int $userId, string $language): void
    {
        if (!$this->hasPreferredLanguageColumn()) {
            return;
        }
        $stmt = $this->db->prepare("UPDATE users SET preferred_language = ? WHERE id = ?");
        $stmt->execute([$this->normalizeLanguage($language), $userId]);
    }

    public function updateUiPreferences(
        int $userId,
        ?string $language = null,
        ?string $theme = null,
        ?string $direction = null
    ): void {
        $sets = [];
        $values = [];

        if ($language !== null && $this->hasPreferredLanguageColumn()) {
            $sets[] = 'preferred_language = ?';
            $values[] = $this->normalizeLanguage($language);
        }
        if ($theme !== null && $this->hasPreferredThemeColumn()) {
            $sets[] = 'preferred_theme = ?';
            $values[] = $this->normalizeTheme($theme);
        }
        if ($direction !== null && $this->hasPreferredDirectionColumn()) {
            $sets[] = 'preferred_direction = ?';
            $values[] = $this->normalizeDirection($direction);
        }

        if (empty($sets)) {
            return;
        }

        $values[] = $userId;
        $sql = 'UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);
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
            $this->normalizeLanguage((string)($row['preferred_language'] ?? 'ar')),
            $this->normalizeTheme((string)($row['preferred_theme'] ?? 'system')),
            $this->normalizeDirection((string)($row['preferred_direction'] ?? 'auto')),
            $row['last_login'] ?? null,
            $row['created_at']
        );
    }

    private function normalizeLanguage(string $language): string
    {
        $language = strtolower(trim($language));
        return in_array($language, ['ar', 'en'], true) ? $language : 'ar';
    }

    private function normalizeTheme(string $theme): string
    {
        $theme = strtolower(trim($theme));
        return in_array($theme, ['system', 'light', 'dark', 'desert'], true) ? $theme : 'system';
    }

    private function normalizeDirection(string $direction): string
    {
        $direction = strtolower(trim($direction));
        return in_array($direction, ['auto', 'rtl', 'ltr'], true) ? $direction : 'auto';
    }

    private function hasPreferredLanguageColumn(): bool
    {
        return true;
    }

    private function hasPreferredThemeColumn(): bool
    {
        return true;
    }

    private function hasPreferredDirectionColumn(): bool
    {
        return true;
    }
}
