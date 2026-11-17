<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TrackerlessTimeSeeding;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ModerationAction;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\ClaimEventResultSlot;
use SpeedPuzzling\Web\Message\DeletePlayer;
use SpeedPuzzling\Web\Message\RecordPhotoStashEntry;
use SpeedPuzzling\Web\Query\GetModerationActions;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\ParticipantLinking\ParticipantLinks;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionParticipantFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ComparisonSubjectFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ModerationActionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\MessageHandler\ParticipantsSheet\SheetChangeSets;
use SpeedPuzzling\Web\Value\ParticipantLinkMethod;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class DeletePlayerHandlerTest extends KernelTestCase
{
    use TrackerlessTimeSeeding;
    use SheetChangeSets;

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
            'occurred_at' => TestClock::now()->format('Y-m-d H:i:sP'),
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
        self::assertSame(PlayerFixture::PLAYER_PRIVATE, $time->player?->id->toString());
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
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $time->player?->id->toString(), 'Ownership unchanged');
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

    public function testDeletingAPlayerRemovesTheirKeptPhotoDescriptions(): void
    {
        $now = TestClock::now();
        $this->messageBus->dispatch(new RecordPhotoStashEntry(str_repeat('a', 32), PlayerFixture::PLAYER_REGULAR, 'box.jpg', 'image/jpeg', false, 1000, null, $now, $now));
        $this->messageBus->dispatch(new RecordPhotoStashEntry(str_repeat('b', 32), PlayerFixture::PLAYER_WITH_STRIPE, 'box.jpg', 'image/jpeg', false, 1000, null, $now, $now));

        $this->messageBus->dispatch(new DeletePlayer(PlayerFixture::PLAYER_REGULAR));

        self::assertSame(
            [str_repeat('b', 32)],
            $this->entityManager->getConnection()->fetchFirstColumn('SELECT token FROM photo_stash_entry'),
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

    /**
     * An imported event result is the event's (docs/features/event-results-import/README.md "Gates"): the deleted
     * player's spot goes back to an anonymous slot, the result stays - though nobody else of it has an account, which
     * deletes an ordinary pair/team time the player tracked.
     */
    public function testTheSpotOfAnImportedEventResultGoesBackToTheEvent(): void
    {
        $round = $this->seedTrackerlessEvent(PuzzleFixture::PUZZLE_1000_04);
        $pals = $this->seedTrackerlessTime($round, PuzzleFixture::PUZZLE_1000_04, 'Puzzle Pals', 3600);
        $crew = $this->seedTrackerlessTime($round, PuzzleFixture::PUZZLE_1000_04, 'Corner Crew', 3000);
        $palsId = $pals->id->toString();
        $crewId = $crew->id->toString();

        // The deleted player tracks it, another claimer stays
        $this->messageBus->dispatch(new ClaimEventResultSlot($palsId, PlayerFixture::PLAYER_PRIVATE));
        $this->messageBus->dispatch(new ClaimEventResultSlot($palsId, PlayerFixture::PLAYER_WITH_FAVORITES));

        $this->messageBus->dispatch(new DeletePlayer(PlayerFixture::PLAYER_PRIVATE));
        $this->entityManager->clear();
        $connection = $this->entityManager->getConnection();

        self::assertSame(PlayerFixture::PLAYER_WITH_FAVORITES, $connection->fetchOne('SELECT player_id FROM puzzle_solving_time WHERE id = :id', ['id' => $palsId]));
        self::assertNotFalse($connection->fetchOne('SELECT 1 FROM puzzle_solving_time WHERE id = :id', ['id' => $crewId]));

        $team = $connection->fetchOne('SELECT team FROM puzzle_solving_time WHERE id = :id', ['id' => $palsId]);
        self::assertIsString($team);
        self::assertStringNotContainsString(PlayerFixture::PLAYER_PRIVATE, $team);
        self::assertSame(3, substr_count($team, '"anonymous_slot"'));
        self::assertFalse($connection->fetchOne('SELECT 1 FROM puzzling_team_member WHERE player_id = :id', ['id' => PlayerFixture::PLAYER_PRIVATE]));

        self::assertSame(
            [['action' => 'account_deleted', 'by_player_id' => null]],
            $connection->fetchAllAssociative("SELECT action, by_player_id FROM result_claim_log WHERE solving_time_id = :id AND action <> 'claimed'", ['id' => $palsId]),
        );

        // The claim log keeps ids, actions and times of the deleted player, never the name (Jan, 2026-11-16)
        self::assertSame(
            [['action' => 'account_deleted', 'player_name' => ''], ['action' => 'claimed', 'player_name' => '']],
            $connection->fetchAllAssociative('SELECT action, player_name FROM result_claim_log WHERE player_id = :id ORDER BY action', ['id' => PlayerFixture::PLAYER_PRIVATE]),
        );
        self::assertNotSame('', $connection->fetchOne("SELECT player_name FROM result_claim_log WHERE player_id = :id AND action = 'claimed'", ['id' => PlayerFixture::PLAYER_WITH_FAVORITES]));

        // The crew result was claimed by nobody else - it stays the event's, nobody's again
        $this->messageBus->dispatch(new ClaimEventResultSlot($crewId, PlayerFixture::PLAYER_WITH_STRIPE));
        $this->messageBus->dispatch(new DeletePlayer(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->entityManager->clear();

        self::assertSame(
            ['player_id' => null],
            $connection->fetchAssociative('SELECT player_id FROM puzzle_solving_time WHERE id = :id', ['id' => $crewId]),
        );
    }

    /**
     * A single person's imported result the deleted player claimed (solo-and-relays.md §1) has no team to find them in -
     * it is found by its tracker, released and stays the event's, nobody's again. Never deleted with the player.
     */
    public function testAClaimedSinglePersonsResultGoesBackToTheEvent(): void
    {
        $round = $this->seedTrackerlessEvent(PuzzleFixture::PUZZLE_1000_04);
        $janeId = $this->seedTrackerlessSoloTime($round, PuzzleFixture::PUZZLE_1000_04, 'Jane Doe', 3300)->id->toString();

        $this->messageBus->dispatch(new ClaimEventResultSlot($janeId, PlayerFixture::PLAYER_PRIVATE, firstTry: true));
        $this->messageBus->dispatch(new DeletePlayer(PlayerFixture::PLAYER_PRIVATE));
        $this->entityManager->clear();
        $connection = $this->entityManager->getConnection();

        self::assertSame(
            ['player_id' => null, 'first_attempt' => false, 'puzzling_type' => 'solo'],
            $connection->fetchAssociative('SELECT player_id, first_attempt, puzzling_type FROM puzzle_solving_time WHERE id = :id', ['id' => $janeId]),
        );
        self::assertSame(
            [['action' => 'account_deleted', 'slot_key' => 's:' . $janeId, 'player_name' => '']],
            $connection->fetchAllAssociative("SELECT action, slot_key, player_name FROM result_claim_log WHERE solving_time_id = :id AND action <> 'claimed'", ['id' => $janeId]),
        );
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

    /**
     * docs/features/competitions-management/participant-linking.md §11: the account's competition rows let go of it
     * with nothing of the link left, its own self-joined rows without official data go (else they would read as an
     * unclaimed name anybody could pick), and the link log keeps ids and actions but never the account's name.
     */
    public function testTheAccountsCompetitionRowsLetGoOfIt(): void
    {
        $connection = $this->entityManager->getConnection();
        $favorites = PlayerFixture::PLAYER_WITH_FAVORITES;

        // An organiser's row without official data (WJPC, linked by the organiser) ...
        $container = self::getContainer();
        $container->get(ParticipantLinks::class)->link(
            $container->get(CompetitionParticipantRepository::class)->get(CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED),
            $container->get(PlayerRepository::class)->get($favorites),
            ParticipantLinkMethod::Organiser,
            null,
            TestClock::now(),
        );
        $this->entityManager->flush();
        $connection->executeStatement(
            'UPDATE competition_participant SET online_link_confirmed_at = connected_at WHERE id = :id',
            ['id' => CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED],
        );
        // ... and a self-joined row holding an official result (the Results Cup's Ivan, finished in group B)
        $this->applySheetChanges([
            self::sheetGroup(['op' => 'player', 'participant' => OfficialResultsFixture::PARTICIPANT_IVAN, 'from' => null, 'to' => $favorites]),
        ]);
        $connection->executeStatement("UPDATE competition_participant SET source = 'self_joined' WHERE id = :id", ['id' => OfficialResultsFixture::PARTICIPANT_IVAN]);
        // PARTICIPANT_SELF_JOINED is their own self-joined row without results

        $this->messageBus->dispatch(new DeletePlayer($favorites));
        $this->entityManager->clear();

        /** @var array<string, array{player_id: null|string, connected_at: null|string, connected_via: null|string, online_link_confirmed_at: null|string, deleted_at: null|string}> $rows */
        $rows = $connection->fetchAllAssociativeIndexed(
            'SELECT id, player_id, connected_at, connected_via, online_link_confirmed_at, deleted_at FROM competition_participant WHERE id IN (:a, :b, :c)',
            ['a' => CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED, 'b' => OfficialResultsFixture::PARTICIPANT_IVAN, 'c' => CompetitionParticipantFixture::PARTICIPANT_SELF_JOINED],
        );

        foreach ($rows as $id => $row) {
            self::assertNull($row['player_id'], $id);
            self::assertNull($row['connected_at'], $id);
            self::assertNull($row['connected_via'], $id);
            self::assertNull($row['online_link_confirmed_at'], $id);
        }

        self::assertNull($rows[CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED]['deleted_at'], 'The organiser\'s row stays on the list');
        self::assertNull($rows[OfficialResultsFixture::PARTICIPANT_IVAN]['deleted_at'], 'A self-joined row with an official result stays - the organiser\'s record');
        self::assertSame(TestClock::FROZEN_NOW, $rows[CompetitionParticipantFixture::PARTICIPANT_SELF_JOINED]['deleted_at'], 'Their own self-joined row goes');
        self::assertSame('Michael Johnson', $connection->fetchOne('SELECT name FROM competition_participant WHERE id = :id', ['id' => CompetitionParticipantFixture::PARTICIPANT_SELF_JOINED]));

        // Nothing of theirs is left anywhere on a participant
        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM competition_participant WHERE player_id = :p', ['p' => $favorites]));

        // The log: the account's name erased everywhere, one account_deleted row per participant they were linked to
        self::assertSame(0, $connection->fetchOne("SELECT COUNT(*) FROM participant_link_log WHERE player_id = :p AND player_name <> ''", ['p' => $favorites]));
        self::assertGreaterThan(0, $connection->fetchOne("SELECT COUNT(*) FROM participant_link_log WHERE player_id = :p AND action = 'linked'", ['p' => $favorites]));

        foreach ([CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED, OfficialResultsFixture::PARTICIPANT_IVAN, CompetitionParticipantFixture::PARTICIPANT_SELF_JOINED] as $participantId) {
            /** @var list<array{id: string, player_name: string, method: null|string, by_player_id: null|string, at: string, participant_name: string}> $deleted */
            $deleted = $connection->fetchAllAssociative(
                "SELECT id, player_name, method, by_player_id, at, participant_name FROM participant_link_log WHERE participant_id = :id AND player_id = :p AND action = 'account_deleted'",
                ['id' => $participantId, 'p' => $favorites],
            );
            self::assertCount(1, $deleted, $participantId);
            self::assertSame('', $deleted[0]['player_name']);
            self::assertNull($deleted[0]['method']);
            self::assertNull($deleted[0]['by_player_id']);
            self::assertSame(TestClock::FROZEN_NOW, $deleted[0]['at']);
            self::assertNotSame('', $deleted[0]['participant_name']);
            // gen_uuid_v7() - a time-ordered id
            self::assertSame('7', $deleted[0]['id'][14]);
        }
    }

    private function insertComparisonRow(string $ownerId, string $teamId): string
    {
        $id = Uuid::uuid7()->toString();

        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO comparison_subject (id, player_id, subject_team_id, added_at) VALUES (:id, :owner, :team, ' . TestClock::SQL_NOW . ')',
            ['id' => $id, 'owner' => $ownerId, 'team' => $teamId],
        );

        return $id;
    }
}
