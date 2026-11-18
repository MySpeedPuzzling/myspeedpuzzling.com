<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\ParticipantLinking;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\ClaimParticipant;
use SpeedPuzzling\Web\Message\LeaveCompetition;
use SpeedPuzzling\Web\Query\GetCompetitionOrganisers;
use SpeedPuzzling\Web\Query\GetNotifications;
use SpeedPuzzling\Web\Repository\NotificationRepository;
use SpeedPuzzling\Web\Results\ParticipantImportPlan;
use SpeedPuzzling\Web\Results\PlayerNotification;
use SpeedPuzzling\Web\Services\ParticipantImport\ParticipantImportApplier;
use SpeedPuzzling\Web\Services\ParticipantImport\ParticipantImportPlanner;
use SpeedPuzzling\Web\Services\ParticipantImport\Plan\ParticipantImportOperations;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionParticipantFixture as Wjpc;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\MessageHandler\ParticipantsSheet\SheetChangeSets;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\ClaimOutcome;
use SpeedPuzzling\Web\Value\ParticipantImportMode;
use SpeedPuzzling\Web\Value\ParticipantImportRowData;
use SpeedPuzzling\Web\Value\ParticipantImportRows;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * The in-app notifications of participant linking and "Not me" (docs/features/competitions-management/
 * participant-linking.md §9.2, §6.6, D8, D11, D16, R10, R12).
 *
 * WJPC 2024 gets a team here: created by PLAYER_PRIVATE, maintained by PLAYER_WITH_STRIPE, under the Riverbend
 * organization (created by PLAYER_WITH_STRIPE, maintained by PLAYER_WITH_FAVORITES). PLAYER_ADMIN, an admin, is on none
 * of it. Fixture rows: PARTICIPANT_CONNECTED = PLAYER_REGULAR by import, PARTICIPANT_SELF_JOINED = PLAYER_WITH_FAVORITES'
 * own row, PARTICIPANT_UNCONNECTED nobody's.
 */
final class ParticipantLinkNotificationsTest extends KernelTestCase
{
    use SheetChangeSets;

