<?php
declare(strict_types=1);

use App\Repositories\SupplierRepository;
use App\Services\Learning\Feeders\FuzzySignalFeeder;
use App\Support\Normalizer;
use PHPUnit\Framework\TestCase;

final class FuzzySignalFeederUnicodeTest extends TestCase
{
    public function testCalculateSimilarityUsesUnicodeCharactersForArabic(): void
    {
        $feeder = new FuzzySignalFeeder(
            $this->createStub(SupplierRepository::class),
            $this->createStub(Normalizer::class)
        );

        /** @var float $similarity */
        $similarity = $this->invokePrivate(
            $feeder,
            'calculateSimilarity',
            ['مؤسسة الاحمدي', 'مؤسسة الأحمدي']
        );

        $this->assertGreaterThanOrEqual(0.90, $similarity);
    }

    public function testDistinctiveKeywordMatchRejectsSingleSidedDistinctiveNames(): void
    {
        $feeder = new FuzzySignalFeeder(
            $this->createStub(SupplierRepository::class),
            $this->createStub(Normalizer::class)
        );

        /** @var bool $result */
        $result = $this->invokePrivate(
            $feeder,
            'hasDistinctiveKeywordMatch',
            ['شركة مؤسسة', 'شركة الاحمدي']
        );

        $this->assertFalse($result);
    }

    public function testDistinctiveKeywordMatchAllowsExactGenericOnlyNames(): void
    {
        $feeder = new FuzzySignalFeeder(
            $this->createStub(SupplierRepository::class),
            $this->createStub(Normalizer::class)
        );

        /** @var bool $sameGeneric */
        $sameGeneric = $this->invokePrivate(
            $feeder,
            'hasDistinctiveKeywordMatch',
            ['شركة مؤسسة', 'شركة مؤسسة']
        );

        /** @var bool $differentGeneric */
        $differentGeneric = $this->invokePrivate(
            $feeder,
            'hasDistinctiveKeywordMatch',
            ['شركة مؤسسة', 'مؤسسة شركة']
        );

        $this->assertTrue($sameGeneric);
        $this->assertFalse($differentGeneric);
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
