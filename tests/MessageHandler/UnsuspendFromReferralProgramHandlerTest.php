<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Message\UnsuspendFromReferralProgram;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class UnsuspendFromReferralProgramHandlerTest extends KernelTestCase
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

    public function testPlayerGetsUnsuspended(): void
    {
        // PLAYER_WITH_STRIPE is a suspended program member from fixtures
        $player = $this->playerRepository->get(PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertTrue($player->referralProgramSuspended);

        $this->messageBus->dispatch(new UnsuspendFromReferralProgram(PlayerFixture::PLAYER_WITH_STRIPE));

        $player = $this->playerRepository->get(PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertFalse($player->referralProgramSuspended);
    }
}
