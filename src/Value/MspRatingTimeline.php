<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The days that matter on the MSP Rating's history (docs/features/msp-rating-history.md): the days the rating formula
 * changed - everybody's rating moved that day, places did not - and the start of the next season. A points change
 * never spans a formula change ("–" instead of a drop nobody caused), the charts mark both kinds of day.
 *
 * A new formula version adds a line here, dated by the first snapshot day it produced.
 */
final class MspRatingTimeline
{
    /**
     * The first snapshot day each new formula produced: 2026-03-31 the portfolio rating (v2), 2026-10-18 the as-of puzzle
     * difficulty (pace history)
     */
    public const array FORMULA_CHANGES = [
        '2026-03-31',
        '2026-10-18',
    ];

    /** Today's ladder is "Season 0"; the line under the ladder's title announces the next one until it starts */
    public const string NEXT_SEASON_STARTS_ON = '2027-01-01';
    public const int NEXT_SEASON = 1;
    public const int CURRENT_SEASON = 0;

    public static function nextSeasonStartsOn(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NEXT_SEASON_STARTS_ON);
    }

    public static function nextSeasonAnnounced(DateTimeImmutable $now): bool
    {
        return $now < self::nextSeasonStartsOn();
    }

    /**
     * The stored day a movement compares today's ladder with: $days before today (UTC days, like the snapshots), but
     * never before the latest formula change - a formula moves every place and every rating at once, so a week across
     * one would show the formula. Null while that day is today: there is nothing to compare yet.
     */
    public static function moveReferenceDay(DateTimeImmutable $now, int $days): null|DateTimeImmutable
    {
        $today = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
        $reference = (new DateTimeImmutable($today))->modify("-{$days} days")->format('Y-m-d');

        foreach (self::FORMULA_CHANGES as $day) {
            if ($day > $reference && $day <= $today) {
                $reference = $day;
            }
        }

        return $reference < $today ? new DateTimeImmutable($reference) : null;
    }

    /**
     * The oldest stored day a movement may fall back to when the reference day itself is missing: never before the latest
     * formula change, and at most $days days before the reference day - a stale day says nothing about "this week"
     */
    public static function moveNotBefore(DateTimeImmutable $referenceDay, int $days): DateTimeImmutable
    {
        $reference = $referenceDay->format('Y-m-d');
        $floor = $referenceDay->modify("-{$days} days")->format('Y-m-d');

        foreach (self::FORMULA_CHANGES as $day) {
            if ($day > $floor && $day <= $reference) {
                $floor = $day;
            }
        }

        return new DateTimeImmutable($floor);
    }

    /**
     * Whether ratings of the two snapshot days were computed by one formula - no change after $from up to $to
     */
    public static function pointsComparable(DateTimeImmutable $from, DateTimeImmutable $to): bool
    {
        $fromDay = $from->format('Y-m-d');
        $toDay = $to->format('Y-m-d');

        foreach (self::FORMULA_CHANGES as $day) {
            if ($day > $fromDay && $day <= $toDay) {
                return false;
            }
        }

        return true;
    }

    /**
     * The marked days from $from to $to (both included), oldest first
     *
     * @return list<array{date: DateTimeImmutable, kind: 'formula'|'season', key: string}>
     */
    public static function markersBetween(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $fromDay = $from->format('Y-m-d');
        $toDay = $to->format('Y-m-d');
        $markers = [];

        foreach (self::FORMULA_CHANGES as $day) {
            if ($day >= $fromDay && $day <= $toDay) {
                $markers[] = ['date' => new DateTimeImmutable($day), 'kind' => 'formula', 'key' => $day];
            }
        }

        if (self::NEXT_SEASON_STARTS_ON >= $fromDay && self::NEXT_SEASON_STARTS_ON <= $toDay) {
            $markers[] = ['date' => self::nextSeasonStartsOn(), 'kind' => 'season', 'key' => (string) self::NEXT_SEASON];
        }

        usort($markers, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);

        return $markers;
    }
}
