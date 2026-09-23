<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Nette\Utils\Json;
use Ramsey\Uuid\Rfc4122\FieldsInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\NotificationType;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class NotifyWhenPuzzleSolvedTest extends KernelTestCase
{
    private const string PLAYER_ADMIN_USER_ID = 'auth0|admin003';
    private const string PLAYER_PRIVATE_USER_ID = 'auth0|private002';

    private MessageBusInterface $messageBus;
    private Connection $database;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var MessageBusInterface $messageBus */
        $messageBus = $container->get(MessageBusInterface::class);
        $this->messageBus = $messageBus;

        /** @var Connection $database */
        $database = $container->get(Connection::class);
        $this->database = $database;

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        $this->entityManager = $entityManager;
    }

    public function testEveryFollowerGetsExactlyOneNotificationWithTheSameFieldsAsBefore(): void
    {
        $followers = $this->createFollowersOf([PlayerFixture::PLAYER_REGULAR], 12);
        // The fixture player already follows the regular player (and admin)
        $followers[] = PlayerFixture::PLAYER_WITH_FAVORITES;

        $before = new DateTimeImmutable('-1 second');
        $timeId = $this->addTime(PlayerFixture::PLAYER_REGULAR_USER_ID, PuzzleFixture::PUZZLE_1500_01);
        $after = new DateTimeImmutable('+1 second');

        $notifications = $this->notificationsOf($timeId);

        self::assertEqualsCanonicalizing($followers, array_column($notifications, 'player_id'));

        foreach ($notifications as $notification) {
            $id = Uuid::fromString($notification['id']);
            $fields = $id->getFields();
            self::assertInstanceOf(FieldsInterface::class, $fields);
            self::assertSame(7, $fields->getVersion(), 'Notification ids stay uuid7');

            self::assertSame(NotificationType::SubscribedPlayerAddedTime->value, $notification['type']);
            self::assertNull($notification['read_at']);
            self::assertNull($notification['target_transfer_id']);
            self::assertNull($notification['target_change_request_id']);
            self::assertNull($notification['target_merge_request_id']);
            self::assertNull($notification['target_sold_swapped_item_id']);
            self::assertNull($notification['target_conversation_id']);

            $notifiedAt = new DateTimeImmutable($notification['notified_at']);
            self::assertGreaterThanOrEqual($before->getTimestamp(), $notifiedAt->getTimestamp());
            self::assertLessThanOrEqual($after->getTimestamp(), $notifiedAt->getTimestamp());
        }

        // Nobody else is notified - the solver does not follow themselves
        self::assertNotContains(PlayerFixture::PLAYER_REGULAR, array_column($notifications, 'player_id'));
    }

    public function testFanOutIsOneInsertWhateverTheNumberOfFollowers(): void
    {
        $this->createFollowersOf([PlayerFixture::PLAYER_REGULAR], 25);

        /** @var DebugDataHolder $debugDataHolder */
        $debugDataHolder = self::getContainer()->get('doctrine.debug_data_holder');
        $debugDataHolder->reset();

        $timeId = $this->addTime(PlayerFixture::PLAYER_REGULAR_USER_ID, PuzzleFixture::PUZZLE_1500_01);

        /** @var list<array{sql: string}> $executed */
        $executed = $debugDataHolder->getData()['default'] ?? [];
        $queries = array_column($executed, 'sql');
        $notificationInserts = array_filter(
            $queries,
            static fn (string $sql): bool => str_starts_with($sql, 'INSERT INTO notification'),
        );

        self::assertCount(26, $this->notificationsOf($timeId));
        self::assertCount(1, $notificationInserts, 'All followers are notified by a single INSERT');
        // 53 queries before the fan-out became one INSERT (one per follower + loading the follower entities);
        // one of the 28 asks who blocks the solver
        self::assertLessThanOrEqual(28, count($queries), implode("\n", $queries));
    }

    public function testTeamMemberFollowedTwiceIsNotifiedOnceAndPrivateMembersNotifyNobody(): void
    {
        // PLAYER_WITH_FAVORITES follows both the regular player and the admin
        $adminOnlyFollowers = $this->createFollowersOf([PlayerFixture::PLAYER_ADMIN], 2);
        $privateOnlyFollowers = $this->createFollowersOf([PlayerFixture::PLAYER_PRIVATE], 2);

        $timeId = $this->addTime(PlayerFixture::PLAYER_REGULAR_USER_ID, PuzzleFixture::PUZZLE_1500_01, ['#admin', '#player2', 'Guest Puzzler']);

        $notifiedPlayers = array_column($this->notificationsOf($timeId), 'player_id');

        self::assertEqualsCanonicalizing(
            [PlayerFixture::PLAYER_WITH_FAVORITES, ...$adminOnlyFollowers],
            $notifiedPlayers,
        );
        self::assertEmpty(array_intersect($privateOnlyFollowers, $notifiedPlayers));
    }

    public function testFollowerWhoBlocksTheSolverIsNotNotified(): void
    {
        $followers = $this->createFollowersOf([PlayerFixture::PLAYER_REGULAR], 3);
        $this->block($followers[0], PlayerFixture::PLAYER_REGULAR);
        // A block in the other direction changes nothing
        $this->block(PlayerFixture::PLAYER_REGULAR, $followers[1]);

        $timeId = $this->addTime(PlayerFixture::PLAYER_REGULAR_USER_ID, PuzzleFixture::PUZZLE_1500_01);

        self::assertEqualsCanonicalizing(
            [$followers[1], $followers[2], PlayerFixture::PLAYER_WITH_FAVORITES],
            array_column($this->notificationsOf($timeId), 'player_id'),
        );
    }

    public function testFollowerWhoBlocksAnyGroupMemberIsNotNotified(): void
    {
        $followers = $this->createFollowersOf([PlayerFixture::PLAYER_REGULAR], 2);
        // The private partner notifies nobody, but would be shown in the notification
        $this->block($followers[0], PlayerFixture::PLAYER_PRIVATE);

        $timeId = $this->addTime(PlayerFixture::PLAYER_REGULAR_USER_ID, PuzzleFixture::PUZZLE_1500_01, ['#player2']);

        self::assertEqualsCanonicalizing(
            [$followers[1], PlayerFixture::PLAYER_WITH_FAVORITES],
            array_column($this->notificationsOf($timeId), 'player_id'),
        );
    }

    public function testPrivateSoloPlayerNotifiesNobody(): void
    {
        $this->createFollowersOf([PlayerFixture::PLAYER_PRIVATE], 3);

        $timeId = $this->addTime(self::PLAYER_PRIVATE_USER_ID, PuzzleFixture::PUZZLE_1500_01);

        self::assertSame([], $this->notificationsOf($timeId));
    }

    /**
     * docs/features/private-profile-allow-list.md: following a private player is anybody's choice,
     * hearing about them is the private player's - only followers on their allow list are told.
     */
    public function testPrivateSoloPlayerNotifiesOnlyFollowersOnTheirAllowList(): void
    {
        [$allowedFollower, $follower] = $this->createFollowersOf([PlayerFixture::PLAYER_PRIVATE], 2);
        [$allowedButNotFollowing] = $this->createFollowersOf([PlayerFixture::PLAYER_ADMIN], 1);
        $this->allow(PlayerFixture::PLAYER_PRIVATE, $allowedFollower);
        $this->allow(PlayerFixture::PLAYER_PRIVATE, $allowedButNotFollowing);

        $timeId = $this->addTime(self::PLAYER_PRIVATE_USER_ID, PuzzleFixture::PUZZLE_1500_01);

        self::assertSame([$allowedFollower], array_column($this->notificationsOf($timeId), 'player_id'));
        self::assertNotContains($follower, array_column($this->notificationsOf($timeId), 'player_id'));
    }

    public function testAllowedFollowerThePrivatePlayerBlockedIsNotNotified(): void
    {
        [$allowedFollower] = $this->createFollowersOf([PlayerFixture::PLAYER_PRIVATE], 1);
        $this->allow(PlayerFixture::PLAYER_PRIVATE, $allowedFollower);
        $this->block(PlayerFixture::PLAYER_PRIVATE, $allowedFollower);

        $timeId = $this->addTime(self::PLAYER_PRIVATE_USER_ID, PuzzleFixture::PUZZLE_1500_01);

        self::assertSame([], $this->notificationsOf($timeId));
    }

    public function testPlayerWithoutFollowersNotifiesNobody(): void
    {
        $this->database->executeStatement("UPDATE player SET favorite_players = '[]'");

        $timeId = $this->addTime(self::PLAYER_ADMIN_USER_ID, PuzzleFixture::PUZZLE_1500_01);

        self::assertSame([], $this->notificationsOf($timeId));
    }

    private function allow(string $ownerId, string $viewerId): void
    {
        $this->database->executeStatement(
            'INSERT INTO private_profile_viewer (id, owner_id, viewer_id, added_at) VALUES (:id, :owner, :viewer, NOW())',
            ['id' => Uuid::uuid7()->toString(), 'owner' => $ownerId, 'viewer' => $viewerId],
        );
    }

    private function block(string $blockerId, string $blockedId): void
    {
        $this->database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => $blockerId, 'blocked' => $blockedId],
        );
    }

    /**
     * @param list<string> $followedPlayerIds
     * @return list<string>
     */
    private function createFollowersOf(array $followedPlayerIds, int $count): array
    {
        $followerIds = [];

        for ($i = 0; $i < $count; $i++) {
            $id = Uuid::uuid7();
            $this->entityManager->persist(new Player(
                id: $id,
                code: 'follower' . substr(str_replace('-', '', $id->toString()), -12),
                userId: null,
                name: 'Follower ' . $i,
                registeredAt: new DateTimeImmutable(),
            ));
            $followerIds[] = $id->toString();
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        foreach ($followerIds as $followerId) {
            $this->database->executeStatement(
                'UPDATE player SET favorite_players = CAST(:favorites AS json) WHERE id = :id',
                ['favorites' => Json::encode($followedPlayerIds), 'id' => $followerId],
            );
        }

        return $followerIds;
    }

    /**
     * @param array<string> $groupPlayers
     */
    private function addTime(string $userId, string $puzzleId, array $groupPlayers = []): UuidInterface
    {
        $timeId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddPuzzleSolvingTime(
            timeId: $timeId,
            userId: $userId,
            puzzleId: $puzzleId,
            competitionId: null,
            time: '01:30:00',
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: $groupPlayers,
            finishedAt: null,
            firstAttempt: false,
            unboxed: false,
        ));

        return $timeId;
    }

    /**
     * @return list<array{id: string, player_id: string, type: string, notified_at: string, read_at: null|string, target_transfer_id: null|string, target_change_request_id: null|string, target_merge_request_id: null|string, target_sold_swapped_item_id: null|string, target_conversation_id: null|string}>
     */
    private function notificationsOf(UuidInterface $timeId): array
    {
        /** @var list<array{id: string, player_id: string, type: string, notified_at: string, read_at: null|string, target_transfer_id: null|string, target_change_request_id: null|string, target_merge_request_id: null|string, target_sold_swapped_item_id: null|string, target_conversation_id: null|string}> $rows */
        $rows = $this->database->fetchAllAssociative(
            'SELECT id, player_id, type, notified_at, read_at, target_transfer_id, target_change_request_id,
                    target_merge_request_id, target_sold_swapped_item_id, target_conversation_id
             FROM notification
             WHERE target_solving_time_id = :timeId',
            ['timeId' => $timeId->toString()],
        );

        return $rows;
    }
}
