<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetNotifications;
use SpeedPuzzling\Web\Results\PlayerNotification;
use SpeedPuzzling\Web\Tests\DataFixtures\LentPuzzleTransferFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\TestingViewer;
use SpeedPuzzling\Web\Value\Puzzler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetNotificationsTest extends KernelTestCase
{
    private GetNotifications $query;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->query = $container->get(GetNotifications::class);
        $this->database = $container->get(Connection::class);
    }

    public function testPuzzleSolvingNotificationContainsFirstAttemptFlag(): void
    {
        $notifications = $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, 50);

        $found = false;
        foreach ($notifications as $notification) {
            if ($notification->isPuzzleSolvingNotification() && $notification->firstAttempt === true && $notification->unboxed === false) {
                $found = true;
                break;
            }
        }

        self::assertTrue($found, 'Should find a notification with firstAttempt=true and unboxed=false');
    }

    public function testPuzzleSolvingNotificationContainsUnboxedFlag(): void
    {
        $notifications = $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, 50);

        $found = false;
        foreach ($notifications as $notification) {
            if ($notification->isPuzzleSolvingNotification() && $notification->unboxed === true) {
                $found = true;
                break;
            }
        }

        self::assertTrue($found, 'Should find a notification with unboxed=true');
    }

    public function testPuzzleSolvingNotificationWithoutFirstAttempt(): void
    {
        $notifications = $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, 50);

        $found = false;
        foreach ($notifications as $notification) {
            if ($notification->isPuzzleSolvingNotification() && $notification->firstAttempt === false) {
                $found = true;
                break;
            }
        }

        self::assertTrue($found, 'Should find a notification with firstAttempt=false');
    }

    public function testNotificationsAboutAHiddenPlayerAreLeftOutAndTheBadgeAgrees(): void
    {
        // PLAYER_WITH_FAVORITES holds notifications about times of PLAYER_REGULAR and PLAYER_ADMIN
        $this->block(PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_REGULAR);

        $unfiltered = $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, 100);
        self::assertContains(PlayerFixture::PLAYER_REGULAR, $this->targetPlayerIds($unfiltered));
        self::assertSame(count($unfiltered), $this->query->countUnreadForPlayer(PlayerFixture::PLAYER_WITH_FAVORITES));

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_FAVORITES);

        $filtered = $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, 100);
        self::assertNotEmpty($filtered);
        self::assertLessThan(count($unfiltered), count($filtered));
        self::assertNotContains(PlayerFixture::PLAYER_REGULAR, $this->targetPlayerIds($filtered));
        self::assertContains(PlayerFixture::PLAYER_ADMIN, $this->targetPlayerIds($filtered));

        self::assertSame(count($filtered), $this->query->countUnreadForPlayer(PlayerFixture::PLAYER_WITH_FAVORITES));
        self::assertEquals(
            min(array_map(static fn (PlayerNotification $notification): \DateTimeImmutable => $notification->notifiedAt, $filtered)),
            $this->query->getOldestUnreadNotifiedAtForPlayer(PlayerFixture::PLAYER_WITH_FAVORITES),
        );
    }

    public function testNotificationAboutAGroupTimeWithAHiddenMemberIsLeftOut(): void
    {
        // TIME_12: tracked by PLAYER_REGULAR (not hidden) together with PLAYER_PRIVATE (hidden)
        $this->notify(PlayerFixture::PLAYER_WITH_FAVORITES, 'SubscribedPlayerAddedTime', 'target_solving_time_id', PuzzleSolvingTimeFixture::TIME_12);
        $this->block(PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_PRIVATE);

        $before = $this->query->countUnreadForPlayer(PlayerFixture::PLAYER_WITH_FAVORITES);

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_FAVORITES);

        $filtered = $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, 100);
        self::assertLessThan($before, count($filtered));
        self::assertSame(count($filtered), $this->query->countUnreadForPlayer(PlayerFixture::PLAYER_WITH_FAVORITES));

        foreach ($filtered as $notification) {
            self::assertNotSame(PlayerFixture::PLAYER_PRIVATE, $notification->conversationInitiatorId);
            self::assertFalse(Puzzler::listContainsPlayer($notification->players, PlayerFixture::PLAYER_PRIVATE));
        }
    }

    public function testLendingHistoryWithAHiddenPlayerStaysReadable(): void
    {
        // TRANSFER_06: PLAYER_REGULAR passed a puzzle to PLAYER_WITH_FAVORITES
        $this->notify(PlayerFixture::PLAYER_WITH_FAVORITES, 'PuzzlePassedToYou', 'target_transfer_id', LentPuzzleTransferFixture::TRANSFER_06);
        $this->block(PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_REGULAR);

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_FAVORITES);

        $lending = array_values(array_filter(
            $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, 100),
            static fn (PlayerNotification $notification): bool => $notification->isLendingNotification(),
        ));

        self::assertCount(1, $lending);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $lending[0]->fromPlayerId);
        self::assertSame(
            count($this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, 100)),
            $this->query->countUnreadForPlayer(PlayerFixture::PLAYER_WITH_FAVORITES),
        );
    }

    /**
     * @param array<PlayerNotification> $notifications
     * @return list<null|string>
     */
    private function targetPlayerIds(array $notifications): array
    {
        return array_values(array_map(
            static fn (PlayerNotification $notification): null|string => $notification->targetPlayerId,
            $notifications,
        ));
    }

    private function notify(string $playerId, string $type, string $targetColumn, string $targetId): void
    {
        $this->database->executeStatement(
            "INSERT INTO notification (id, player_id, type, notified_at, {$targetColumn}) VALUES (:id, :player, :type, NOW(), :target)",
            ['id' => Uuid::uuid7()->toString(), 'player' => $playerId, 'type' => $type, 'target' => $targetId],
        );
    }

    private function block(string $blockerId, string $blockedId): void
    {
        $this->database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => $blockerId, 'blocked' => $blockedId],
        );
    }
}
