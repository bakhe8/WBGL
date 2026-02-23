<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Role Model
 */
class Role
{
    public function __construct(
        public ?int $id,
        public string $name,
        public string $slug,
        public ?string $description = null,
        public ?string $createdAt = null
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'created_at' => $this->createdAt,
        ];
    }
}
