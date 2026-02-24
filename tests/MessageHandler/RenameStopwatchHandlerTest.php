<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\RenameStopwatch;
use SpeedPuzzling\Web\Repository\StopwatchRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\StopwatchFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class RenameStopwatchHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private StopwatchRepository $stopwatchRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->stopwatchRepository = self::getContainer()->get(StopwatchRepository::class);
    }

    public function testRenamesStopwatch(): void
    {
        $this->messageBus->dispatch(new RenameStopwatch(
            stopwatchId: Uuid::fromString(StopwatchFixture::STOPWATCH_PAUSED),
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            name: 'My evening session',
        ));

        $stopwatch = $this->stopwatchRepository->get(StopwatchFixture::STOPWATCH_PAUSED);
        self::assertSame('My evening session', $stopwatch->name);
    }

    public function testRenameToNullClearsName(): void
    {
        // First set a name
        $this->messageBus->dispatch(new RenameStopwatch(
            stopwatchId: Uuid::fromString(StopwatchFixture::STOPWATCH_PAUSED),
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            name: 'Temporary name',
        ));

        // Then clear it
        $this->messageBus->dispatch(new RenameStopwatch(
            stopwatchId: Uuid::fromString(StopwatchFixture::STOPWATCH_PAUSED),
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            name: null,
        ));

        $stopwatch = $this->stopwatchRepository->get(StopwatchFixture::STOPWATCH_PAUSED);
        self::assertNull($stopwatch->name);
    }
}
