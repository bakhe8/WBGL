<?php
declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Signals a stale-write/concurrent-update conflict on mutable guarantee state.
 */
final class ConcurrencyConflictException extends RuntimeException
{
    /** @var array<string,mixed> */
    private array $context;

    /**
     * @param array<string,mixed> $context
     */
    public function __construct(
        string $message = 'تم تحديث السجل بواسطة مستخدم آخر. يرجى إعادة التحميل ثم إعادة المحاولة.',
        array $context = []
    ) {
        parent::__construct($message, 409);
        $this->context = $context;
    }

    /**
     * @return array<string,mixed>
     */
    public function context(): array
    {
        return $this->context;
    }
}

