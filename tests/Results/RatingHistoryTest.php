<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Results;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\RatingHistory;

final class RatingHistoryTest extends TestCase
{
    /**
     * 2026-11-14: #278 in the morning, #279 at noon after a newly rated player went ahead - "Best place" showed #278
     * (14 Nov) and then #279 (13 Nov). The day's best place counts, not its last one.
     */
    public function testAPlaceReachedEarlierInTheDayStaysTheBestPlace(): void
    {
        $history = RatingHistory::fromSnapshots([
            self::day('2026-11-13', place: 279, bestPlace: 279),
            self::day('2026-11-14', place: 279, bestPlace: 278),
        ]);

        self::assertSame(278, $history->bestPlace);
        self::assertEquals(new DateTimeImmutable('2026-11-14'), $history->bestPlaceOn);
        self::assertSame(2, $history->daysOnLadder);
    }

    public function testRowsWithoutTheDaysBestPlaceFallBackToThePlace(): void
    {
        $history = RatingHistory::fromSnapshots([
            self::day('2026-11-12', place: 280, bestPlace: null),
            self::day('2026-11-13', place: 279, bestPlace: null),
            self::day('2026-11-14', place: 279, bestPlace: 279),
        ]);

        self::assertSame(279, $history->bestPlace);
        self::assertEquals(new DateTimeImmutable('2026-11-13'), $history->bestPlaceOn, 'The first day the best place was reached');
    }

    public function testADayOffTheLadderKeepsAPlaceReachedEarlierThatDay(): void
    {
        $history = RatingHistory::fromSnapshots([
            self::day('2026-11-13', place: 300, bestPlace: 300),
            self::day('2026-11-14', place: null, bestPlace: 290),
        ]);

        self::assertSame(290, $history->bestPlace);
        self::assertSame(1, $history->daysOnLadder);
    }

    /**
     * @return array{pieces_count: int, date: DateTimeImmutable, rating: float, place: null|int, best_place: null|int}
     */
    private static function day(string $date, null|int $place, null|int $bestPlace): array
    {
        return [
            'pieces_count' => 500,
            'date' => new DateTimeImmutable($date),
            'rating' => 0.586,
            'place' => $place,
            'best_place' => $bestPlace,
        ];
    }
}
