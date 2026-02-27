<?php

declare(strict_types=1);

namespace App\Models;

/**
 * User Model
 */
class User
{
    public function __construct(
        public ?int $id,
        public string $username,
        public string $passwordHash,
        public string $fullName,
        public ?string $email = null,
        public ?int $roleId = null,
        public string $preferredLanguage = 'ar',
        public string $preferredTheme = 'system',
        public string $preferredDirection = 'auto',
        public ?string $lastLogin = null,
        public ?string $createdAt = null
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'full_name' => $this->fullName,
            'email' => $this->email,
            'role_id' => $this->roleId,
            'preferred_language' => $this->preferredLanguage,
            'preferred_theme' => $this->preferredTheme,
            'preferred_direction' => $this->preferredDirection,
            'last_login' => $this->lastLogin,
            'created_at' => $this->createdAt,
        ];
    }
}
