<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Message\BlockUser;
use SpeedPuzzling\Web\Query\GetUserBlocks;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class BlockUserHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private GetUserBlocks $getUserBlocks;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->getUserBlocks = $container->get(GetUserBlocks::class);
    }

    public function testBlockingCreatesUserBlock(): void
    {
        $this->messageBus->dispatch(
            new BlockUser(
                blockerId: PlayerFixture::PLAYER_ADMIN,
                blockedId: PlayerFixture::PLAYER_WITH_FAVORITES,
            ),
        );

        $isBlocked = $this->getUserBlocks->isBlocked(
            PlayerFixture::PLAYER_ADMIN,
            PlayerFixture::PLAYER_WITH_FAVORITES,
        );

        self::assertTrue($isBlocked);
    }

    public function testDuplicateBlockIsIdempotent(): void
    {
        // Block already exists from UserBlockFixture: REGULAR blocks PRIVATE
        $this->messageBus->dispatch(
            new BlockUser(
                blockerId: PlayerFixture::PLAYER_REGULAR,
                blockedId: PlayerFixture::PLAYER_PRIVATE,
            ),
        );

        // Should not throw - idempotent
        $isBlocked = $this->getUserBlocks->isBlocked(
            PlayerFixture::PLAYER_REGULAR,
            PlayerFixture::PLAYER_PRIVATE,
        );

        self::assertTrue($isBlocked);
    }
}
