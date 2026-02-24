<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Exceptions\StopwatchNotFound;
use SpeedPuzzling\Web\Query\GetStopwatch;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\StopwatchFixture;
use SpeedPuzzling\Web\Value\StopwatchStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetStopwatchTest extends KernelTestCase
{
    private GetStopwatch $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetStopwatch::class);
    }

    public function testByIdReturnsStopwatchDetail(): void
    {
        $detail = $this->query->byId(StopwatchFixture::STOPWATCH_PAUSED);

        self::assertSame(StopwatchFixture::STOPWATCH_PAUSED, $detail->stopwatchId);
        self::assertSame(StopwatchStatus::Paused, $detail->status);
        self::assertSame(PuzzleFixture::PUZZLE_500_01, $detail->puzzleId);
    }

    public function testByIdThrowsForNonExistentStopwatch(): void
    {
        $this->expectException(StopwatchNotFound::class);

        $this->query->byId('018d000d-0000-0000-0000-999999999999');
    }

    public function testByIdThrowsForInvalidUuid(): void
    {
        $this->expectException(StopwatchNotFound::class);

        $this->query->byId('not-a-uuid');
    }

    public function testByIdIncludesNameField(): void
    {
        $detail = $this->query->byId(StopwatchFixture::STOPWATCH_PAUSED);

        // Name is null by default in fixture
        self::assertNull($detail->name);
    }

    public function testAllForPlayerReturnsStopwatches(): void
    {
        $stopwatches = $this->query->allForPlayer(PlayerFixture::PLAYER_REGULAR);

        self::assertNotEmpty($stopwatches);
        self::assertCount(2, $stopwatches);
    }

    public function testAllForPlayerOrderedByMostRecent(): void
    {
        $stopwatches = $this->query->allForPlayer(PlayerFixture::PLAYER_REGULAR);

        // Running stopwatch started 10 min ago, paused started 1 hour ago
        // Most recent (running) should come first
        self::assertSame(StopwatchFixture::STOPWATCH_RUNNING, $stopwatches[0]->stopwatchId);
        self::assertSame(StopwatchFixture::STOPWATCH_PAUSED, $stopwatches[1]->stopwatchId);
    }

    public function testAllForPlayerWithNoStopwatchesReturnsEmpty(): void
    {
        $stopwatches = $this->query->allForPlayer(PlayerFixture::PLAYER_PRIVATE);

        self::assertEmpty($stopwatches);
    }
}
