<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\LiveResults;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testACountdownIsNotRunningYetButAPassedScheduledStartRuns(): void
    {
        $now = new DateTimeImmutable(self::NOW);

        // Counting down to 12:05 - the round that runs is still the current one
        $counting = [
            self::round('Heat 1', '2026-10-10 11:00', 'running', '2026-10-10 11:01'),
            self::round('Heat 2', '2026-10-10 12:05', 'scheduled', '2026-10-10 12:05'),
        ];
        self::assertSame('Heat 1', LiveResultsCurrentRound::pick($counting, $now)?->name);

        // Its moment has passed (nothing was written then): it runs, and it started last
        $passed = [
            self::round('Heat 1', '2026-10-10 11:00', 'running', '2026-10-10 11:01'),
            self::round('Heat 2', '2026-10-10 11:55', 'scheduled', '2026-10-10 11:58'),
        ];
        self::assertSame('Heat 2', LiveResultsCurrentRound::pick($passed, $now)?->name);

        // Only a countdown, before anything ran: the next round by schedule, not a "running" one
        $onlyCountdown = [
            self::round('Final', '2026-10-10 15:00', null, null),
            self::round('Semifinal', '2026-10-10 13:00', 'scheduled', '2026-10-10 12:30'),
        ];
        self::assertSame('Semifinal', LiveResultsCurrentRound::pick($onlyCountdown, $now)?->name);
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

    /**
     * The cases the live entry's offline copy runs too (assets/live_entry_shell.js currentRound(),
     * tests/live-entry-shell-harness.mjs) - the JS port picks what this class picks.
     *
     * @param list<array{name: string, startsAt: string, status: null|string, startedAt: null|string}> $rounds
     */
    #[DataProvider('provideSharedCases')]
    public function testTheSharedCasesTheOfflineCopyRunsToo(string $now, array $rounds, null|string $expected): void
    {
        $overviews = array_map(
            static fn (array $round): RoundResultsOverview => self::round($round['name'], $round['startsAt'], $round['status'], $round['startedAt']),
            $rounds,
        );

        self::assertSame($expected, LiveResultsCurrentRound::pick($overviews, new DateTimeImmutable($now))?->name);
    }

    /**
     * @return iterable<string, array{string, list<array{name: string, startsAt: string, status: null|string, startedAt: null|string}>, null|string}>
     */
    public static function provideSharedCases(): iterable
    {
        /** @var list<array{case: string, now: string, rounds: list<array{name: string, startsAt: string, status: null|string, startedAt: null|string}>, expected: null|string}> $cases */
        $cases = json_decode((string) file_get_contents(__DIR__ . '/../../fixtures/live-current-round-cases.json'), true, flags: JSON_THROW_ON_ERROR);

        foreach ($cases as $case) {
            yield $case['case'] => [$case['now'], $case['rounds'], $case['expected']];
        }
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
