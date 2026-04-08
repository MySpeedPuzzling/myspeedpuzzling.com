<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Message\JoinReferralProgram;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class JoinReferralProgramHandlerTest extends KernelTestCase
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

    public function testPlayerJoinsReferralProgram(): void
    {
        // PLAYER_ADMIN is not in referral program
        $player = $this->playerRepository->get(PlayerFixture::PLAYER_ADMIN);
        self::assertNull($player->referralProgramJoinedAt);

        $this->messageBus->dispatch(new JoinReferralProgram(PlayerFixture::PLAYER_ADMIN));

        $player = $this->playerRepository->get(PlayerFixture::PLAYER_ADMIN);
        self::assertNotNull($player->referralProgramJoinedAt);
        self::assertTrue($player->isInReferralProgram());
    }

    public function testJoiningTwiceIsIdempotent(): void
    {
        // PLAYER_REGULAR is already in referral program (from fixtures)
        $player = $this->playerRepository->get(PlayerFixture::PLAYER_REGULAR);
        $originalJoinedAt = $player->referralProgramJoinedAt;
        self::assertNotNull($originalJoinedAt);

        $this->messageBus->dispatch(new JoinReferralProgram(PlayerFixture::PLAYER_REGULAR));

        $player = $this->playerRepository->get(PlayerFixture::PLAYER_REGULAR);
        self::assertSame(
            $originalJoinedAt->format('Y-m-d H:i:s'),
            $player->referralProgramJoinedAt?->format('Y-m-d H:i:s'),
        );
    }
}
