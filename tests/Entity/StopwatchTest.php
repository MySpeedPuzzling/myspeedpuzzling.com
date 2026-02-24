<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Entity;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Stopwatch;
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

        $stopwatch->start(new DateTimeImmutable());
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

        $stopwatch->start(new DateTimeImmutable());
        self::assertCount(1, $stopwatch->laps);
    }

    public function testPauseFinishesLap(): void
    {
        $stopwatch = $this->createStopwatch();
        $stopwatch->start(new DateTimeImmutable('2024-01-01 10:00:00'));

        $stopwatch->pause(new DateTimeImmutable('2024-01-01 10:30:00'));
        self::assertNotNull($stopwatch->laps[0]->end);
    }

    private function createStopwatch(): Stopwatch
    {
        $player = new Player(
            id: Uuid::uuid7(),
            code: 'test',
            userId: 'auth0|test',
            email: 'test@test.com',
            name: 'Test Player',
            registeredAt: new DateTimeImmutable(),
        );

        return new Stopwatch(
            id: Uuid::uuid7(),
            player: $player,
            puzzle: null,
        );
    }
}
