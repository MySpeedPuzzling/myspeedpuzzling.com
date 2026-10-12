<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Results\RunningStopwatch;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\StopwatchStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The running-stopwatch pill's state rides on the viewer's own profile row (docs/features/navigation README D7, ADD-03):
 * the running stopwatch with the latest lap start, fresh for 24 hours. Every lap start is set here relative to the
 * clock - never the fixture's STOPWATCH_RUNNING, which is fresh only while the cached test database is younger than a
 * day (implementation plan, risk 17). PLAYER_WITH_STRIPE has no stopwatch of its own in the fixtures.
 */
final class GetPlayerProfileRunningStopwatchTest extends KernelTestCase
{
    private const string PLAYER_ID = PlayerFixture::PLAYER_WITH_STRIPE;
    private const string USER_ID = PlayerFixture::PLAYER_WITH_STRIPE_USER_ID;

    private GetPlayerProfile $getPlayerProfile;
    private Connection $database;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->getPlayerProfile = self::getContainer()->get(GetPlayerProfile::class);
        $this->database = self::getContainer()->get(Connection::class);
        $this->now = self::getContainer()->get(ClockInterface::class)->now();
    }

    public function testNoStopwatchNoPill(): void
    {
        self::assertNull($this->runningStopwatch());
    }

    public function testRunningForTenMinutes(): void
    {
        $id = $this->insert(StopwatchStatus::Running, [['-10 minutes', null]]);

        $stopwatch = $this->runningStopwatch();

        self::assertNotNull($stopwatch);
        self::assertSame($id, $stopwatch->stopwatchId);
        self::assertSame($this->at('-10 minutes'), $stopwatch->lapStartedAt->format('Y-m-d H:i:s'));
        self::assertSame(0, $stopwatch->closedMilliseconds);
        self::assertEqualsWithDelta(600, $stopwatch->elapsedSeconds($stopwatch->readAt), 5);
    }

    public function testRunningFor23Hours59Minutes(): void
    {
        $this->insert(StopwatchStatus::Running, [['-23 hours -59 minutes', null]]);

        self::assertNotNull($this->runningStopwatch());
    }

    public function testRunningFor24Hours1MinuteIsStale(): void
    {
        $this->insert(StopwatchStatus::Running, [['-24 hours -1 minute', null]]);

        self::assertNull($this->runningStopwatch());
    }

    public function testPausedAndFinishedShowNothing(): void
    {
        $this->insert(StopwatchStatus::Paused, [['-20 minutes', '-10 minutes']]);
        $this->insert(StopwatchStatus::Finished, [['-15 minutes', '-5 minutes']]);
        $this->insert(StopwatchStatus::NotStarted, []);

        self::assertNull($this->runningStopwatch());
    }

    public function testTwoRunningTheLaterLapStartWins(): void
    {
        // Started earlier but resumed later: the last lap decides, not the first
        $resumed = $this->insert(StopwatchStatus::Running, [['-3 hours', '-2 hours'], ['-5 minutes', null]]);
        $this->insert(StopwatchStatus::Running, [['-30 minutes', null]]);

        self::assertSame($resumed, $this->runningStopwatch()?->stopwatchId);
    }

    public function testTheSameLapStartIsDecidedByTheId(): void
    {
        $first = $this->insert(StopwatchStatus::Running, [['-10 minutes', null]]);
        $second = $this->insert(StopwatchStatus::Running, [['-10 minutes', null]]);

        self::assertSame(max($first, $second), $this->runningStopwatch()?->stopwatchId);
    }

    public function testTheMostRecentIsStaleSoIsEveryOther(): void
    {
        $this->insert(StopwatchStatus::Running, [['-30 hours', null]]);
        $this->insert(StopwatchStatus::Running, [['-3 days', null]]);
        // A fresh paused one changes nothing
        $this->insert(StopwatchStatus::Paused, [['-20 minutes', '-10 minutes']]);

        self::assertNull($this->runningStopwatch());
    }

    public function testClosedLapsAreSummed(): void
    {
        $this->insert(StopwatchStatus::Running, [
            ['-2 hours', '-1 hour -50 minutes'],
            ['-1 hour', '-55 minutes'],
            ['-10 minutes', null],
        ]);

        $stopwatch = $this->runningStopwatch();

        self::assertNotNull($stopwatch);
        // 10 + 5 closed minutes
        self::assertSame(900_000, $stopwatch->closedMilliseconds);
        self::assertSame($this->at('-10 minutes'), $stopwatch->lapStartedAt->format('Y-m-d H:i:s'));
        self::assertEqualsWithDelta(1500, $stopwatch->elapsedSeconds($stopwatch->readAt), 5);
    }

    /**
     * Laps are stored to the millisecond since 2026-10-12, whole seconds before - a stopwatch running across that deploy
     * holds both: the closed laps keep their milliseconds, the running lap is read to the millisecond
     */
    public function testMillisecondAndWholeSecondLapsMixed(): void
    {
        $this->insert(StopwatchStatus::Running, [
            ['-2 hours', '-1 hour -50 minutes'],
            ['-1 hour|.250', '-55 minutes|.750'],
            ['-10 minutes|.100', null],
        ]);

        $stopwatch = $this->runningStopwatch();

        self::assertNotNull($stopwatch);
        // 10 minutes + 5 minutes 0.5 s
        self::assertSame(900_500, $stopwatch->closedMilliseconds);
        self::assertSame($this->at('-10 minutes|.100'), $stopwatch->lapStartedAt->format('Y-m-d H:i:s.v'));
        self::assertEqualsWithDelta(1500, $stopwatch->elapsedSeconds($stopwatch->readAt), 5);
    }

    public function testALapStartWithMillisecondsSortsAfterTheSameWholeSecond(): void
    {
        $this->insert(StopwatchStatus::Running, [['-5 minutes', null]]);
        $later = $this->insert(StopwatchStatus::Running, [['-5 minutes|.500', null]]);

        self::assertSame($later, $this->runningStopwatch()?->stopwatchId);
    }

    public function testAnotherPlayersStopwatchIsNotTheViewers(): void
    {
        $this->insert(StopwatchStatus::Running, [['-10 minutes', null]], PlayerFixture::PLAYER_PRIVATE);

        self::assertNull($this->runningStopwatch());
    }

    public function testOnlyTheViewersOwnRowCarriesIt(): void
    {
        $this->insert(StopwatchStatus::Running, [['-10 minutes', null]]);

        self::assertNotNull($this->runningStopwatch());
        self::assertNull($this->getPlayerProfile->byId(self::PLAYER_ID)->runningStopwatch);
    }

    private function runningStopwatch(): null|RunningStopwatch
    {
        return $this->getPlayerProfile->byUserId(self::USER_ID)->runningStopwatch;
    }

    /**
     * @param list<array{string, null|string}> $laps [start, end] relative to the clock
     */
    private function insert(StopwatchStatus $status, array $laps, string $playerId = self::PLAYER_ID): string
    {
        $id = Uuid::uuid7()->toString();

        $this->database->insert('stopwatch', [
            'id' => $id,
            'player_id' => $playerId,
            'puzzle_id' => null,
            'status' => $status->value,
            // As Stopwatch::$laps stores them (LapsArrayDoctrineType)
            'laps' => json_encode(array_map(
                fn (array $lap): array => ['start' => $this->at($lap[0]), 'end' => $lap[1] === null ? null : $this->at($lap[1])],
                $laps,
            ), JSON_THROW_ON_ERROR),
        ]);

        return $id;
    }

    /**
     * A lap moment as LapsArrayDoctrineType writes it: `-10 minutes` in whole seconds (laps before 2026-10-12),
     * `-10 minutes|.250` with milliseconds
     */
    private function at(string $modifier): string
    {
        [$relative, $milliseconds] = explode('|', $modifier . '|');

        return $this->now->modify($relative)->format('Y-m-d H:i:s') . $milliseconds;
    }
}
