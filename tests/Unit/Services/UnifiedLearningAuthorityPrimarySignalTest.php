<?php
declare(strict_types=1);

use App\DTO\SignalDTO;
use App\Services\Learning\ConfidenceCalculatorV2;
use App\Services\Learning\SuggestionFormatter;
use App\Services\Learning\UnifiedLearningAuthority;
use App\Support\Normalizer;
use PHPUnit\Framework\TestCase;

final class UnifiedLearningAuthorityPrimarySignalTest extends TestCase
{
    public function testAuthorityDelegatesPrimarySignalResolutionToCalculator(): void
    {
        $normalizer = $this->createStub(Normalizer::class);
        $calculator = $this->createMock(ConfidenceCalculatorV2::class);
        $formatter = $this->createStub(SuggestionFormatter::class);

        $authority = new UnifiedLearningAuthority($normalizer, $calculator, $formatter);

        $signals = [
            new SignalDTO(1, 'historical_occasional', 0.70, []),
            new SignalDTO(1, 'override_exact', 1.0, []),
        ];

        $calculator
            ->expects($this->once())
            ->method('resolvePrimarySignal')
            ->with($signals)
            ->willReturn($signals[1]);

        /** @var SignalDTO $primary */
        $primary = $this->invokePrivate($authority, 'identifyPrimarySignal', [$signals]);

        $this->assertSame($signals[1], $primary);
    }

    /**
     * @param array<int,mixed> $args
     */
    private function invokePrivate(object $instance, string $method, array $args = []): mixed
    {
        $ref = new ReflectionClass($instance);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs($instance, $args);
    }
}
