<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\MarkNotificationsAsRead;
use SpeedPuzzling\Web\Tests\DataFixtures\NotificationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class MarkNotificationsAsReadHandlerTest extends KernelTestCase
{
    /**
     * A visit marks the unread notifications as read - and leaves the ones read before alone, with the time
     * they really were read (it used to rewrite every notification of the player on every visit).
     */
    public function testOnlyUnreadNotificationsAreMarkedAndOldReadTimesStay(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $database = $container->get(Connection::class);

        $database->executeStatement(
            "UPDATE notification SET read_at = '2026-01-02 03:04:05' WHERE id = :id",
            ['id' => NotificationFixture::NOTIFICATION_FIRST_ATTEMPT],
        );
        $database->executeStatement(
            'UPDATE notification SET read_at = NULL WHERE id IN (:unboxed, :regular)',
            ['unboxed' => NotificationFixture::NOTIFICATION_UNBOXED, 'regular' => NotificationFixture::NOTIFICATION_REGULAR],
        );

        $container->get(MessageBusInterface::class)->dispatch(new MarkNotificationsAsRead(PlayerFixture::PLAYER_WITH_FAVORITES));

        /** @var array<string, null|string> $readAt */
        $readAt = $database->fetchAllKeyValue(
            'SELECT id, read_at FROM notification WHERE id IN (:first, :unboxed, :regular)',
            [
                'first' => NotificationFixture::NOTIFICATION_FIRST_ATTEMPT,
                'unboxed' => NotificationFixture::NOTIFICATION_UNBOXED,
                'regular' => NotificationFixture::NOTIFICATION_REGULAR,
            ],
        );

        self::assertSame('2026-01-02 03:04:05', $readAt[NotificationFixture::NOTIFICATION_FIRST_ATTEMPT]);
        self::assertNotNull($readAt[NotificationFixture::NOTIFICATION_UNBOXED]);
        self::assertNotNull($readAt[NotificationFixture::NOTIFICATION_REGULAR]);
        self::assertNotSame('2026-01-02 03:04:05', $readAt[NotificationFixture::NOTIFICATION_UNBOXED]);
    }
}
