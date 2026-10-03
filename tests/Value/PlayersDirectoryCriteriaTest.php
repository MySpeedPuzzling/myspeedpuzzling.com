<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\CommunityScope;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\PlayersDirectoryCriteria;
use SpeedPuzzling\Web\Value\PlayersDirectorySort;

final class PlayersDirectoryCriteriaTest extends TestCase
{
    public function testNothingInTheUrlIsTheDefaultList(): void
    {
        $criteria = PlayersDirectoryCriteria::fromQuery([], CommunityScope::world());

        self::assertSame(PlayersDirectorySort::Active, $criteria->sort);
        self::assertFalse($criteria->hasFilters());
        self::assertSame(PlayersDirectoryCriteria::PAGE_SIZE, $criteria->limit);
        self::assertSame([], $criteria->queryParameters());
        self::assertSame(48, $criteria->nextLimit());
    }

    public function testEverythingReadFromTheUrlGoesBackIntoIt(): void
    {
        $criteria = PlayersDirectoryCriteria::fromQuery(
            ['active' => '1', 'events' => 'on', 'swaps' => '1', 'instagram' => 'true', 'sort' => 'followed', 'limit' => '48', 'scope' => 'ignored'],
            CommunityScope::country(CountryCode::cz),
        );

        self::assertTrue($criteria->activeThisMonth);
        self::assertTrue($criteria->competesInEvents);
        self::assertTrue($criteria->swapsPuzzles);
        self::assertTrue($criteria->onInstagram);
        self::assertTrue($criteria->hasFilters());
        self::assertSame(PlayersDirectorySort::Followed, $criteria->sort);
        self::assertSame(CountryCode::cz, $criteria->scope->country);
        self::assertSame(
            ['active' => 1, 'events' => 1, 'swaps' => 1, 'instagram' => 1, 'sort' => 'followed', 'limit' => 48],
            $criteria->queryParameters(),
        );
    }

    #[DataProvider('provideUnknownValues')]
    public function testUnknownValuesFallBackToTheDefaults(mixed $value): void
    {
        $criteria = PlayersDirectoryCriteria::fromQuery(
            ['active' => $value, 'events' => $value, 'sort' => $value, 'limit' => $value],
            CommunityScope::world(),
        );

        self::assertFalse($criteria->hasFilters());
        self::assertSame(PlayersDirectorySort::Active, $criteria->sort);
        self::assertSame(PlayersDirectoryCriteria::PAGE_SIZE, $criteria->limit);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideUnknownValues(): iterable
    {
        yield 'empty' => [''];
        yield 'zero' => ['0'];
        yield 'nonsense' => ['banana'];
        yield 'negative' => ['-24'];
        yield 'array' => [['1']];
        yield 'null' => [null];
    }

    #[DataProvider('provideLimits')]
    public function testTheLimitIsWholePagesUpToTheMaximum(string $limit, int $expected): void
    {
        self::assertSame($expected, PlayersDirectoryCriteria::fromQuery(['limit' => $limit], CommunityScope::world())->limit);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideLimits(): iterable
    {
        yield 'one page' => ['24', 24];
        yield 'two pages' => ['48', 48];
        yield 'rounded up to a page' => ['30', 48];
        yield 'zero is one page' => ['0', 24];
        yield 'at most the maximum' => ['100000', PlayersDirectoryCriteria::MAX_LIMIT];
        yield 'huge' => ['99999999999999999999999', PlayersDirectoryCriteria::MAX_LIMIT];
    }

    public function testShowMoreStopsAtTheMaximum(): void
    {
        $criteria = PlayersDirectoryCriteria::fromQuery(['limit' => (string) PlayersDirectoryCriteria::MAX_LIMIT], CommunityScope::world());

        self::assertNull($criteria->nextLimit());
    }
}
