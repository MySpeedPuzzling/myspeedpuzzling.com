<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Message\BanFromMarketplace;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class BanFromMarketplaceHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private PlayerRepository $playerRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->playerRepository = $container->get(PlayerRepository::class);
    }

    public function testBanSetsFlagOnPlayer(): void
    {
        $this->messageBus->dispatch(
            new BanFromMarketplace(
                targetPlayerId: PlayerFixture::PLAYER_REGULAR,
                adminId: PlayerFixture::PLAYER_ADMIN,
                reason: 'Test marketplace ban',
            ),
        );

        $player = $this->playerRepository->get(PlayerFixture::PLAYER_REGULAR);
        self::assertTrue($player->marketplaceBanned);
    }
}
