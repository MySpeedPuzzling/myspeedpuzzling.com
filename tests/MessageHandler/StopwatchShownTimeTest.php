<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\ResumeStopwatch;
use SpeedPuzzling\Web\Message\StartStopwatch;
use SpeedPuzzling\Web\Message\StopStopwatch;
use SpeedPuzzling\Web\Query\GetStopwatch;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The time saved from a stopwatch is what the stopwatch showed at Stop - a stop just before the next second
 * must not come out a second longer (reported by a player: stopped at 5 s, the add form said 6 s).
 */
final class StopwatchShownTimeTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private GetStopwatch $getStopwatch;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->getStopwatch = self::getContainer()->get(GetStopwatch::class);
    }

    public function testSavedTimeIsWhatTheStopwatchShowed(): void
    {
        $stopwatchId = Uuid::uuid7();
        $this->messageBus->dispatch(new StartStopwatch($stopwatchId, PlayerFixture::PLAYER_REGULAR_USER_ID, null));

        $this->messageBus->dispatch(new StopStopwatch($stopwatchId, PlayerFixture::PLAYER_REGULAR_USER_ID, 5_987));

        $stopwatch = $this->getStopwatch->byId($stopwatchId->toString());
        self::assertSame(5_987, $stopwatch->totalMilliseconds);
        self::assertSame(5, $stopwatch->totalSeconds);
    }

    public function testLapsAddUpToTheShownTotal(): void
    {
        $stopwatchId = Uuid::uuid7();
        $this->messageBus->dispatch(new StartStopwatch($stopwatchId, PlayerFixture::PLAYER_REGULAR_USER_ID, null));
        $this->messageBus->dispatch(new StopStopwatch($stopwatchId, PlayerFixture::PLAYER_REGULAR_USER_ID, 2_400));
        $this->messageBus->dispatch(new ResumeStopwatch($stopwatchId, PlayerFixture::PLAYER_REGULAR_USER_ID, 150));

        $this->messageBus->dispatch(new StopStopwatch($stopwatchId, PlayerFixture::PLAYER_REGULAR_USER_ID, 9_999));

        $stopwatch = $this->getStopwatch->byId($stopwatchId->toString());
        self::assertSame(9_999, $stopwatch->totalMilliseconds);
        self::assertSame(9, $stopwatch->totalSeconds);
    }
}
