<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Query\OccurrenceRounds;
use SpeedPuzzling\Web\Value\RoundTimezone;

/**
 * The rounds JSON of the occurrence statements (OccurrenceRounds::fromJson()): the zone each round is read in, whether
 * it is only assumed, and the per-round results flag of SQL_JOIN_WITH_RESULTS.
 */
final class OccurrenceRoundsTest extends TestCase
{
    public function testReadsTheResultsFlagAndTheZone(): void
    {
        $rounds = OccurrenceRounds::fromJson(json_encode([
            ['id' => 'a', 'name' => 'Sprint 1', 'starts_at' => '2026-06-17 02:00:00', 'timezone' => 'America/New_York', 'has_results' => true],
            ['id' => 'b', 'name' => 'Sprint 2', 'starts_at' => '2026-07-15 02:00:00', 'timezone' => null, 'has_results' => false],
            ['id' => 'c', 'name' => 'Sprint 3', 'starts_at' => '2026-08-12 02:00:00'],
        ], JSON_THROW_ON_ERROR), 'us');

        self::assertSame([true, false, false], array_map(static fn ($round): bool => $round->hasResults, $rounds));
        self::assertSame(['America/New_York', 'America/New_York', 'America/New_York'], array_map(static fn ($round): string => $round->zone, $rounds));
        self::assertSame([false, false, false], array_map(static fn ($round): bool => $round->zoneAssumed, $rounds));
        self::assertSame('2026-06-17T02:00:00+00:00', $rounds[0]->startsAt->format(DATE_ATOM));
    }

    public function testAZoneWithoutAnyCountryIsAssumed(): void
    {
        $rounds = OccurrenceRounds::fromJson(json_encode([
            ['id' => 'a', 'name' => 'Round', 'starts_at' => '2026-06-17 18:00:00', 'timezone' => null],
            ['id' => 'b', 'name' => 'Round', 'starts_at' => '2026-06-18 18:00:00', 'timezone' => 'America/Toronto'],
        ], JSON_THROW_ON_ERROR), null, null);

        self::assertSame(RoundTimezone::FALLBACK, $rounds[0]->zone);
        self::assertTrue($rounds[0]->zoneAssumed);
        self::assertSame('America/Toronto', $rounds[1]->zone);
        self::assertFalse($rounds[1]->zoneAssumed);
    }

    public function testNoRoundsIsAnEmptyList(): void
    {
        self::assertSame([], OccurrenceRounds::fromJson(null));
        self::assertSame([], OccurrenceRounds::fromJson('not json'));
    }
}
