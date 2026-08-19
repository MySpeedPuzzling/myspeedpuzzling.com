<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\ChangeCollectionDisplayMode;
use SpeedPuzzling\Web\Query\GetCollectionDisplayMode;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\CollectionDisplayMode;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class ChangeCollectionDisplayModeHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;

    private PlayerRepository $playerRepository;

    private GetCollectionDisplayMode $getCollectionDisplayMode;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->playerRepository = $container->get(PlayerRepository::class);
        $this->getCollectionDisplayMode = $container->get(GetCollectionDisplayMode::class);
    }

    public function testDefaultIsOffAndEveryModeIsPersisted(): void
    {
        self::assertSame(CollectionDisplayMode::Off, $this->playerRepository->get(PlayerFixture::PLAYER_REGULAR)->collectionDisplayMode);
        self::assertSame(CollectionDisplayMode::Off, $this->getCollectionDisplayMode->forPlayer(PlayerFixture::PLAYER_REGULAR));

        foreach ([CollectionDisplayMode::Times, CollectionDisplayMode::TimesPredictions, CollectionDisplayMode::Off] as $mode) {
            $this->messageBus->dispatch(new ChangeCollectionDisplayMode(
                playerId: PlayerFixture::PLAYER_REGULAR,
                mode: $mode,
            ));

            self::assertSame($mode, $this->playerRepository->get(PlayerFixture::PLAYER_REGULAR)->collectionDisplayMode);
            // The read model sees the flushed value (the doctrine_transaction middleware flushes)
            self::assertSame($mode, $this->getCollectionDisplayMode->forPlayer(PlayerFixture::PLAYER_REGULAR));
        }

        // Other players are untouched
        self::assertSame(CollectionDisplayMode::Off, $this->getCollectionDisplayMode->forPlayer(PlayerFixture::PLAYER_WITH_STRIPE));
    }

    public function testUnknownPlayer(): void
    {
        self::assertSame(CollectionDisplayMode::Off, $this->getCollectionDisplayMode->forPlayer('00000000-0000-0000-0000-000000000099'));

        // PlayerNotFound is an HTTP exception, so UnwrapHttpExceptionMiddleware hands it over bare
        $this->expectException(PlayerNotFound::class);

        $this->messageBus->dispatch(new ChangeCollectionDisplayMode(
            playerId: '00000000-0000-0000-0000-000000000099',
            mode: CollectionDisplayMode::Times,
        ));
    }
}
