<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ModerationAction;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\DeletePlayer;
use SpeedPuzzling\Web\Query\GetModerationActions;
use SpeedPuzzling\Web\Tests\DataFixtures\ComparisonSubjectFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ModerationActionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
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

    public function testComparisonLineUpsFollowTheMergedPair(): void
    {
        $connection = $this->entityManager->getConnection();
        $private = $this->entityManager->find(Player::class, PlayerFixture::PLAYER_PRIVATE);
        self::assertNotNull($private);

        // PLAYER_WITH_STRIPE compares the account pair (ComparisonSubjectFixture::STRIPE_PAIR)
        $accountPairId = $connection->fetchOne('SELECT puzzling_team_id FROM puzzle_solving_time WHERE id = :id', ['id' => PuzzleSolvingTimeFixture::TIME_12]);
        self::assertIsString($accountPairId);

        $this->messageBus->dispatch(new AddPuzzleSolvingTime(
            timeId: $guestTimeId = Uuid::uuid7(),
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            puzzleId: PuzzleFixture::PUZZLE_1500_01,
            competitionId: null,
            time: '03:00:00',
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: [$private->name ?? $private->code],
            finishedAt: null,
            firstAttempt: false,
            unboxed: false,
        ));
        $guestPairId = $connection->fetchOne('SELECT puzzling_team_id FROM puzzle_solving_time WHERE id = :id', ['id' => $guestTimeId->toString()]);
        self::assertIsString($guestPairId);

        // PLAYER_ADMIN compares both pairs, PLAYER_WITH_FAVORITES the guest one only
        $this->insertComparisonRow(PlayerFixture::PLAYER_ADMIN, $accountPairId);
        $adminGuestRow = $this->insertComparisonRow(PlayerFixture::PLAYER_ADMIN, $guestPairId);
        $favoritesRow = $this->insertComparisonRow(PlayerFixture::PLAYER_WITH_FAVORITES, $guestPairId);

        $this->messageBus->dispatch(new DeletePlayer(PlayerFixture::PLAYER_PRIVATE));

        // The account pair merged into the guest one: same row, now comparing the survivor
        self::assertSame($guestPairId, $connection->fetchOne('SELECT subject_team_id FROM comparison_subject WHERE id = :id', ['id' => ComparisonSubjectFixture::STRIPE_PAIR]));
        // Who had both keeps one
        self::assertSame(
            [['id' => $adminGuestRow, 'subject_team_id' => $guestPairId]],
            $connection->fetchAllAssociative('SELECT id, subject_team_id FROM comparison_subject WHERE player_id = :id AND subject_team_id IS NOT NULL', ['id' => PlayerFixture::PLAYER_ADMIN]),
        );
        self::assertSame($guestPairId, $connection->fetchOne('SELECT subject_team_id FROM comparison_subject WHERE id = :id', ['id' => $favoritesRow]));
    }

    public function testDeletingAPlayerRemovesTheirLineUpsAndTheirPlaceInOthers(): void
    {
        $this->messageBus->dispatch(new DeletePlayer(PlayerFixture::PLAYER_REGULAR));

        $connection = $this->entityManager->getConnection();

        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM comparison_subject WHERE player_id = :id OR subject_player_id = :id', ['id' => PlayerFixture::PLAYER_REGULAR]));
        // Their pair lives on (with them as a guest) and so does its place in other line-ups
        self::assertSame(
            [ComparisonSubjectFixture::STRIPE_SELF, ComparisonSubjectFixture::STRIPE_ADMIN, ComparisonSubjectFixture::STRIPE_PAIR],
            $connection->fetchFirstColumn('SELECT id FROM comparison_subject WHERE player_id = :id ORDER BY added_at, id', ['id' => PlayerFixture::PLAYER_WITH_STRIPE]),
        );
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

    public function testDeletingAnAdminKeepsTheirModerationActionsWithoutThem(): void
    {
        // PLAYER_ADMIN performed both fixture moderation actions - the FK used to block the deletion
        $this->messageBus->dispatch(new DeletePlayer(PlayerFixture::PLAYER_ADMIN));
        $this->entityManager->clear();

        self::assertNull($this->entityManager->find(Player::class, PlayerFixture::PLAYER_ADMIN));

        foreach ([ModerationActionFixture::ACTION_WARNING, ModerationActionFixture::ACTION_EXPIRED_MUTE] as $actionId) {
            $action = $this->entityManager->find(ModerationAction::class, $actionId);

            self::assertNotNull($action, 'The moderation record must survive the deletion of the admin');
            self::assertNull($action->admin);
            self::assertNotNull($action->targetPlayer);
        }

        $history = self::getContainer()->get(GetModerationActions::class)->forPlayer(PlayerFixture::PLAYER_REGULAR);

        self::assertCount(1, $history);
        self::assertNull($history[0]->adminName);
    }

    /**
     * docs/features/organizations/README.md: an organization survives its creator - like a series; the deleted player
     * leaves every organization team.
     */
    public function testOrganizationsSurviveTheirCreatorAndLoseTheDeletedMaintainer(): void
    {
        // PLAYER_WITH_FAVORITES created Maple and Cedar and maintains Riverbend
        $this->messageBus->dispatch(new DeletePlayer(PlayerFixture::PLAYER_WITH_FAVORITES));
        $this->entityManager->clear();

        $connection = $this->entityManager->getConnection();

        foreach ([OrganizationFixture::ORGANIZATION_MAPLE_PENDING, OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT] as $organizationId) {
            $row = $connection->fetchAssociative('SELECT id, added_by_player_id FROM organization WHERE id = :id', ['id' => $organizationId]);
            self::assertIsArray($row, $organizationId . ' must survive');
            self::assertNull($row['added_by_player_id']);
        }

        self::assertSame(0, $connection->fetchOne(
            'SELECT COUNT(*) FROM organization_maintainer WHERE player_id = :player',
            ['player' => PlayerFixture::PLAYER_WITH_FAVORITES],
        ));
        self::assertSame(PlayerFixture::PLAYER_WITH_STRIPE, $connection->fetchOne(
            'SELECT added_by_player_id FROM organization WHERE id = :id',
            ['id' => OrganizationFixture::ORGANIZATION_RIVERBEND],
        ));
    }

    public function testAnApproverOfAnOrganizationCanBeDeleted(): void
    {
        // PLAYER_ADMIN approved Riverbend and the Harbor Puzzle Club
        $this->messageBus->dispatch(new DeletePlayer(PlayerFixture::PLAYER_ADMIN));
        $this->entityManager->clear();

        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT approved_at, approved_by_player_id FROM organization WHERE id = :id',
            ['id' => OrganizationFixture::ORGANIZATION_RIVERBEND],
        );

        self::assertIsArray($row);
        self::assertNotNull($row['approved_at']);
        self::assertNull($row['approved_by_player_id']);
    }

    private function insertComparisonRow(string $ownerId, string $teamId): string
    {
        $id = Uuid::uuid7()->toString();

        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO comparison_subject (id, player_id, subject_team_id, added_at) VALUES (:id, :owner, :team, NOW())',
            ['id' => $id, 'owner' => $ownerId, 'team' => $teamId],
        );

        return $id;
    }
}
