<?php

declare(strict_types=1);

use App\Support\DirectionResolver;
use PHPUnit\Framework\TestCase;

final class DirectionResolverTest extends TestCase
{
    public function testUserOverrideTakesPrecedence(): void
    {
        $resolved = DirectionResolver::resolve('ar', 'ltr', 'rtl');

        $this->assertSame('ltr', $resolved['direction']);
        $this->assertSame('user_override', $resolved['source']);
        $this->assertSame('ltr', $resolved['override']);
    }

    public function testOrganizationOverrideUsedWhenUserIsAuto(): void
    {
        $resolved = DirectionResolver::resolve('en', 'auto', 'rtl');

        $this->assertSame('rtl', $resolved['direction']);
        $this->assertSame('org_default', $resolved['source']);
        $this->assertSame('rtl', $resolved['override']);
    }

    public function testLocaleDirectionUsedWhenOverridesAreAuto(): void
    {
        $resolvedAr = DirectionResolver::resolve('ar', 'auto', 'auto');
        $resolvedEn = DirectionResolver::resolve('en', 'auto', 'auto');

        $this->assertSame('rtl', $resolvedAr['direction']);
        $this->assertSame('ltr', $resolvedEn['direction']);
        $this->assertSame('locale', $resolvedAr['source']);
        $this->assertSame('locale', $resolvedEn['source']);
    }
}