    private const string EVENT = CompetitionFixture::COMPETITION_WJPC_2024;
    private const string ORGANISER = PlayerFixture::PLAYER_WITH_STRIPE;

    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);

        $this->database->executeStatement('UPDATE competition SET added_by_player_id = :creator, organization_id = :organization WHERE id = :id', [
            'creator' => PlayerFixture::PLAYER_PRIVATE,
            'organization' => OrganizationFixture::ORGANIZATION_RIVERBEND,
            'id' => self::EVENT,
        ]);
        $this->database->executeStatement('INSERT INTO competition_maintainer (competition_id, player_id) VALUES (:id, :player)', ['id' => self::EVENT, 'player' => self::ORGANISER]);
    }

    public function testTheOrganisersAreTheEditTeamWithoutAdmins(): void
    {
        $organisers = self::getContainer()->get(GetCompetitionOrganisers::class)->playerIds(self::EVENT);
        sort($organisers);

        self::assertSame([PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_WITH_STRIPE], $organisers);
    }

    // ---------------------------------------------------------------- organisers: one while unread per event (D16)

    public function testARequestTellsEveryOrganiserOnceWhileUnread(): void
    {
        $this->policy('approval');

        self::assertSame(ClaimOutcome::Requested, $this->claim(PlayerFixture::PLAYER_ADMIN, Wjpc::PARTICIPANT_UNCONNECTED));

        self::assertSame([
            ['ParticipantLinkRequested', PlayerFixture::PLAYER_PRIVATE],
            ['ParticipantLinkRequested', PlayerFixture::PLAYER_WITH_FAVORITES],
            ['ParticipantLinkRequested', PlayerFixture::PLAYER_WITH_STRIPE],
        ], $this->notifications());

        // A second request while unread: nothing new
        $another = $this->newParticipant('Second Person');
        self::assertSame(ClaimOutcome::Requested, $this->claim(PlayerFixture::PLAYER_REGULAR, $another));
        self::assertCount(3, $this->notifications());

        // One organiser read theirs - only they hear of the next one
        self::getContainer()->get(NotificationRepository::class)->markNotificationAsReadForPlayer(self::ORGANISER);
        $third = $this->newParticipant('Third Person');
        self::assertSame(ClaimOutcome::Requested, $this->claim(PlayerFixture::PLAYER_WITH_FAVORITES, $third));

        $perPlayer = array_count_values(array_column($this->notifications(), 1));
        self::assertSame([PlayerFixture::PLAYER_PRIVATE => 1, PlayerFixture::PLAYER_WITH_FAVORITES => 1, self::ORGANISER => 2], $perPlayer);
    }

    public function testADisputeTellsTheOrganisersUnderTheOpenPolicyToo(): void
    {
        self::assertSame(ClaimOutcome::DisputeSent, $this->claim(PlayerFixture::PLAYER_ADMIN, Wjpc::PARTICIPANT_CONNECTED));

        self::assertCount(3, $this->notifications());
        self::assertSame(['ParticipantLinkRequested'], array_values(array_unique(array_column($this->notifications(), 0))));
    }

    public function testAnOrganiserClaimingIsNotToldOfTheirOwnRequest(): void
    {
        $this->policy('approval');

        self::assertSame(ClaimOutcome::Requested, $this->claim(self::ORGANISER, Wjpc::PARTICIPANT_UNCONNECTED));

        self::assertNotContains(['ParticipantLinkRequested', self::ORGANISER], $this->notifications());
        self::assertCount(2, $this->notifications());
    }

    public function testAnOpenClaimTellsNobody(): void
    {
        self::assertSame(ClaimOutcome::Linked, $this->claim(PlayerFixture::PLAYER_ADMIN, Wjpc::PARTICIPANT_UNCONNECTED));

        self::assertSame([], $this->notifications());
    }

    // ---------------------------------------------------------------- the claimant

    public function testApprovalTellsTheClaimantOnly(): void
    {
        $this->policy('approval');
        $this->claim(PlayerFixture::PLAYER_ADMIN, Wjpc::PARTICIPANT_UNCONNECTED);
        $this->readAll();

        $this->applySheetChanges([self::sheetGroup(self::playerChange(Wjpc::PARTICIPANT_UNCONNECTED, null, PlayerFixture::PLAYER_ADMIN))], competitionId: self::EVENT, actingPlayerId: self::ORGANISER);

        // No "you are on the list" besides it
        self::assertSame([['ParticipantLinkApproved', PlayerFixture::PLAYER_ADMIN]], $this->unreadNotifications());
    }

    public function testDeclineTellsTheClaimant(): void
    {
        $this->policy('approval');
        $this->claim(PlayerFixture::PLAYER_ADMIN, Wjpc::PARTICIPANT_UNCONNECTED);
        $this->readAll();
        $requestId = $this->database->fetchOne('SELECT id FROM participant_link_request WHERE competition_id = :id', ['id' => self::EVENT]);
        self::assertIsString($requestId);

        $this->applySheetChanges([self::sheetGroup(['op' => 'declineLinkRequest', 'request' => $requestId, 'from' => 'pending'])], competitionId: self::EVENT, actingPlayerId: self::ORGANISER);

        self::assertSame([['ParticipantLinkDeclined', PlayerFixture::PLAYER_ADMIN]], $this->unreadNotifications());

        // The same decline again changes nothing - nobody is told twice
        $this->applySheetChanges([self::sheetGroup(['op' => 'declineLinkRequest', 'request' => $requestId, 'from' => 'pending'])], competitionId: self::EVENT, actingPlayerId: self::ORGANISER);
        self::assertCount(1, $this->unreadNotifications());
    }

    /**
     * R10: a request superseded by the organiser's link of somebody else reads as the neutral "not confirmed"; the linked
     * player hears they are on the list
     */
    public function testASupersededRequestReadsAsDeclined(): void
    {
        $this->policy('approval');
        $this->claim(PlayerFixture::PLAYER_ADMIN, Wjpc::PARTICIPANT_UNCONNECTED);
        $this->readAll();

        // Another organiser of the event links somebody else to the name
        $applied = $this->applySheetChanges([self::sheetGroup(self::playerChange(Wjpc::PARTICIPANT_UNCONNECTED, null, self::ORGANISER))], competitionId: self::EVENT, actingPlayerId: PlayerFixture::PLAYER_PRIVATE);
        self::assertSame(['applied'], self::groupStatuses($applied));

        self::assertSame([
            ['ParticipantLinkDeclined', PlayerFixture::PLAYER_ADMIN],
            ['ParticipantListedYou', self::ORGANISER],
        ], $this->sorted($this->unreadNotifications()));
    }

    public function testTakingARequestBackOrMakingANewOneTellsTheClaimantNothing(): void
    {
        $this->policy('approval');
        $this->claim(PlayerFixture::PLAYER_ADMIN, Wjpc::PARTICIPANT_UNCONNECTED);
        $this->readAll();

        // The player's new request supersedes their older one silently
        $this->claim(PlayerFixture::PLAYER_ADMIN, $this->newParticipant('Another Name'));

        self::assertSame([], array_filter($this->unreadNotifications(), static fn (array $row): bool => $row[1] === PlayerFixture::PLAYER_ADMIN));
    }

    public function testARemovedParticipantsRequestsGoSilently(): void
    {
        $this->policy('approval');
        $this->claim(PlayerFixture::PLAYER_ADMIN, Wjpc::PARTICIPANT_UNCONNECTED);
        $this->readAll();

        $this->applySheetChanges([self::sheetGroup(['op' => 'remove', 'participant' => Wjpc::PARTICIPANT_UNCONNECTED])], competitionId: self::EVENT, actingPlayerId: self::ORGANISER);

        self::assertSame([], $this->unreadNotifications());
    }

    // ---------------------------------------------------------------- "you are on the participant list" (D8, R12)

    public function testTheOrganisersLinkTellsThePlayer(): void
    {
        $this->applySheetChanges([self::sheetGroup(self::playerChange(Wjpc::PARTICIPANT_UNCONNECTED, null, PlayerFixture::PLAYER_ADMIN))], competitionId: self::EVENT, actingPlayerId: self::ORGANISER);

        self::assertSame([['ParticipantListedYou', PlayerFixture::PLAYER_ADMIN]], $this->notifications());
        self::assertSame(Wjpc::PARTICIPANT_UNCONNECTED, $this->database->fetchOne("SELECT target_competition_participant_id FROM notification WHERE type = 'ParticipantListedYou'"));
    }

    public function testAnOrganiserLinkingThemselvesIsNotTold(): void
    {
        $this->applySheetChanges([self::sheetGroup(self::playerChange(Wjpc::PARTICIPANT_UNCONNECTED, null, self::ORGANISER))], competitionId: self::EVENT, actingPlayerId: self::ORGANISER);

        self::assertSame([], $this->notifications());
    }

    public function testADraftsLinksAreNotAnnounced(): void
    {
        $this->database->executeStatement('UPDATE competition SET is_draft = true WHERE id = :id', ['id' => self::EVENT]);

        $this->applySheetChanges([self::sheetGroup(self::playerChange(Wjpc::PARTICIPANT_UNCONNECTED, null, PlayerFixture::PLAYER_ADMIN))], competitionId: self::EVENT, actingPlayerId: self::ORGANISER);

        self::assertSame(PlayerFixture::PLAYER_ADMIN, $this->linkedPlayer(Wjpc::PARTICIPANT_UNCONNECTED));
        self::assertSame([], $this->notifications());
    }

    public function testTheImportTellsThePlayerOnceAndNeverForTheirOwnRow(): void
    {
        $this->import([
            self::row(2, 'Jane Unconnected', '#admin', Wjpc::PARTICIPANT_UNCONNECTED),
            // R12: the file names the player of their own self-joined row - nothing changes, nobody is told
            self::row(3, 'Michael Johnson', PlayerFixture::PLAYER_WITH_FAVORITES, Wjpc::PARTICIPANT_SELF_JOINED),
        ]);

        self::assertSame([['ParticipantListedYou', PlayerFixture::PLAYER_ADMIN]], $this->notifications());

        // R12: a re-import of the same file changes nothing
        $this->import([self::row(2, 'Jane Unconnected', '#admin', Wjpc::PARTICIPANT_UNCONNECTED)]);
        self::assertCount(1, $this->notifications());
    }

    // ---------------------------------------------------------------- "Not me" (§6.6, D11)

    public function testNotMeUnlinksAsARefusedPair(): void
    {
        $this->import([self::row(2, 'Jane Unconnected', '#admin', Wjpc::PARTICIPANT_UNCONNECTED)]);

        self::assertTrue($this->notMe(PlayerFixture::PLAYER_ADMIN, Wjpc::PARTICIPANT_UNCONNECTED));

        self::assertNull($this->linkedPlayer(Wjpc::PARTICIPANT_UNCONNECTED));
        self::assertSame('not_me', $this->database->fetchOne(
            'SELECT action FROM participant_link_log WHERE participant_id = :id ORDER BY at DESC, id DESC LIMIT 1',
            ['id' => Wjpc::PARTICIPANT_UNCONNECTED],
        ));

        // The player cannot claim it back by themselves, and the import does not link it again
        self::assertSame(ClaimOutcome::AskOrganiser, $this->claim(PlayerFixture::PLAYER_ADMIN, Wjpc::PARTICIPANT_UNCONNECTED));
        $plan = $this->plan([self::row(2, 'Jane Unconnected', '#admin', Wjpc::PARTICIPANT_UNCONNECTED)]);
        $operations = $plan->operations;
        self::assertInstanceOf(ParticipantImportOperations::class, $operations);
        self::assertNull($operations->participants[0]['connectPlayerId'] ?? null);

        // The notification no longer offers "Not me"
        $listed = array_values(array_filter(
            self::getContainer()->get(GetNotifications::class)->forPlayer(PlayerFixture::PLAYER_ADMIN, 30),
            static fn (PlayerNotification $notification): bool => $notification->isParticipantLinkNotification(),
        ));
        self::assertCount(1, $listed);
        self::assertFalse($listed[0]->participantLinkNotMe);
    }

    public function testNotMeKeepsThePlayersOtherRows(): void
    {
        $this->applySheetChanges([self::sheetGroup(self::playerChange(Wjpc::PARTICIPANT_UNCONNECTED, null, PlayerFixture::PLAYER_ADMIN))], competitionId: self::EVENT, actingPlayerId: self::ORGANISER);
        $second = $this->newParticipant('Admin Elsewhere');
        $this->database->executeStatement("UPDATE competition_participant SET player_id = :player, connected_via = 'organiser' WHERE id = :id", ['player' => PlayerFixture::PLAYER_ADMIN, 'id' => $second]);

        self::assertTrue($this->notMe(PlayerFixture::PLAYER_ADMIN, Wjpc::PARTICIPANT_UNCONNECTED));

        self::assertSame(PlayerFixture::PLAYER_ADMIN, $this->linkedPlayer($second));
    }

    public function testNotMeOfSomebodyElsesParticipantIsRefused(): void
    {
        self::assertFalse($this->notMe(PlayerFixture::PLAYER_ADMIN, Wjpc::PARTICIPANT_CONNECTED));

        self::assertSame(PlayerFixture::PLAYER_REGULAR, $this->linkedPlayer(Wjpc::PARTICIPANT_CONNECTED));
        self::assertEquals(0, $this->database->fetchOne("SELECT COUNT(*) FROM participant_link_log WHERE action = 'not_me'"));
    }

    public function testNotMeOfARowThePlayerLinkedThemselvesIsRefused(): void
    {
        // Their own self-joined row - "Leave" is for that
        self::assertFalse($this->notMe(PlayerFixture::PLAYER_WITH_FAVORITES, Wjpc::PARTICIPANT_SELF_JOINED));
        self::assertSame(PlayerFixture::PLAYER_WITH_FAVORITES, $this->linkedPlayer(Wjpc::PARTICIPANT_SELF_JOINED));

        // A name they picked themselves
        self::assertSame(ClaimOutcome::Linked, $this->claim(PlayerFixture::PLAYER_ADMIN, Wjpc::PARTICIPANT_UNCONNECTED));
        self::assertFalse($this->notMe(PlayerFixture::PLAYER_ADMIN, Wjpc::PARTICIPANT_UNCONNECTED));
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $this->linkedPlayer(Wjpc::PARTICIPANT_UNCONNECTED));
    }

    public function testNotMeOfAnotherEventsParticipantIsRefused(): void
    {
        $envelope = $this->messageBus->dispatch(new LeaveCompetition(CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024, PlayerFixture::PLAYER_REGULAR, Wjpc::PARTICIPANT_CONNECTED));

        self::assertFalse($envelope->last(HandledStamp::class)?->getResult());
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $this->linkedPlayer(Wjpc::PARTICIPANT_CONNECTED));
    }

    // ---------------------------------------------------------------- the notifications list

    public function testTheListCarriesTheEventAndTheParticipant(): void
    {
        $this->policy('approval');
        $this->claim(PlayerFixture::PLAYER_ADMIN, Wjpc::PARTICIPANT_UNCONNECTED);
        $this->applySheetChanges([self::sheetGroup(self::playerChange(Wjpc::PARTICIPANT_CONNECTED, PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_STRIPE))], competitionId: self::EVENT, actingPlayerId: PlayerFixture::PLAYER_PRIVATE);

        $getNotifications = self::getContainer()->get(GetNotifications::class);

        $organisers = array_values(array_filter($getNotifications->forPlayer(PlayerFixture::PLAYER_PRIVATE, 30), static fn (PlayerNotification $notification): bool => $notification->isParticipantLinkNotification()));
        self::assertCount(1, $organisers);
        self::assertTrue($organisers[0]->isParticipantLinkNotification());
        self::assertSame(self::EVENT, $organisers[0]->participantLinkCompetitionId);
        self::assertSame('WJPC 2024', $organisers[0]->participantLinkCompetitionName);
        self::assertSame('wjpc-2024', $organisers[0]->participantLinkCompetitionSlug);
        self::assertFalse($organisers[0]->participantLinkNotMe);

        $listed = array_values(array_filter($getNotifications->forPlayer(self::ORGANISER, 30), static fn (PlayerNotification $notification): bool => $notification->isParticipantLinkNotification() && $notification->participantLinkParticipantId === Wjpc::PARTICIPANT_CONNECTED));
        self::assertCount(1, $listed);
        self::assertSame('John Regular', $listed[0]->participantLinkParticipantName);
        self::assertTrue($listed[0]->participantLinkNotMe);
    }

    // ---------------------------------------------------------------- helpers

    private function claim(string $playerId, string $participantId): ClaimOutcome
    {
        $outcome = $this->messageBus->dispatch(new ClaimParticipant(self::EVENT, $playerId, $participantId))->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(ClaimOutcome::class, $outcome);

        return $outcome;
    }

    private function notMe(string $playerId, string $participantId): bool
    {
        $result = $this->messageBus->dispatch(new LeaveCompetition(self::EVENT, $playerId, $participantId))->last(HandledStamp::class)?->getResult();
        self::assertIsBool($result);

        return $result;
    }

    private function policy(string $policy): void
    {
        $this->database->executeStatement('UPDATE competition SET participant_claims = :policy WHERE id = :id', ['policy' => $policy, 'id' => self::EVENT]);
    }

    private function readAll(): void
    {
        $this->database->executeStatement('UPDATE notification SET read_at = ' . TestClock::SQL_NOW . ' WHERE read_at IS NULL');
    }

    /**
     * @param list<ParticipantImportRowData> $rows
     */
    private function import(array $rows): void
    {
        self::getContainer()->get(ParticipantImportApplier::class)->apply(self::EVENT, $this->plan($rows));
        self::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    /**
     * @param list<ParticipantImportRowData> $rows
     */
    private function plan(array $rows): ParticipantImportPlan
    {
        return self::getContainer()->get(ParticipantImportPlanner::class)->plan(self::EVENT, new ParticipantImportRows($rows), ParticipantImportMode::Update);
    }

    private static function row(int $number, string $name, string $player, string $participantId): ParticipantImportRowData
    {
        return new ParticipantImportRowData(rowNumber: $number, name: $name, playerId: $player, participantId: $participantId);
    }

    private function newParticipant(string $name): string
    {
        $id = Uuid::uuid7()->toString();
        $this->database->executeStatement(
            "INSERT INTO competition_participant (id, name, country, competition_id, source) VALUES (:id, :name, 'cz', :competition, 'imported')",
            ['id' => $id, 'name' => $name, 'competition' => self::EVENT],
        );

        return $id;
    }

    private function linkedPlayer(string $participantId): null|string
    {
        $playerId = $this->database->fetchOne('SELECT player_id FROM competition_participant WHERE id = :id', ['id' => $participantId]);

        return is_string($playerId) ? $playerId : null;
    }

    /**
     * @return list<array{string, string}> [type, player id] of the participant linking notifications, in order
     */
    private function notifications(bool $unreadOnly = false): array
    {
        /** @var list<array{type: string, player_id: string}> $rows */
        $rows = $this->database->fetchAllAssociative(
            "SELECT type, player_id FROM notification WHERE type LIKE 'Participant%'" . ($unreadOnly ? ' AND read_at IS NULL' : '') . ' ORDER BY notified_at, player_id, id',
        );

        return array_map(static fn (array $row): array => [$row['type'], $row['player_id']], $rows);
    }

    /**
     * @phpstan-impure
     * @return list<array{string, string}>
     */
    private function unreadNotifications(): array
    {
        return $this->notifications(true);
    }

    /**
     * @param list<array{string, string}> $rows
     * @return list<array{string, string}>
     */
    private function sorted(array $rows): array
    {
        sort($rows);

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private static function playerChange(string $participantId, null|string $from, null|string $to): array
    {
        return ['op' => 'player', 'participant' => $participantId, 'from' => $from, 'to' => $to];
    }
}
