<?php
declare(strict_types=1);

namespace App\Models;

class ImportSession
{
    public function __construct(
        public ?int $id,
        public string $sessionType,
        public int $recordCount = 0,
        public ?string $createdAt = null,
    ) {
    }
}
