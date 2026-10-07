<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\LiveResults;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\RoundResultsOverview;
use SpeedPuzzling\Web\Services\LiveResultsCurrentRound;

final class LiveResultsCurrentRoundTest extends TestCase
{
    private const string NOW = '2026-10-10 12:00:00';

    public function testNoRoundsNoRound(): void
    {
        self::assertNull(LiveResultsCurrentRound::pick([], new DateTimeImmutable(self::NOW)));
    }

    public function testTheRunningStopwatchWinsTheLatestStartedOfSeveral(): void
    {
        $rounds = [
            self::round('A', '2026-10-10 09:00', 'running', '2026-10-10 09:02'),
            self::round('B', '2026-10-10 11:30', 'running', '2026-10-10 11:31'),
            self::round('C', '2026-10-10 11:55', null, null),
        ];

        self::assertSame('B', LiveResultsCurrentRound::pick($rounds, new DateTimeImmutable(self::NOW))?->name);
    }

    public function testWithoutAStopwatchTheRoundStartedLastInTheLast12HoursIsCurrent(): void
    {
        $rounds = [
            self::round('Morning', '2026-10-10 08:00', 'stopped', '2026-10-10 08:01'),
            // Timed without the stopwatch: its schedule counts once it has passed
            self::round('Late morning', '2026-10-10 10:30', null, null),
            self::round('Afternoon', '2026-10-10 14:00', null, null),
        ];

        self::assertSame('Late morning', LiveResultsCurrentRound::pick($rounds, new DateTimeImmutable(self::NOW))?->name);
    }

    public function testBeforeAnythingStartedTheNextRound(): void
    {
        $rounds = [
            self::round('Yesterday', '2026-10-09 09:00', 'stopped', '2026-10-09 09:00'),
            self::round('Final', '2026-10-10 16:00', null, null),
            self::round('Semifinal', '2026-10-10 13:00', null, null),
        ];

        self::assertSame('Semifinal', LiveResultsCurrentRound::pick($rounds, new DateTimeImmutable(self::NOW))?->name);
    }

    public function testAllOverLongAgoTheLastRound(): void
    {
        $rounds = [
            self::round('Group A', '2026-09-01 09:00', 'stopped', '2026-09-01 09:00'),
            self::round('Final', '2026-09-01 15:00', 'stopped', '2026-09-01 15:00'),
            self::round('Group B', '2026-09-01 11:00', null, null),
        ];

        self::assertSame('Final', LiveResultsCurrentRound::pick($rounds, new DateTimeImmutable(self::NOW))?->name);
    }

    private static function round(string $name, string $startsAt, null|string $status, null|string $stopwatchStartedAt): RoundResultsOverview
    {
        return new RoundResultsOverview(
            roundId: strtolower($name),
            name: $name,
            category: 'solo',
            startsAt: new DateTimeImmutable($startsAt),
            minutesLimit: 90,
            slug: null,
            stopwatchStatus: $status,
            stopwatchStartedAt: $stopwatchStartedAt !== null ? new DateTimeImmutable($stopwatchStartedAt) : null,
            stopwatchStoppedAt: null,
            resultsPublishedAt: null,
            resultsFirstPublishedAt: null,
            tableNumbersOff: false,
            puzzlesCount: 1,
            piecesCount: 500,
            entriesTotal: 0,
            entriesWithTableNumber: 0,
            entriesWithResult: 0,
            entriesQualified: 0,
        );
    }
}
