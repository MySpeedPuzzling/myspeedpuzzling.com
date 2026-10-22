<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Entity;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Stopwatch;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\StopwatchStatus;

final class StopwatchTest extends TestCase
{
    public function testNewStopwatchHasNoName(): void
    {
        $stopwatch = $this->createStopwatch();

        self::assertNull($stopwatch->name);
    }

    public function testRenamesSetsName(): void
    {
        $stopwatch = $this->createStopwatch();

        $stopwatch->rename('Morning session');
        self::assertSame('Morning session', $stopwatch->name);
    }

    public function testRenameToNullClearsName(): void
    {
        $stopwatch = $this->createStopwatch();
        $stopwatch->rename('Some name');

        $stopwatch->rename(null);
        self::assertNull($stopwatch->name);
    }

    public function testNewStopwatchIsNotStarted(): void
    {
        $stopwatch = $this->createStopwatch();

        self::assertSame(StopwatchStatus::NotStarted, $stopwatch->status);
    }

    public function testStartChangesStatusToRunning(): void
    {
        $stopwatch = $this->createStopwatch();

        $stopwatch->start(TestClock::now());
        self::assertSame(StopwatchStatus::Running, $stopwatch->status);
    }

    public function testPauseChangesStatusToPaused(): void
    {
        $stopwatch = $this->createStopwatch();
        $stopwatch->start(new DateTimeImmutable('2024-01-01 10:00:00'));

        $stopwatch->pause(new DateTimeImmutable('2024-01-01 10:30:00'));
        self::assertSame(StopwatchStatus::Paused, $stopwatch->status);
    }

    public function testResumeChangesStatusToRunning(): void
    {
        $stopwatch = $this->createStopwatch();
        $stopwatch->start(new DateTimeImmutable('2024-01-01 10:00:00'));
        $stopwatch->pause(new DateTimeImmutable('2024-01-01 10:30:00'));

        $stopwatch->resume(new DateTimeImmutable('2024-01-01 11:00:00'));
        self::assertSame(StopwatchStatus::Running, $stopwatch->status);
    }

    public function testStartCreatesLap(): void
    {
        $stopwatch = $this->createStopwatch();

        $stopwatch->start(TestClock::now());
        self::assertCount(1, $stopwatch->laps);
    }

    public function testPauseFinishesLap(): void
    {
        $stopwatch = $this->createStopwatch();
        $stopwatch->start(new DateTimeImmutable('2024-01-01 10:00:00'));

        $stopwatch->pause(new DateTimeImmutable('2024-01-01 10:30:00'));
        self::assertNotNull($stopwatch->laps[0]->end);
    }

    public function testPauseEndsTheLapWhereTheShownTimeSays(): void
    {
        $stopwatch = $this->createStopwatch();
        $stopwatch->start(new DateTimeImmutable('2024-01-01 10:00:00.950'));

        // Stopped at 5.987 s on the phone, the request reached the server later
        $stopwatch->pause(new DateTimeImmutable('2024-01-01 10:00:07.300'), 5_987);

        self::assertSame('2024-01-01 10:00:06.937', $stopwatch->laps[0]->end?->format('Y-m-d H:i:s.v'));
        self::assertSame(5_987, $stopwatch->laps[0]->durationMilliseconds());
    }

    public function testShownTimeCountsTheFinishedLapsToo(): void
    {
        $stopwatch = $this->createStopwatch();
        $stopwatch->start(new DateTimeImmutable('2024-01-01 10:00:00.000'));
        $stopwatch->pause(new DateTimeImmutable('2024-01-01 10:00:10.000'), 3_500);
        $stopwatch->resume(new DateTimeImmutable('2024-01-01 10:01:00.000'));

        $stopwatch->pause(new DateTimeImmutable('2024-01-01 10:01:05.000'), 7_250);

        self::assertSame(3_500, $stopwatch->laps[0]->durationMilliseconds());
        self::assertSame(3_750, $stopwatch->laps[1]->durationMilliseconds());
    }

    public function testShownTimeCannotRunFarAheadOfTheServer(): void
    {
        $stopwatch = $this->createStopwatch();
        $stopwatch->start(new DateTimeImmutable('2024-01-01 10:00:00'));

        $stopwatch->pause(new DateTimeImmutable('2024-01-01 10:00:30'), 3_600_000);

        self::assertSame(30_000 + Stopwatch::SHOWN_TIME_TOLERANCE_MILLISECONDS, $stopwatch->laps[0]->durationMilliseconds());
    }

    public function testWithoutShownTimeTheLapEndsNow(): void
    {
        $stopwatch = $this->createStopwatch();
        $stopwatch->start(new DateTimeImmutable('2024-01-01 10:00:00'));

        $stopwatch->pause(new DateTimeImmutable('2024-01-01 10:00:30.250'));

        self::assertSame(30_250, $stopwatch->laps[0]->durationMilliseconds());
    }

    public function testResumeNeverStartsBeforeThePreviousLapEnded(): void
    {
        $stopwatch = $this->createStopwatch();
        $stopwatch->start(new DateTimeImmutable('2024-01-01 10:00:00'));
        $stopwatch->pause(new DateTimeImmutable('2024-01-01 10:00:30'));

        $stopwatch->resume(new DateTimeImmutable('2024-01-01 10:00:20'));

        self::assertSame('2024-01-01 10:00:30', $stopwatch->laps[1]->start->format('Y-m-d H:i:s'));
    }

    private function createStopwatch(): Stopwatch
    {
        $player = new Player(
            id: Uuid::uuid7(),
            code: 'test',
            userId: 'auth0|test',
            name: 'Test Player',
            registeredAt: TestClock::now(),
        );

        return new Stopwatch(
            id: Uuid::uuid7(),
            player: $player,
            puzzle: null,
        );
    }
}
