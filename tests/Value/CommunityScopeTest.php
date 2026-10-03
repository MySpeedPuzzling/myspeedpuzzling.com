<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\CommunityScope;
use SpeedPuzzling\Web\Value\CountryCode;

final class CommunityScopeTest extends TestCase
{
    public function testACountryCodeOpensThatCountry(): void
    {
        $scope = CommunityScope::fromQuery('CZ');

        self::assertFalse($scope->isWorld());
        self::assertSame(CountryCode::cz, $scope->country);
        self::assertSame('cz', $scope->key());
        self::assertSame('cz', $scope->queryValue());
    }

    #[DataProvider('provideWorldValues')]
    public function testAnythingElseIsTheWorld(mixed $value): void
    {
        $scope = CommunityScope::fromQuery($value);

        self::assertTrue($scope->isWorld());
        self::assertSame('world', $scope->key());
        self::assertNull($scope->queryValue());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideWorldValues(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'world' => ['world'];
        yield 'unknown code' => ['xx'];
        yield 'not a string' => [['cz']];
    }

    public function testScopesWithTheSameKeyAreEqual(): void
    {
        self::assertTrue(CommunityScope::fromQuery('de')->equals(CommunityScope::country(CountryCode::de)));
        self::assertFalse(CommunityScope::world()->equals(CommunityScope::country(CountryCode::de)));
    }
}
