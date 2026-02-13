<?php
declare(strict_types=1);

namespace App\Models;

class LearningLog
{
    public function __construct(
        public ?int $id,
        public string $rawInput,
        public string $normalizedInput,
        public ?int $suggestedSupplierId,
        public string $decisionResult,
        public ?string $createdAt = null,
    ) {
    }
}
