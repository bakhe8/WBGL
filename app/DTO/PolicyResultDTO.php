<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * PolicyResultDTO
 *
 * Canonical access decision envelope:
 * visible -> actionable -> executable
 */
class PolicyResultDTO
{
    /**
     * @param array<int,string> $reasonCodes
     */
    public function __construct(
        public bool $visible,
        public bool $actionable,
        public bool $executable,
        public array $reasonCodes = []
    ) {
        $this->reasonCodes = array_values(array_unique(array_filter(array_map(
            static fn($reason): string => trim((string)$reason),
            $this->reasonCodes
        ), static fn(string $reason): bool => $reason !== '')));
    }

    public function toArray(): array
    {
        return [
            'visible' => $this->visible,
            'actionable' => $this->actionable,
            'executable' => $this->executable,
            'reasons' => $this->reasonCodes,
        ];
    }
}

