<?php
declare(strict_types=1);

namespace App\Models;

class Bank
{
    public function __construct(
        public ?int $id,
        public string $officialName,
        public ?string $officialNameEn = null,
        public ?string $officialNameAr = null,
        public ?string $bankNormalizedKey = null,
        public ?string $shortCode = null,
        public int $isConfirmed = 0,
        public ?string $createdAt = null,
        public ?string $department = null,
        public ?string $addressLine1 = null,
        public ?string $addressLine2 = null,
        public ?string $contactEmail = null,
    ) {
    }
}
