<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Results;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\RatingMove;

final class RatingMoveTest extends TestCase
{
    public function testPlacesAndPointsAgainstTheComparedDay(): void
    {
        $move = RatingMove::between(rank: 12, rating: 0.9123, previousRank: 15, previousRating: 0.9031, referenceDay: new DateTimeImmutable('2026-06-08'), now: new DateTimeImmutable('2026-06-15'));

        self::assertFalse($move->isNew);
        self::assertSame(3, $move->placesGained);
        self::assertSame(9, $move->pointsChange);
    }

    public function testNotOnTheListThenIsNew(): void
    {
        $move = RatingMove::between(rank: 40, rating: 0.7, previousRank: null, previousRating: null, referenceDay: new DateTimeImmutable('2026-06-08'), now: new DateTimeImmutable('2026-06-15'));

        self::assertTrue($move->isNew);
        self::assertNull($move->placesGained);
        self::assertNull($move->pointsChange);
    }

    public function testNoPointsAcrossAFormulaChangeButStillPlaces(): void
    {
        $move = RatingMove::between(rank: 10, rating: 0.8, previousRank: 8, previousRating: 0.9, referenceDay: new DateTimeImmutable('2026-10-13'), now: new DateTimeImmutable('2026-10-20'));

        self::assertSame(-2, $move->placesGained);
        self::assertNull($move->pointsChange);
    }
}
