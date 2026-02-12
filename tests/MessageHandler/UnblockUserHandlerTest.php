<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Exceptions\UserBlockNotFound;
use SpeedPuzzling\Web\Message\UnblockUser;
use SpeedPuzzling\Web\Query\GetUserBlocks;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final class UnblockUserHandlerTest extends KernelTestCase
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

    public function testUnblockingRemovesBlock(): void
    {
        // Block exists from UserBlockFixture: REGULAR blocks PRIVATE
        $this->messageBus->dispatch(
            new UnblockUser(
                blockerId: PlayerFixture::PLAYER_REGULAR,
                blockedId: PlayerFixture::PLAYER_PRIVATE,
            ),
        );

        $isBlocked = $this->getUserBlocks->isBlocked(
            PlayerFixture::PLAYER_REGULAR,
            PlayerFixture::PLAYER_PRIVATE,
        );

        self::assertFalse($isBlocked);
    }

    public function testUnblockingNonExistentBlockThrowsException(): void
    {
        try {
            $this->messageBus->dispatch(
                new UnblockUser(
                    blockerId: PlayerFixture::PLAYER_ADMIN,
                    blockedId: PlayerFixture::PLAYER_REGULAR,
                ),
            );
            self::fail('Expected UserBlockNotFound exception was not thrown');
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(UserBlockNotFound::class, $previous);
        }
    }
}
