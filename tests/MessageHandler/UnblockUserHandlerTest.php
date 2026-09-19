<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\UserBlockNotFound;
use SpeedPuzzling\Web\Message\UnblockUser;
use SpeedPuzzling\Web\Query\GetUserBlocks;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
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
        $this->expectException(UserBlockNotFound::class);

        $this->messageBus->dispatch(
            new UnblockUser(
                blockerId: PlayerFixture::PLAYER_ADMIN,
                blockedId: PlayerFixture::PLAYER_REGULAR,
            ),
        );
    }

    public function testAdminImposedBlockCannotBeLiftedAndLooksLikeNoBlock(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source, note) VALUES (:id, :blocker, :blocked, NOW(), 'admin', 'test')",
            [
                'id' => Uuid::uuid7()->toString(),
                'blocker' => PlayerFixture::PLAYER_ADMIN,
                'blocked' => PlayerFixture::PLAYER_REGULAR,
            ],
        );

        try {
            $this->messageBus->dispatch(
                new UnblockUser(
                    blockerId: PlayerFixture::PLAYER_ADMIN,
                    blockedId: PlayerFixture::PLAYER_REGULAR,
                ),
            );
            self::fail('An admin-imposed block must not be removable by the blocker.');
        } catch (\Throwable $e) {
            $previous = $e->getPrevious() ?? $e;
            self::assertInstanceOf(UserBlockNotFound::class, $previous);
        }

        self::assertTrue($this->getUserBlocks->isBlocked(PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_REGULAR));
        self::assertSame([], $this->getUserBlocks->forPlayer(PlayerFixture::PLAYER_ADMIN), 'The blocker is never shown an admin-imposed block.');
    }
}
