<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Results;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\EditionRoundDetail;
use SpeedPuzzling\Web\Value\RoundCategory;

/**
 * A stopwatch started before the schedule beats it on the pages asking "has it started / is it over"
 * (docs/features/competitions-management/spws-demo-ux-leftovers.md §8.4) - a late or future stopwatch changes nothing.
 */
final class EditionRoundDetailTest extends TestCase
{
    public function testAnEarlyStopwatchStartBeatsTheSchedule(): void
    {
        $now = new DateTimeImmutable('2026-11-24 12:00:00');
        $june = new DateTimeImmutable('2027-06-03 14:00:00');

        self::assertEquals($june, self::round($june, null)->startedAt($now), 'no stopwatch: the schedule');
        self::assertEquals(new DateTimeImmutable('2026-11-24 11:50:00'), self::round($june, new DateTimeImmutable('2026-11-24 11:50:00'))->startedAt($now), 'started early: running now');
        self::assertEquals($june, self::round($june, new DateTimeImmutable('2026-11-24 12:30:00'))->startedAt($now), 'a countdown still ahead: the schedule');

        $past = new DateTimeImmutable('2026-11-24 10:00:00');
        self::assertEquals($past, self::round($past, new DateTimeImmutable('2026-11-24 10:05:00'))->startedAt($now), 'started late: the schedule said started already');
    }

    private static function round(DateTimeImmutable $startsAt, null|DateTimeImmutable $stopwatchStartedAt): EditionRoundDetail
    {
        return new EditionRoundDetail(
            id: '018d0000-0000-0000-0000-000000000001',
            name: 'Round',
            startsAt: $startsAt,
            minutesLimit: 60,
            category: RoundCategory::Solo,
            badgeBackgroundColor: null,
            badgeTextColor: null,
            puzzles: [],
            color: '#000000',
            textColor: '#ffffff',
            stopwatchStartedAt: $stopwatchStartedAt,
        );
    }
}
