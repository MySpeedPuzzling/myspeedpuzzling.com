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

    public function testActiveDaysReturnsUniqueSortedDays(): void
    {
        $days = $this->query->activeDays(PlayerFixture::PLAYER_REGULAR);

        self::assertNotEmpty($days);
        self::assertSame($days, array_values(array_unique($days)), 'Days must be unique');

        $sorted = $days;
        sort($sorted);
        self::assertSame($sorted, $days, 'Days must be ASC sorted');

        foreach ($days as $day) {
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $day);
        }
    }

    public function testActiveDaysIncludesTeamParticipationWhenNotOwner(): void
    {
        // PLAYER_PRIVATE appears in team puzzlers for TIME_12 (team-001) and TIME_41 (team-002),
        // but player_id on those rows is PLAYER_REGULAR. The query must detect the JSON membership.
        $days = $this->query->activeDays(PlayerFixture::PLAYER_PRIVATE);

        self::assertNotEmpty($days);
    }

    public function testActiveDaysThrowsForInvalidPlayerId(): void
    {
        $this->expectException(PlayerNotFound::class);

        $this->query->activeDays('not-a-uuid');
    }

    public function testDayOfWeekBucketsAlwaysReturnsSevenKeys(): void
    {
        $buckets = $this->query->dayOfWeekBuckets(PlayerFixture::PLAYER_REGULAR);

        self::assertCount(7, $buckets);
        self::assertSame([0, 1, 2, 3, 4, 5, 6], array_keys($buckets));

        foreach ($buckets as $count) {
            self::assertGreaterThanOrEqual(0, $count);
        }
    }

    public function testDayOfWeekBucketsSumMatchesFinishedSolveCount(): void
    {
        $buckets = $this->query->dayOfWeekBuckets(PlayerFixture::PLAYER_REGULAR);
        $hourBuckets = $this->query->hourOfDayBuckets(PlayerFixture::PLAYER_REGULAR);

        // Both aggregations filter the same rows (finished_at IS NOT NULL + player in row or in team).
        self::assertSame(array_sum($buckets), array_sum($hourBuckets));
    }

    public function testHourOfDayBucketsAlwaysReturnsTwentyFourKeys(): void
    {
        $buckets = $this->query->hourOfDayBuckets(PlayerFixture::PLAYER_REGULAR);

        self::assertCount(24, $buckets);
        self::assertSame(range(0, 23), array_keys($buckets));

        foreach ($buckets as $count) {
            self::assertGreaterThanOrEqual(0, $count);
        }
    }

    public function testPerDayInMonthReturnsOnlyRequestedMonth(): void
    {
        // Use a wide window — fixtures space solves across a couple of months relative to "now".
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
        // Scan a wide window covering all fixture dates and verify structural invariants
        // hold per day.
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
}
