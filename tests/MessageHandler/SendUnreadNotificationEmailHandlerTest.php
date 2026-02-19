<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Message\SendUnreadNotificationEmail;
use SpeedPuzzling\Web\MessageHandler\SendUnreadNotificationEmailHandler;
use SpeedPuzzling\Web\Results\UnreadMessageSummary;
use SpeedPuzzling\Web\Tests\DataFixtures\ConversationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SendUnreadNotificationEmailHandlerTest extends KernelTestCase
{
    private SendUnreadNotificationEmailHandler $handler;
    private Connection $connection;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->handler = $container->get(SendUnreadNotificationEmailHandler::class);
        $this->connection = $container->get(Connection::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    public function testCreatesMessageNotificationLogWhenUnreadMessagesExist(): void
    {
        $logCountBefore = $this->countMessageNotificationLogs(PlayerFixture::PLAYER_REGULAR);

        ($this->handler)(new SendUnreadNotificationEmail(
            playerId: PlayerFixture::PLAYER_REGULAR,
            playerEmail: PlayerFixture::PLAYER_REGULAR_EMAIL,
            playerName: PlayerFixture::PLAYER_REGULAR_NAME,
            playerLocale: 'en',
            summaries: [
                new UnreadMessageSummary(
                    senderName: 'Admin User',
                    senderCode: 'admin',
                    unreadCount: 2,
                    puzzleName: null,
                    conversationId: ConversationFixture::CONVERSATION_ACCEPTED,
                ),
            ],
            pendingRequestCount: 0,
            unreadNotificationCount: 0,
            oldestUnreadAt: new DateTimeImmutable('-2 days'),
            oldestPendingAt: null,
        ));
        $this->entityManager->flush();

        $logCountAfter = $this->countMessageNotificationLogs(PlayerFixture::PLAYER_REGULAR);
        self::assertSame($logCountBefore + 1, $logCountAfter);
    }

    public function testCreatesRequestNotificationLogWhenPendingRequestsExist(): void
    {
        $logCountBefore = $this->countRequestNotificationLogs(PlayerFixture::PLAYER_REGULAR);

        ($this->handler)(new SendUnreadNotificationEmail(
            playerId: PlayerFixture::PLAYER_REGULAR,
            playerEmail: PlayerFixture::PLAYER_REGULAR_EMAIL,
            playerName: PlayerFixture::PLAYER_REGULAR_NAME,
            playerLocale: 'en',
            summaries: [],
            pendingRequestCount: 1,
            unreadNotificationCount: 0,
            oldestUnreadAt: null,
            oldestPendingAt: new DateTimeImmutable('-2 days'),
        ));
        $this->entityManager->flush();

        $logCountAfter = $this->countRequestNotificationLogs(PlayerFixture::PLAYER_REGULAR);
        self::assertSame($logCountBefore + 1, $logCountAfter);
    }

    public function testCreatesBothLogsWhenBothMessagesAndRequestsExist(): void
    {
        $messageLogBefore = $this->countMessageNotificationLogs(PlayerFixture::PLAYER_REGULAR);
        $requestLogBefore = $this->countRequestNotificationLogs(PlayerFixture::PLAYER_REGULAR);

        ($this->handler)(new SendUnreadNotificationEmail(
            playerId: PlayerFixture::PLAYER_REGULAR,
            playerEmail: PlayerFixture::PLAYER_REGULAR_EMAIL,
            playerName: PlayerFixture::PLAYER_REGULAR_NAME,
            playerLocale: 'en',
            summaries: [
                new UnreadMessageSummary(
                    senderName: 'Admin User',
                    senderCode: 'admin',
                    unreadCount: 2,
                    puzzleName: null,
                    conversationId: ConversationFixture::CONVERSATION_ACCEPTED,
                ),
            ],
            pendingRequestCount: 1,
            unreadNotificationCount: 0,
            oldestUnreadAt: new DateTimeImmutable('-2 days'),
            oldestPendingAt: new DateTimeImmutable('-2 days'),
        ));
        $this->entityManager->flush();

        $messageLogAfter = $this->countMessageNotificationLogs(PlayerFixture::PLAYER_REGULAR);
        $requestLogAfter = $this->countRequestNotificationLogs(PlayerFixture::PLAYER_REGULAR);
        self::assertSame($messageLogBefore + 1, $messageLogAfter);
        self::assertSame($requestLogBefore + 1, $requestLogAfter);
    }

    public function testDoesNotCreateMessageLogWhenNoUnreadMessages(): void
    {
        $logCountBefore = $this->countMessageNotificationLogs(PlayerFixture::PLAYER_REGULAR);

        ($this->handler)(new SendUnreadNotificationEmail(
            playerId: PlayerFixture::PLAYER_REGULAR,
            playerEmail: PlayerFixture::PLAYER_REGULAR_EMAIL,
            playerName: PlayerFixture::PLAYER_REGULAR_NAME,
            playerLocale: 'en',
            summaries: [],
            pendingRequestCount: 1,
            unreadNotificationCount: 0,
            oldestUnreadAt: null,
            oldestPendingAt: new DateTimeImmutable('-2 days'),
        ));
        $this->entityManager->flush();

        $logCountAfter = $this->countMessageNotificationLogs(PlayerFixture::PLAYER_REGULAR);
        self::assertSame($logCountBefore, $logCountAfter);
    }

    public function testDoesNotCreateRequestLogWhenNoPendingRequests(): void
    {
        $logCountBefore = $this->countRequestNotificationLogs(PlayerFixture::PLAYER_REGULAR);

        ($this->handler)(new SendUnreadNotificationEmail(
            playerId: PlayerFixture::PLAYER_REGULAR,
            playerEmail: PlayerFixture::PLAYER_REGULAR_EMAIL,
            playerName: PlayerFixture::PLAYER_REGULAR_NAME,
            playerLocale: 'en',
            summaries: [
                new UnreadMessageSummary(
                    senderName: 'Admin User',
                    senderCode: 'admin',
                    unreadCount: 2,
                    puzzleName: null,
                    conversationId: ConversationFixture::CONVERSATION_ACCEPTED,
                ),
            ],
            pendingRequestCount: 0,
            unreadNotificationCount: 0,
            oldestUnreadAt: new DateTimeImmutable('-2 days'),
            oldestPendingAt: null,
        ));
        $this->entityManager->flush();

        $logCountAfter = $this->countRequestNotificationLogs(PlayerFixture::PLAYER_REGULAR);
        self::assertSame($logCountBefore, $logCountAfter);
    }

    private function countMessageNotificationLogs(string $playerId): int
    {
        /** @var int $count */
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM message_notification_log WHERE player_id = :playerId',
            ['playerId' => $playerId],
        );

        return $count;
    }

    private function countRequestNotificationLogs(string $playerId): int
    {
        /** @var int $count */
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM request_notification_log WHERE player_id = :playerId',
            ['playerId' => $playerId],
        );

        return $count;
    }
}
