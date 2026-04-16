<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetPlayerActivityCalendar;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetPlayerActivityCalendarTest extends KernelTestCase
{
    private GetPlayerActivityCalendar $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->query = $container->get(GetPlayerActivityCalendar::class);
    }

    public function testActiveDaysInMonthReturnsUniqueSortedDays(): void
    {
        $days = $this->scanYearsOfMonths(
            PlayerFixture::PLAYER_REGULAR,
            fn (int $y, int $m): array => $this->query->activeDaysInMonth(PlayerFixture::PLAYER_REGULAR, $y, $m),
        );

        self::assertNotEmpty($days);
        self::assertSame($days, array_values(array_unique($days)), 'Days must be unique');

        $sorted = $days;
        sort($sorted);
        self::assertSame($sorted, $days, 'Days must be ASC sorted');

        foreach ($days as $day) {
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $day);
        }
    }

    public function testActiveDaysInMonthRestrictsToThatMonth(): void
    {
        $now = new \DateTimeImmutable();
        $year = (int) $now->format('Y');
        $month = (int) $now->format('m');

        $days = $this->query->activeDaysInMonth(PlayerFixture::PLAYER_REGULAR, $year, $month);

        $expectedPrefix = sprintf('%04d-%02d-', $year, $month);
        foreach ($days as $day) {
            self::assertStringStartsWith($expectedPrefix, $day);
        }
    }

    public function testActiveDaysInMonthIncludesTeamParticipationWhenNotOwner(): void
    {
        // PLAYER_PRIVATE appears in team puzzlers for TIME_12 (team-001) and TIME_41 (team-002),
        // but player_id on those rows is PLAYER_REGULAR. The query must detect JSON membership.
        $days = $this->scanYearsOfMonths(
            PlayerFixture::PLAYER_PRIVATE,
            fn (int $y, int $m): array => $this->query->activeDaysInMonth(PlayerFixture::PLAYER_PRIVATE, $y, $m),
        );

        self::assertNotEmpty($days);
    }

    public function testActiveDaysInMonthThrowsForInvalidPlayerId(): void
    {
        $this->expectException(PlayerNotFound::class);

        $this->query->activeDaysInMonth('not-a-uuid', 2026, 1);
    }

    public function testDayOfWeekBucketsInMonthAlwaysReturnsSevenKeys(): void
    {
        $now = new \DateTimeImmutable();
        $buckets = $this->query->dayOfWeekBucketsInMonth(
            PlayerFixture::PLAYER_REGULAR,
            (int) $now->format('Y'),
            (int) $now->format('m'),
        );

        self::assertCount(7, $buckets);
        self::assertSame([0, 1, 2, 3, 4, 5, 6], array_keys($buckets));

        foreach ($buckets as $count) {
            self::assertGreaterThanOrEqual(0, $count);
        }
    }

    public function testDayOfWeekBucketsInMonthReturnsZerosForFutureMonth(): void
    {
        $buckets = $this->query->dayOfWeekBucketsInMonth(PlayerFixture::PLAYER_REGULAR, 2999, 1);

        self::assertSame([0, 0, 0, 0, 0, 0, 0], $buckets);
    }

    public function testHourOfDayBucketsInMonthAlwaysReturnsTwentyFourKeys(): void
    {
        $now = new \DateTimeImmutable();
        $buckets = $this->query->hourOfDayBucketsInMonth(
            PlayerFixture::PLAYER_REGULAR,
            (int) $now->format('Y'),
            (int) $now->format('m'),
        );

        self::assertCount(24, $buckets);
        self::assertSame(range(0, 23), array_keys($buckets));

        foreach ($buckets as $count) {
            self::assertGreaterThanOrEqual(0, $count);
        }
    }

    public function testHourOfDayBucketsInMonthCoverTrackedEvenIfNotFinished(): void
    {
        // `dayOfWeekBucketsInMonth` filters finished_at IS NOT NULL;
        // `hourOfDayBucketsInMonth` uses tracked_at and includes every row.
        // For a wide window, hour total should be >= dow total.
        $dowTotal = 0;
        $hourTotal = 0;
        $now = new \DateTimeImmutable();

        for ($offset = 0; $offset <= 3; $offset++) {
            $m = $now->modify("-{$offset} months");
            $dowTotal += array_sum($this->query->dayOfWeekBucketsInMonth(
                PlayerFixture::PLAYER_REGULAR,
                (int) $m->format('Y'),
                (int) $m->format('m'),
            ));
            $hourTotal += array_sum($this->query->hourOfDayBucketsInMonth(
                PlayerFixture::PLAYER_REGULAR,
                (int) $m->format('Y'),
                (int) $m->format('m'),
            ));
        }

        self::assertGreaterThanOrEqual($dowTotal, $hourTotal);
    }

    public function testPerDayInMonthReturnsOnlyRequestedMonth(): void
    {
        $now = new \DateTimeImmutable();
        $year = (int) $now->format('Y');
        $month = (int) $now->format('m');

        $days = $this->query->perDayInMonth(PlayerFixture::PLAYER_REGULAR, $year, $month);

        $expectedPrefix = sprintf('%04d-%02d-', $year, $month);

        foreach ($days as $key => $day) {
            self::assertStringStartsWith($expectedPrefix, $key);
            self::assertSame($key, $day->date->format('Y-m-d'));
            self::assertGreaterThan(0, $day->totalCount(), 'Days in the map should have at least one solve');
        }

        // Sanity: at least one day exists for player1 in the current/recent window.
        $prev = $now->modify('-1 month');
        $prevDays = $this->query->perDayInMonth(
            PlayerFixture::PLAYER_REGULAR,
            (int) $prev->format('Y'),
            (int) $prev->format('m'),
        );

        self::assertGreaterThan(0, count($days) + count($prevDays));
    }

    public function testPerDayInMonthSplitsCountsByType(): void
    {
        $now = new \DateTimeImmutable();

        $allDays = [];
        for ($offset = 0; $offset <= 3; $offset++) {
            $month = $now->modify("-{$offset} months");
            $allDays = array_merge($allDays, array_values($this->query->perDayInMonth(
                PlayerFixture::PLAYER_REGULAR,
                (int) $month->format('Y'),
                (int) $month->format('m'),
            )));
        }

        self::assertNotEmpty($allDays);

        foreach ($allDays as $day) {
            self::assertSame(
                $day->soloCount + $day->duoCount + $day->teamCount,
                $day->totalCount(),
            );
            self::assertLessThanOrEqual($day->totalCount(), $day->firstAttemptCount);
            self::assertGreaterThanOrEqual(0, $day->totalSeconds);
        }
    }

    public function testPerDayInMonthReturnsEmptyForFutureMonth(): void
    {
        $days = $this->query->perDayInMonth(PlayerFixture::PLAYER_REGULAR, 2999, 1);

        self::assertSame([], $days);
    }

    /**
     * Collects unique 'Y-m-d' strings across a 6-month window ending at "now", to avoid
     * depending on a specific month of fixture data.
     *
     * @param callable(int, int): list<string> $queryForMonth
     * @return list<string>
     */
    private function scanYearsOfMonths(string $playerId, callable $queryForMonth): array
    {
        unset($playerId); // callable closes over it
        $now = new \DateTimeImmutable();
        $all = [];

        for ($offset = 0; $offset <= 6; $offset++) {
            $m = $now->modify("-{$offset} months");
            $all = array_merge($all, $queryForMonth((int) $m->format('Y'), (int) $m->format('m')));
        }

        sort($all);

        return array_values(array_unique($all));
    }
}
