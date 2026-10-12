<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Nette\Utils\Json;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Events\PuzzleSolved;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Query\GetInboxBadge;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\InboxBadgeParts;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Favorites' times left the bell (docs/features/navigation INB-03, README D4): NotifyWhenPuzzleSolved is deleted, so a
 * solve - PuzzleSolved - creates no notification for anybody who follows the solver, and no follower's Inbox badge
 * moves. Their content is Home › Favorites. The file and every case of the old fan-out stay until the D4 cleanup
 * (decision 49), each inverted: the same followers, allow lists and blocks, and nobody is told.
 */
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

    public function testNoFollowerGetsANotificationNorAMovedBadge(): void
    {
        $followers = $this->createFollowersOf([PlayerFixture::PLAYER_REGULAR], 12);
        // The fixture player already follows the regular player (and admin)
        $followers[] = PlayerFixture::PLAYER_WITH_FAVORITES;
        $badges = $this->badgesOf($followers);
        $rows = $this->notificationCountOf($followers);

        $timeId = $this->addTime(PlayerFixture::PLAYER_REGULAR_USER_ID, PuzzleFixture::PUZZLE_1500_01);

        // The event itself, dispatched once more, tells nobody either
        $this->messageBus->dispatch(new PuzzleSolved($timeId, Uuid::fromString(PuzzleFixture::PUZZLE_1500_01)));

        self::assertSame([], $this->notificationsOf($timeId));
        self::assertSame($rows, $this->notificationCountOf($followers), 'A follower got a notification');
        self::assertEquals($badges, $this->badgesOf($followers), "A follower's Inbox badge moved");
        // Nor the solver
        self::assertSame(0, $this->notificationCountOf([PlayerFixture::PLAYER_REGULAR])[PlayerFixture::PLAYER_REGULAR]);
    }

    public function testNoNotificationInsertAndNoFollowerLookupWhateverTheNumberOfFollowers(): void
    {
        $followers = $this->createFollowersOf([PlayerFixture::PLAYER_REGULAR], 25);
        $badges = $this->badgesOf($followers);

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

        self::assertSame([], $this->notificationsOf($timeId));
        self::assertSame([], $notificationInserts, 'A solve writes no notification');
        self::assertEquals($badges, $this->badgesOf($followers));
        // The followers are not even looked up (GetSubscribedPlayers stays only until the D4 cleanup), nor who blocks
        // the solver
        self::assertSame([], array_values(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'favorite_players::jsonb')
                || str_contains($sql, 'SELECT DISTINCT blocker_id FROM user_block'),
        )));
        // 44 while the fan-out ran (one INSERT for every follower, the followers' lookup, who blocks the solver);
        // INB-03 leaves out those 3. The rest: 6 record the solve's prediction (a savepoint pair + the 4 reads of a
        // personal prediction - prediction history, SolvingTimePredictor), 3 guard against saving it twice (the lock on
        // the id, the id lookup + GetRecentIdenticalSolvingTime, docs/features/duplicate-results.md), 3 look for a twin
        // right after the save (a savepoint pair + the scoped candidate query, DetectDuplicateResultsOnSave), 4
        // dispatch RecalculateBadgesForPlayer + AwardXpForSolvingTime (async - a savepoint pair each, the
        // doctrine_transaction middleware wraps every dispatch inside the open transaction)
        self::assertLessThanOrEqual(41, count($queries), implode("\n", $queries));
    }

    public function testNoFollowerOfAnyTeamMemberIsNotified(): void
    {
        // PLAYER_WITH_FAVORITES follows both the regular player and the admin
        $adminOnlyFollowers = $this->createFollowersOf([PlayerFixture::PLAYER_ADMIN], 2);
        $privateOnlyFollowers = $this->createFollowersOf([PlayerFixture::PLAYER_PRIVATE], 2);
        $followers = [PlayerFixture::PLAYER_WITH_FAVORITES, ...$adminOnlyFollowers, ...$privateOnlyFollowers];
        $badges = $this->badgesOf($followers);
        $rows = $this->notificationCountOf($followers);

        $timeId = $this->addTime(PlayerFixture::PLAYER_REGULAR_USER_ID, PuzzleFixture::PUZZLE_1500_01, ['#admin', '#player2', 'Guest Puzzler']);

        self::assertSame([], $this->notificationsOf($timeId));
        self::assertSame($rows, $this->notificationCountOf($followers));
        self::assertEquals($badges, $this->badgesOf($followers));
    }

    public function testNoFollowerIsNotifiedWhetherTheyBlockTheSolverOrNot(): void
    {
        $followers = $this->createFollowersOf([PlayerFixture::PLAYER_REGULAR], 3);
        $this->block($followers[0], PlayerFixture::PLAYER_REGULAR);
        // A block in the other direction changes nothing
        $this->block(PlayerFixture::PLAYER_REGULAR, $followers[1]);
        $followers[] = PlayerFixture::PLAYER_WITH_FAVORITES;
        $badges = $this->badgesOf($followers);

        $timeId = $this->addTime(PlayerFixture::PLAYER_REGULAR_USER_ID, PuzzleFixture::PUZZLE_1500_01);

        self::assertSame([], $this->notificationsOf($timeId));
        self::assertEquals($badges, $this->badgesOf($followers));
    }

    public function testNoFollowerIsNotifiedWhetherTheyBlockAGroupMemberOrNot(): void
    {
        $followers = $this->createFollowersOf([PlayerFixture::PLAYER_REGULAR], 2);
        // The private partner would be shown in the notification
        $this->block($followers[0], PlayerFixture::PLAYER_PRIVATE);
        $followers[] = PlayerFixture::PLAYER_WITH_FAVORITES;
        $badges = $this->badgesOf($followers);

        $timeId = $this->addTime(PlayerFixture::PLAYER_REGULAR_USER_ID, PuzzleFixture::PUZZLE_1500_01, ['#player2']);

        self::assertSame([], $this->notificationsOf($timeId));
        self::assertEquals($badges, $this->badgesOf($followers));
    }

    public function testPrivateSoloPlayerNotifiesNobody(): void
    {
        $followers = $this->createFollowersOf([PlayerFixture::PLAYER_PRIVATE], 3);
        $badges = $this->badgesOf($followers);

        $timeId = $this->addTime(self::PLAYER_PRIVATE_USER_ID, PuzzleFixture::PUZZLE_1500_01);

        self::assertSame([], $this->notificationsOf($timeId));
        self::assertEquals($badges, $this->badgesOf($followers));
    }

    /**
     * docs/features/private-profile-allow-list.md: following a private player is anybody's choice, seeing them the
     * private player's - an allowed follower now sees her times on Home › Favorites and is told nothing either.
     */
    public function testPrivateSoloPlayerNotifiesNoFollowerEvenOnTheirAllowList(): void
    {
        [$allowedFollower, $follower] = $this->createFollowersOf([PlayerFixture::PLAYER_PRIVATE], 2);
        [$allowedButNotFollowing] = $this->createFollowersOf([PlayerFixture::PLAYER_ADMIN], 1);
        $this->allow(PlayerFixture::PLAYER_PRIVATE, $allowedFollower);
        $this->allow(PlayerFixture::PLAYER_PRIVATE, $allowedButNotFollowing);
        $badges = $this->badgesOf([$allowedFollower, $follower, $allowedButNotFollowing]);

        $timeId = $this->addTime(self::PLAYER_PRIVATE_USER_ID, PuzzleFixture::PUZZLE_1500_01);

        self::assertSame([], $this->notificationsOf($timeId));
        self::assertEquals($badges, $this->badgesOf([$allowedFollower, $follower, $allowedButNotFollowing]));
    }

    public function testAllowedFollowerThePrivatePlayerBlockedIsNotNotified(): void
    {
        [$allowedFollower] = $this->createFollowersOf([PlayerFixture::PLAYER_PRIVATE], 1);
        $this->allow(PlayerFixture::PLAYER_PRIVATE, $allowedFollower);
        $this->block(PlayerFixture::PLAYER_PRIVATE, $allowedFollower);
        $badges = $this->badgesOf([$allowedFollower]);

        $timeId = $this->addTime(self::PLAYER_PRIVATE_USER_ID, PuzzleFixture::PUZZLE_1500_01);

        self::assertSame([], $this->notificationsOf($timeId));
        self::assertEquals($badges, $this->badgesOf([$allowedFollower]));
    }

    public function testPlayerWithoutFollowersNotifiesNobody(): void
    {
        $this->database->executeStatement("UPDATE player SET favorite_players = '[]'");
        $badges = $this->badgesOf([PlayerFixture::PLAYER_WITH_FAVORITES]);

        $timeId = $this->addTime(self::PLAYER_ADMIN_USER_ID, PuzzleFixture::PUZZLE_1500_01);

        self::assertSame([], $this->notificationsOf($timeId));
        self::assertEquals($badges, $this->badgesOf([PlayerFixture::PLAYER_WITH_FAVORITES]));
    }

    /**
     * Each player's Inbox badge (GetInboxBadge, docs/features/navigation INB-02), their blocks left out as on any page.
     *
     * @param list<string> $playerIds
     * @return array<string, InboxBadgeParts>
     */
    private function badgesOf(array $playerIds): array
    {
        $badge = self::getContainer()->get(GetInboxBadge::class);
        $badges = [];

        foreach ($playerIds as $playerId) {
            /** @var list<string> $hiddenIds */
            $hiddenIds = $this->database->fetchFirstColumn('SELECT blocked_id FROM user_block WHERE blocker_id = :id', ['id' => $playerId]);
            $badges[$playerId] = $badge->forPlayer($playerId, $hiddenIds);
        }

        return $badges;
    }

    /**
     * Every notification row each player holds, of any type.
     *
     * @param list<string> $playerIds
     * @return array<string, int>
     */
    private function notificationCountOf(array $playerIds): array
    {
        $counts = [];

        foreach ($playerIds as $playerId) {
            /** @var int|string $count */
            $count = $this->database->fetchOne('SELECT COUNT(*) FROM notification WHERE player_id = :id', ['id' => $playerId]);
            $counts[$playerId] = (int) $count;
        }

        return $counts;
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
