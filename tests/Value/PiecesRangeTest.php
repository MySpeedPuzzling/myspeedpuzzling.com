<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\PiecesFilter;
use SpeedPuzzling\Web\Value\PiecesRange;

final class PiecesRangeTest extends TestCase
{
    public function testBetween(): void
    {
        $range = PiecesRange::between(500, 1000);

        self::assertSame(500, $range->minPieces);
        self::assertSame(1000, $range->maxPieces);
        self::assertFalse($range->isUnbounded());

        $exact = PiecesRange::between(500, 500);
        self::assertSame(500, $exact->minPieces);
        self::assertSame(500, $exact->maxPieces);

        $openEnded = PiecesRange::between(2000, null);
        self::assertSame(2000, $openEnded->minPieces);
        self::assertNull($openEnded->maxPieces);

        self::assertTrue(PiecesRange::between(null, null)->isUnbounded());
        self::assertTrue(PiecesRange::any()->isUnbounded());
    }

    public function testContradictingBoundsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PiecesRange::between(1000, 500);
    }

    /**
     * @return iterable<string, array{PiecesFilter, null|int, null|int}>
     */
    public static function filters(): iterable
    {
        yield 'any' => [PiecesFilter::Any, null, null];
        yield 'up to 499' => [PiecesFilter::UpTo499, 1, 499];
        yield 'exactly 500' => [PiecesFilter::Exactly500, 500, 500];
        yield '501-999' => [PiecesFilter::UpTo999, 501, 999];
        yield 'exactly 1000' => [PiecesFilter::Exactly1000, 1000, 1000];
        yield 'more than 1000' => [PiecesFilter::MoreThan1000, 1001, null];
    }

    /**
     * The website's fixed ranges map onto the same bounds PiecesFilter always
     * expressed - the search query behaves identically for every web caller.
     */
    #[DataProvider('filters')]
    public function testFromFilter(PiecesFilter $filter, null|int $expectedMin, null|int $expectedMax): void
    {
        $range = PiecesRange::fromFilter($filter);

        self::assertSame($expectedMin, $range->minPieces);
        self::assertSame($expectedMax, $range->maxPieces);
    }
}
