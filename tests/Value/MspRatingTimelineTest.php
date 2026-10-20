<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\MspRatingTimeline;

final class MspRatingTimelineTest extends TestCase
{
    public function testTheMovementComparesWithTheDayAWeekAgo(): void
    {
        $reference = MspRatingTimeline::moveReferenceDay(new DateTimeImmutable('2026-06-15 10:00', new DateTimeZone('UTC')), 7);

        self::assertNotNull($reference);
        self::assertSame('2026-06-08', $reference->format('Y-m-d'));
    }

    public function testTheMovementNeverReachesBackAcrossAFormulaChange(): void
    {
        // A week before 20 Oct is 13 Oct, but the formula changed on 18 Oct
        $reference = MspRatingTimeline::moveReferenceDay(new DateTimeImmutable('2026-10-20 10:00', new DateTimeZone('UTC')), 7);

        self::assertNotNull($reference);
        self::assertSame('2026-10-18', $reference->format('Y-m-d'));
    }

    public function testNothingIsComparedOnTheDayOfAFormulaChange(): void
    {
        self::assertNull(MspRatingTimeline::moveReferenceDay(new DateTimeImmutable('2026-10-18 23:00', new DateTimeZone('UTC')), 7));
    }

    public function testTheDayIsTheUtcDayLikeTheSnapshots(): void
    {
        // 01:30 in Prague on 16 Jun is still 15 Jun in UTC
        $reference = MspRatingTimeline::moveReferenceDay(new DateTimeImmutable('2026-06-16 01:30', new DateTimeZone('Europe/Prague')), 7);

        self::assertNotNull($reference);
        self::assertSame('2026-06-08', $reference->format('Y-m-d'));
    }

    public function testAFallbackDayIsNeverBeforeTheFormulaChangeNorMuchOlder(): void
    {
        self::assertSame('2026-10-18', MspRatingTimeline::moveNotBefore(new DateTimeImmutable('2026-10-18'), 7)->format('Y-m-d'));
        self::assertSame('2026-06-01', MspRatingTimeline::moveNotBefore(new DateTimeImmutable('2026-06-08'), 7)->format('Y-m-d'));
    }

    public function testPointsAreComparableOnlyWithinOneFormula(): void
    {
        self::assertTrue(MspRatingTimeline::pointsComparable(new DateTimeImmutable('2026-06-08'), new DateTimeImmutable('2026-06-15')));
        self::assertFalse(MspRatingTimeline::pointsComparable(new DateTimeImmutable('2026-10-13'), new DateTimeImmutable('2026-10-20')));
        // The change day itself is already the new formula
        self::assertTrue(MspRatingTimeline::pointsComparable(new DateTimeImmutable('2026-10-18'), new DateTimeImmutable('2026-10-20')));
    }

    public function testMarkersAreTheFormulaChangesAndTheNextSeasonInRange(): void
    {
        $markers = MspRatingTimeline::markersBetween(new DateTimeImmutable('2026-03-30'), new DateTimeImmutable('2027-01-05'));

        self::assertSame(
            [['2026-03-31', 'formula'], ['2026-10-18', 'formula'], [MspRatingTimeline::NEXT_SEASON_STARTS_ON, 'season']],
            array_map(static fn (array $marker): array => [$marker['date']->format('Y-m-d'), $marker['kind']], $markers),
        );
        self::assertSame([], MspRatingTimeline::markersBetween(new DateTimeImmutable('2026-05-01'), new DateTimeImmutable('2026-06-01')));
    }

    public function testTheNextSeasonIsAnnouncedUntilItStarts(): void
    {
        self::assertTrue(MspRatingTimeline::nextSeasonAnnounced(new DateTimeImmutable('2026-12-31 23:00')));
        self::assertFalse(MspRatingTimeline::nextSeasonAnnounced(new DateTimeImmutable(MspRatingTimeline::NEXT_SEASON_STARTS_ON . ' 00:00')));
    }
}
