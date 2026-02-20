<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Message\PrepareDigestEmailForPlayer;
use SpeedPuzzling\Web\MessageHandler\PrepareDigestEmailForPlayerHandler;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PrepareDigestEmailForPlayerHandlerTest extends KernelTestCase
{
    private PrepareDigestEmailForPlayerHandler $handler;
    private Connection $connection;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->handler = $container->get(PrepareDigestEmailForPlayerHandler::class);
        $this->connection = $container->get(Connection::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    public function testCreatesDigestEmailLogAfterSending(): void
    {
        $logCountBefore = $this->countDigestEmailLogs(PlayerFixture::PLAYER_REGULAR);

        ($this->handler)(new PrepareDigestEmailForPlayer(
            playerId: PlayerFixture::PLAYER_REGULAR,
        ));
        $this->entityManager->flush();

        $logCountAfter = $this->countDigestEmailLogs(PlayerFixture::PLAYER_REGULAR);
        self::assertSame($logCountBefore + 1, $logCountAfter);
    }

    public function testDigestEmailLogContainsOldestTimestamps(): void
    {
        ($this->handler)(new PrepareDigestEmailForPlayer(
            playerId: PlayerFixture::PLAYER_REGULAR,
        ));
        $this->entityManager->flush();

        $log = $this->connection->fetchAssociative(
            'SELECT * FROM digest_email_log WHERE player_id = :playerId ORDER BY sent_at DESC LIMIT 1',
            ['playerId' => PlayerFixture::PLAYER_REGULAR],
        );

        self::assertNotFalse($log);
        // PLAYER_REGULAR has unread messages, so oldest_unread_message_at should be set
        self::assertNotNull($log['oldest_unread_message_at']);
        // PLAYER_REGULAR has pending requests, so oldest_pending_request_at should be set
        self::assertNotNull($log['oldest_pending_request_at']);
    }

    public function testEarlyReturnsWhenPlayerWasAlreadyNotified(): void
    {
        // First call creates a log
        ($this->handler)(new PrepareDigestEmailForPlayer(
            playerId: PlayerFixture::PLAYER_REGULAR,
        ));
        $this->entityManager->flush();

        $logCountAfterFirst = $this->countDigestEmailLogs(PlayerFixture::PLAYER_REGULAR);

        // Second call should early-return because recently notified
        ($this->handler)(new PrepareDigestEmailForPlayer(
            playerId: PlayerFixture::PLAYER_REGULAR,
        ));
        $this->entityManager->flush();

        $logCountAfterSecond = $this->countDigestEmailLogs(PlayerFixture::PLAYER_REGULAR);
        self::assertSame($logCountAfterFirst, $logCountAfterSecond);
    }

    public function testEarlyReturnsWhenPlayerDisabledNotifications(): void
    {
        $this->connection->executeStatement(
            'UPDATE player SET email_notifications_enabled = false WHERE id = :id',
            ['id' => PlayerFixture::PLAYER_REGULAR],
        );
        // Clear identity map so handler gets fresh data
        $this->entityManager->clear();

        $logCountBefore = $this->countDigestEmailLogs(PlayerFixture::PLAYER_REGULAR);

        ($this->handler)(new PrepareDigestEmailForPlayer(
            playerId: PlayerFixture::PLAYER_REGULAR,
        ));
        $this->entityManager->flush();

        $logCountAfter = $this->countDigestEmailLogs(PlayerFixture::PLAYER_REGULAR);
        self::assertSame($logCountBefore, $logCountAfter);
    }

    public function testSkipsWhenNoNewContent(): void
    {
        // Mark all messages as read for PLAYER_ADMIN (who has no unread messages)
        $logCountBefore = $this->countDigestEmailLogs(PlayerFixture::PLAYER_ADMIN);

        ($this->handler)(new PrepareDigestEmailForPlayer(
            playerId: PlayerFixture::PLAYER_ADMIN,
        ));
        $this->entityManager->flush();

        $logCountAfter = $this->countDigestEmailLogs(PlayerFixture::PLAYER_ADMIN);
        self::assertSame($logCountBefore, $logCountAfter);
    }

    private function countDigestEmailLogs(string $playerId): int
    {
        /** @var int $count */
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM digest_email_log WHERE player_id = :playerId',
            ['playerId' => $playerId],
        );

        return $count;
    }
}
