<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\DeletePlayer;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class DeletePlayerHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testThrowsWhenPlayerDoesNotExist(): void
    {
        $this->expectException(PlayerNotFound::class);

        $this->messageBus->dispatch(new DeletePlayer('00000000-0000-0000-0000-000000000999'));
    }

    public function testDeletesPlayerRowAndCascadesPersonalData(): void
    {
        $this->messageBus->dispatch(new DeletePlayer(PlayerFixture::PLAYER_REGULAR));
        $this->entityManager->clear();

        self::assertNull($this->entityManager->find(Player::class, PlayerFixture::PLAYER_REGULAR));
    }

    public function testDeletesUserAccountAndItsAuditTrail(): void
    {
        // The account row (login email + password hash) must not survive a GDPR
        // deletion, and its audit trail cascades with it at the DB level
        $userAccount = $this->entityManager->getRepository(UserAccount::class)
            ->findOneBy(['userId' => PlayerFixture::PLAYER_REGULAR_USER_ID]);
        self::assertNotNull($userAccount);

        $connection = $this->entityManager->getConnection();
        $connection->insert('auth_audit_log', [
            'id' => Uuid::uuid7()->toString(),
            'user_account_id' => $userAccount->id->toString(),
            'email' => $userAccount->email,
            'event_type' => 'login_success',
            'occurred_at' => new \DateTimeImmutable()->format('Y-m-d H:i:sP'),
        ]);

        $this->messageBus->dispatch(new DeletePlayer(PlayerFixture::PLAYER_REGULAR));
        $this->entityManager->clear();

        self::assertNull($this->entityManager->find(UserAccount::class, $userAccount->id));

        /** @var int|string $auditCount */
        $auditCount = $connection->fetchOne(
            'SELECT COUNT(*) FROM auth_audit_log WHERE user_account_id = :id',
            ['id' => $userAccount->id->toString()],
        );
        self::assertSame(0, (int) $auditCount, 'Audit rows must cascade with the account');
    }

    public function testTransfersTeamOwnershipWhenOwnerIsDeleted(): void
    {
        // TIME_12: owner = PLAYER_REGULAR, team = [PLAYER_REGULAR, PLAYER_PRIVATE]
        $regular = $this->entityManager->find(Player::class, PlayerFixture::PLAYER_REGULAR);
        self::assertNotNull($regular);
        $regularName = $regular->name;

        $this->messageBus->dispatch(new DeletePlayer(PlayerFixture::PLAYER_REGULAR));
        $this->entityManager->clear();

        $time = $this->entityManager->find(PuzzleSolvingTime::class, PuzzleSolvingTimeFixture::TIME_12);

        self::assertNotNull($time, 'TIME_12 must still exist; ownership should have transferred');
        self::assertSame(PlayerFixture::PLAYER_PRIVATE, $time->player->id->toString());
        self::assertNotNull($time->team);

        $puzzlerIds = array_map(static fn($p) => $p->playerId, $time->team->puzzlers);
        self::assertNotContains(PlayerFixture::PLAYER_REGULAR, $puzzlerIds, 'Deleted player must be removed from puzzlers');
        self::assertContains(PlayerFixture::PLAYER_PRIVATE, $puzzlerIds, 'New owner stays in puzzlers (current invariant)');

        // The anonymized entry preserves the original name
        $anonymizedNames = array_filter(array_map(
            static fn($p) => $p->playerId === null ? $p->playerName : null,
            $time->team->puzzlers,
        ));
        self::assertContains($regularName, $anonymizedNames);
    }

    public function testAnonymizesTeamMemberWhenNonOwnerIsDeleted(): void
    {
        // TIME_12: owner = PLAYER_REGULAR, team = [PLAYER_REGULAR, PLAYER_PRIVATE]
        $private = $this->entityManager->find(Player::class, PlayerFixture::PLAYER_PRIVATE);
        self::assertNotNull($private);
        $privateName = $private->name;

        $this->messageBus->dispatch(new DeletePlayer(PlayerFixture::PLAYER_PRIVATE));
        $this->entityManager->clear();

        $time = $this->entityManager->find(PuzzleSolvingTime::class, PuzzleSolvingTimeFixture::TIME_12);

        self::assertNotNull($time);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $time->player->id->toString(), 'Ownership unchanged');
        self::assertNotNull($time->team);
        self::assertCount(2, $time->team->puzzlers, 'Anonymized entry preserved, count unchanged');

        $puzzlerIds = array_map(static fn($p) => $p->playerId, $time->team->puzzlers);
        self::assertNotContains(PlayerFixture::PLAYER_PRIVATE, $puzzlerIds);

        $anonymizedNames = array_filter(array_map(
            static fn($p) => $p->playerId === null ? $p->playerName : null,
            $time->team->puzzlers,
        ));
        self::assertContains($privateName, $anonymizedNames);
    }

    public function testDeletedMemberStaysInThePairAsAGuest(): void
    {
        // TIME_12 + TIME_41 belong to the pair PLAYER_REGULAR + PLAYER_PRIVATE
        $connection = $this->entityManager->getConnection();
        $teamId = $connection->fetchOne('SELECT puzzling_team_id FROM puzzle_solving_time WHERE id = :id', ['id' => PuzzleSolvingTimeFixture::TIME_12]);
        self::assertIsString($teamId);
        $keyBefore = $connection->fetchOne('SELECT composition_key FROM puzzling_team WHERE id = :id', ['id' => $teamId]);

        $private = $this->entityManager->find(Player::class, PlayerFixture::PLAYER_PRIVATE);
        self::assertNotNull($private);
        $privateName = $private->name ?? $private->code;

        $this->messageBus->dispatch(new DeletePlayer(PlayerFixture::PLAYER_PRIVATE));

        // Same team, same times - one member is a guest now and the key says so
        self::assertSame($teamId, $connection->fetchOne('SELECT puzzling_team_id FROM puzzle_solving_time WHERE id = :id', ['id' => PuzzleSolvingTimeFixture::TIME_12]));
        self::assertSame($teamId, $connection->fetchOne('SELECT puzzling_team_id FROM puzzle_solving_time WHERE id = :id', ['id' => PuzzleSolvingTimeFixture::TIME_41]));
        self::assertNotSame($keyBefore, $connection->fetchOne('SELECT composition_key FROM puzzling_team WHERE id = :id', ['id' => $teamId]));

        $members = $connection->fetchAllAssociative(
            'SELECT player_id, guest_name FROM puzzling_team_member WHERE team_id = :id ORDER BY position',
            ['id' => $teamId],
        );
        self::assertSame([
            ['player_id' => PlayerFixture::PLAYER_REGULAR, 'guest_name' => null],
            ['player_id' => null, 'guest_name' => $privateName],
        ], $members);

        // A later time with that guest, typed by name, is the very same pair
        $this->messageBus->dispatch(new AddPuzzleSolvingTime(
            timeId: $newTimeId = Uuid::uuid7(),
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            puzzleId: PuzzleFixture::PUZZLE_1500_01,
            competitionId: null,
            time: '03:00:00',
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: [$privateName],
            finishedAt: null,
            firstAttempt: false,
            unboxed: false,
        ));
        self::assertSame($teamId, $connection->fetchOne('SELECT puzzling_team_id FROM puzzle_solving_time WHERE id = :id', ['id' => $newTimeId->toString()]));
    }

    public function testDeletedMemberMergesIntoThePairThatAlreadyKnowsThemAsAGuest(): void
    {
        $connection = $this->entityManager->getConnection();
        $private = $this->entityManager->find(Player::class, PlayerFixture::PLAYER_PRIVATE);
        self::assertNotNull($private);
        $privateName = $private->name ?? $private->code;

        $accountPairId = $connection->fetchOne('SELECT puzzling_team_id FROM puzzle_solving_time WHERE id = :id', ['id' => PuzzleSolvingTimeFixture::TIME_12]);
        self::assertIsString($accountPairId);
        $connection->executeStatement("UPDATE puzzling_team SET name = 'Speedsters' WHERE id = :id", ['id' => $accountPairId]);

        // PLAYER_REGULAR once entered the same person by name only - a second, unnamed pair
        $this->messageBus->dispatch(new AddPuzzleSolvingTime(
            timeId: $guestTimeId = Uuid::uuid7(),
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            puzzleId: PuzzleFixture::PUZZLE_1500_01,
            competitionId: null,
            time: '03:00:00',
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: [$privateName],
            finishedAt: null,
            firstAttempt: false,
            unboxed: false,
        ));
        $guestPairId = $connection->fetchOne('SELECT puzzling_team_id FROM puzzle_solving_time WHERE id = :id', ['id' => $guestTimeId->toString()]);
        self::assertIsString($guestPairId);
        self::assertNotSame($accountPairId, $guestPairId);

        $this->messageBus->dispatch(new DeletePlayer(PlayerFixture::PLAYER_PRIVATE));

        // One pair is left: the guest one, holding all the times and the name
        self::assertSame($guestPairId, $connection->fetchOne('SELECT puzzling_team_id FROM puzzle_solving_time WHERE id = :id', ['id' => PuzzleSolvingTimeFixture::TIME_12]));
        self::assertSame($guestPairId, $connection->fetchOne('SELECT puzzling_team_id FROM puzzle_solving_time WHERE id = :id', ['id' => PuzzleSolvingTimeFixture::TIME_41]));
        self::assertFalse($connection->fetchOne('SELECT id FROM puzzling_team WHERE id = :id', ['id' => $accountPairId]));
        self::assertSame('Speedsters', $connection->fetchOne('SELECT name FROM puzzling_team WHERE id = :id', ['id' => $guestPairId]));
    }

    public function testRemovesSolvingTimeWhenOwnerHasNoOtherTeamMemberWithPlayerId(): void
    {
        // TIME_06: PLAYER_REGULAR solo solve (team = null) — should be hard-deleted
        $this->messageBus->dispatch(new DeletePlayer(PlayerFixture::PLAYER_REGULAR));
        $this->entityManager->clear();

        $time = $this->entityManager->find(PuzzleSolvingTime::class, PuzzleSolvingTimeFixture::TIME_06);

        self::assertNull($time, 'Solo solve of deleted player must be removed');
    }

    public function testScrubsFavoritePlayersFromOtherPlayers(): void
    {
        // PLAYER_WITH_FAVORITES has PLAYER_REGULAR in their favoritePlayers JSON
        $this->messageBus->dispatch(new DeletePlayer(PlayerFixture::PLAYER_REGULAR));
        $this->entityManager->clear();

        $favorites = $this->entityManager->getConnection()
            ->fetchOne(
                'SELECT favorite_players::text FROM player WHERE id = :id',
                ['id' => PlayerFixture::PLAYER_WITH_FAVORITES],
            );

        self::assertIsString($favorites);
        self::assertStringNotContainsString(PlayerFixture::PLAYER_REGULAR, $favorites);
    }
}
