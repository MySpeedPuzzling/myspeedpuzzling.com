<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\PlayerRanking;
use SpeedPuzzling\Web\Value\PlacingFilter;

final class PlacingFilterTest extends TestCase
{
    /**
     * @return iterable<string, array{PlacingFilter, int, int, bool}>
     */
    public static function providePlacings(): iterable
    {
        yield 'top 3: third' => [PlacingFilter::Top3, 3, 500, true];
        yield 'top 3: fourth' => [PlacingFilter::Top3, 4, 500, false];
        yield 'top 3 on a board of two' => [PlacingFilter::Top3, 2, 2, true];
        yield 'top 10: tenth' => [PlacingFilter::Top10, 10, 11, true];
        yield 'top 10: eleventh' => [PlacingFilter::Top10, 11, 2000, false];
        yield 'top 10 %: exactly 10 %' => [PlacingFilter::Top10Percent, 10, 100, true];
        yield 'top 10 %: just over' => [PlacingFilter::Top10Percent, 11, 100, false];
        yield 'top 10 %: first of a board without a share' => [PlacingFilter::Top10Percent, 1, 9, false];
        yield 'top 10 %: first of the smallest board with a share' => [PlacingFilter::Top10Percent, 1, 10, true];
        yield 'top 25 %: exactly a quarter' => [PlacingFilter::Top25Percent, 25, 100, true];
        yield 'top 25 %: just over' => [PlacingFilter::Top25Percent, 26, 100, false];
        yield 'bottom half: the middle is not the bottom half' => [PlacingFilter::BottomHalf, 50, 100, false];
        yield 'bottom half: just below the middle' => [PlacingFilter::BottomHalf, 51, 100, true];
        yield 'bottom half: last of a board without a share' => [PlacingFilter::BottomHalf, 9, 9, false];
    }

    #[DataProvider('providePlacings')]
    public function testMatches(PlacingFilter $filter, int $rank, int $total, bool $expected): void
    {
        self::assertSame($expected, $filter->matches(self::ranking($rank, $total)));
    }

    public function testAPuzzleWithoutARankNeverMatches(): void
    {
        foreach (PlacingFilter::cases() as $filter) {
            self::assertFalse($filter->matches(null), $filter->value);
        }
    }

    public static function ranking(int $rank, int $total, string $puzzleId = 'puzzle-1'): PlayerRanking
    {
        return new PlayerRanking(
            playerId: 'player-1',
            rank: $rank,
            totalPlayers: $total,
            puzzleId: $puzzleId,
            puzzleName: $puzzleId,
            manufacturerName: 'Brand',
            piecesCount: 500,
            time: 1800,
            puzzleImage: null,
            puzzleImageRatio: null,
        );
    }
}
