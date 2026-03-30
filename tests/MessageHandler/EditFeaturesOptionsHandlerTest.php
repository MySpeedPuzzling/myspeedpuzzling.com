<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Message\EditFeaturesOptions;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class EditFeaturesOptionsHandlerTest extends KernelTestCase
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

    public function testOptOutOfStreak(): void
    {
        $this->messageBus->dispatch(
            new EditFeaturesOptions(
                playerId: PlayerFixture::PLAYER_REGULAR,
                streakOptedOut: true,
                rankingOptedOut: false,
            ),
        );

        $player = $this->playerRepository->get(PlayerFixture::PLAYER_REGULAR);

        self::assertTrue($player->streakOptedOut);
        self::assertFalse($player->rankingOptedOut);
    }

    public function testOptOutOfRanking(): void
    {
        $this->messageBus->dispatch(
            new EditFeaturesOptions(
                playerId: PlayerFixture::PLAYER_REGULAR,
                streakOptedOut: false,
                rankingOptedOut: true,
            ),
        );

        $player = $this->playerRepository->get(PlayerFixture::PLAYER_REGULAR);

        self::assertFalse($player->streakOptedOut);
        self::assertTrue($player->rankingOptedOut);
    }

    public function testOptOutOfBoth(): void
    {
        $this->messageBus->dispatch(
            new EditFeaturesOptions(
                playerId: PlayerFixture::PLAYER_REGULAR,
                streakOptedOut: true,
                rankingOptedOut: true,
            ),
        );

        $player = $this->playerRepository->get(PlayerFixture::PLAYER_REGULAR);

        self::assertTrue($player->streakOptedOut);
        self::assertTrue($player->rankingOptedOut);
    }

    public function testOptBackIn(): void
    {
        $this->messageBus->dispatch(
            new EditFeaturesOptions(
                playerId: PlayerFixture::PLAYER_REGULAR,
                streakOptedOut: true,
                rankingOptedOut: true,
            ),
        );

        $this->messageBus->dispatch(
            new EditFeaturesOptions(
                playerId: PlayerFixture::PLAYER_REGULAR,
                streakOptedOut: false,
                rankingOptedOut: false,
            ),
        );

        $player = $this->playerRepository->get(PlayerFixture::PLAYER_REGULAR);

        self::assertFalse($player->streakOptedOut);
        self::assertFalse($player->rankingOptedOut);
    }
}
