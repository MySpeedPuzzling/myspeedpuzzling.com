<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Message\SuspendFromReferralProgram;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class SuspendFromReferralProgramHandlerTest extends KernelTestCase
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

    public function testPlayerGetsSuspended(): void
    {
        // PLAYER_REGULAR is an active (not suspended) program member from fixtures
        $player = $this->playerRepository->get(PlayerFixture::PLAYER_REGULAR);
        self::assertFalse($player->referralProgramSuspended);

        $this->messageBus->dispatch(new SuspendFromReferralProgram(PlayerFixture::PLAYER_REGULAR));

        $player = $this->playerRepository->get(PlayerFixture::PLAYER_REGULAR);
        self::assertTrue($player->referralProgramSuspended);
    }
}
