<?php
declare(strict_types=1);

namespace App\Models;

class SupplierAlternativeName
{
    public function __construct(
        public ?int $id,
        public int $supplierId,
        public string $rawName,
        public string $normalizedRawName,
        public string $source,
        public int $occurrenceCount = 1,
        public ?string $lastSeenAt = null,
    ) {
    }
}
