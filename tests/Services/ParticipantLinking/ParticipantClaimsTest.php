<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\ParticipantLinking;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Message\ClaimParticipant;
use SpeedPuzzling\Web\Message\JoinCompetition;
use SpeedPuzzling\Web\Message\WithdrawParticipantLinkRequest;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionParticipantFixture as Wjpc;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\MessageHandler\ParticipantsSheet\SheetChangeSets;
use SpeedPuzzling\Web\Value\ClaimOutcome;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Claims, requests and the organiser's decisions (docs/features/competitions-management/participant-linking.md §5.1,
 * §5.3, D11, D13, R1). WJPC 2024: PARTICIPANT_CONNECTED is the organiser's row linked to PLAYER_REGULAR (import),
 * PARTICIPANT_SELF_JOINED is PLAYER_WITH_FAVORITES' own row, PARTICIPANT_UNCONNECTED nobody's yet.
 */
final class ParticipantClaimsTest extends KernelTestCase
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
    }

    // ---------------------------------------------------------------- §5.1, row by row

    public function testOpenPolicyLinksAtOnce(): void
    {
        self::assertSame(ClaimOutcome::Linked, $this->claim(PlayerFixture::PLAYER_ADMIN, Wjpc::PARTICIPANT_UNCONNECTED));

        self::assertSame(PlayerFixture::PLAYER_ADMIN, $this->linkedPlayer(Wjpc::PARTICIPANT_UNCONNECTED));
        self::assertSame('claim', $this->connectedVia(Wjpc::PARTICIPANT_UNCONNECTED));
        self::assertSame([], $this->requests());
    }

    public function testApprovalPolicyAsksTheOrganiser(): void
    {
        $this->policy('approval');

        self::assertSame(ClaimOutcome::Requested, $this->join(PlayerFixture::PLAYER_ADMIN, Wjpc::PARTICIPANT_UNCONNECTED));

        // A pending request is no link (D4)
        self::assertNull($this->linkedPlayer(Wjpc::PARTICIPANT_UNCONNECTED));
        self::assertSame([
            ['participant_id' => Wjpc::PARTICIPANT_UNCONNECTED, 'player_id' => PlayerFixture::PLAYER_ADMIN, 'status' => 'pending', 'kind' => 'claim', 'player_name' => 'Admin User'],
        ], array_map(static function (array $request): array {
            unset($request['id']);

            return $request;
        }, $this->requests()));
        self::assertContains('requested', $this->logActions(Wjpc::PARTICIPANT_UNCONNECTED));

        // Asking again changes nothing
        self::assertSame(ClaimOutcome::Requested, $this->join(PlayerFixture::PLAYER_ADMIN, Wjpc::PARTICIPANT_UNCONNECTED));
        self::assertCount(1, $this->requests());
    }

    public function testOnePendingRequestPerPlayerAndEvent(): void
    {
        $this->policy('approval');
        $other = $this->newParticipant('Second Name');

        $this->claim(PlayerFixture::PLAYER_ADMIN, Wjpc::PARTICIPANT_UNCONNECTED);
        self::assertSame(ClaimOutcome::Requested, $this->claim(PlayerFixture::PLAYER_ADMIN, $other));

        self::assertSame(
            [[Wjpc::PARTICIPANT_UNCONNECTED, 'superseded'], [$other, 'pending']],
            array_map(static fn (array $request): array => [$request['participant_id'], $request['status']], $this->requests()),
        );
    }

    public function testYourOwnNameIsAlreadyYours(): void
    {
        $this->policy('approval');

        self::assertSame(ClaimOutcome::AlreadyYours, $this->claim(PlayerFixture::PLAYER_REGULAR, Wjpc::PARTICIPANT_CONNECTED));
        self::assertSame([], $this->requests());
    }

    public function testANameLinkedToSomebodyElseIsADispute(): void
    {
        // Under every policy - the open one included
        self::assertSame(ClaimOutcome::DisputeSent, $this->claim(PlayerFixture::PLAYER_ADMIN, Wjpc::PARTICIPANT_CONNECTED));

        self::assertSame(PlayerFixture::PLAYER_REGULAR, $this->linkedPlayer(Wjpc::PARTICIPANT_CONNECTED));
        self::assertSame('dispute', $this->requests()[0]['kind']);
    }

    public function testARemovedParticipantIsNotAvailable(): void
    {
        self::assertSame(ClaimOutcome::NotAvailable, $this->claim(PlayerFixture::PLAYER_ADMIN, Wjpc::PARTICIPANT_DELETED));
        self::assertSame([], $this->requests());
    }

    public function testADraftTakesNoClaims(): void
    {
        $participant = new CompetitionParticipant(
            id: Uuid::uuid7(),
            name: 'Prepared Puzzler',
            country: 'cz',
            competition: self::getContainer()->get(CompetitionRepository::class)->get(OrganizationFixture::COMPETITION_DRAFT_NIGHT),
        );
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($participant);
        $entityManager->flush();

        $this->expectException(CompetitionNotFound::class);

        $this->messageBus->dispatch(new ClaimParticipant(OrganizationFixture::COMPETITION_DRAFT_NIGHT, PlayerFixture::PLAYER_ADMIN, $participant->id->toString()));
    }

    public function testARefusedPairAsksTheOrganiser(): void
    {
        $this->refusal(Wjpc::PARTICIPANT_UNCONNECTED, PlayerFixture::PLAYER_ADMIN, 'declined');

        self::assertSame(ClaimOutcome::AskOrganiser, $this->claim(PlayerFixture::PLAYER_ADMIN, Wjpc::PARTICIPANT_UNCONNECTED));
        self::assertNull($this->linkedPlayer(Wjpc::PARTICIPANT_UNCONNECTED));

        // Somebody else may still claim it
        self::assertSame(ClaimOutcome::Linked, $this->claim(PlayerFixture::PLAYER_WITH_STRIPE, Wjpc::PARTICIPANT_UNCONNECTED));
    }

    public function testThreeRefusalsAtTheEventEndTheClaims(): void
    {
        $this->refusal($this->newParticipant('A'), PlayerFixture::PLAYER_ADMIN, 'declined');
        $this->refusal($this->newParticipant('B'), PlayerFixture::PLAYER_ADMIN, 'not_this_person');

        // Two refusals: still claims
        $third = $this->newParticipant('C');
        self::assertSame(ClaimOutcome::Linked, $this->claim(PlayerFixture::PLAYER_ADMIN, $third));

        $this->refusal($this->newParticipant('D'), PlayerFixture::PLAYER_ADMIN, 'declined');

        self::assertSame(ClaimOutcome::AskOrganiser, $this->claim(PlayerFixture::PLAYER_ADMIN, Wjpc::PARTICIPANT_UNCONNECTED));
    }

    public function testAPaidOwnRegistrationNeedsTheExplicitYes(): void
    {
        $this->managedWithPaidOwnRow();

        self::assertSame(ClaimOutcome::ReleaseNotConfirmed, $this->join(PlayerFixture::PLAYER_WITH_FAVORITES, Wjpc::PARTICIPANT_UNCONNECTED));
        self::assertNull($this->linkedPlayer(Wjpc::PARTICIPANT_UNCONNECTED));

        self::assertSame(ClaimOutcome::Linked, $this->join(PlayerFixture::PLAYER_WITH_FAVORITES, Wjpc::PARTICIPANT_UNCONNECTED, releasesOwnRegistration: true));
        self::assertSame(PlayerFixture::PLAYER_WITH_FAVORITES, $this->linkedPlayer(Wjpc::PARTICIPANT_UNCONNECTED));
        self::assertNotNull($this->row(Wjpc::PARTICIPANT_SELF_JOINED)['deleted_at']);
    }

    public function testTakingARequestBack(): void
    {
        $this->policy('approval');
        $this->claim(PlayerFixture::PLAYER_ADMIN, Wjpc::PARTICIPANT_UNCONNECTED);

        $this->messageBus->dispatch(new WithdrawParticipantLinkRequest(self::EVENT, PlayerFixture::PLAYER_ADMIN));

        self::assertSame('withdrawn', $this->requests()[0]['status']);
        self::assertContains('withdrawn', $this->logActions(Wjpc::PARTICIPANT_UNCONNECTED));
    }

    // ---------------------------------------------------------------- races (D13)

    public function testTwoClaimsOfOneNameTheSecondIsADispute(): void
    {
        self::assertSame(ClaimOutcome::Linked, $this->claim(PlayerFixture::PLAYER_ADMIN, Wjpc::PARTICIPANT_UNCONNECTED));
        self::assertSame(ClaimOutcome::DisputeSent, $this->claim(PlayerFixture::PLAYER_WITH_STRIPE, Wjpc::PARTICIPANT_UNCONNECTED));

        self::assertSame(PlayerFixture::PLAYER_ADMIN, $this->linkedPlayer(Wjpc::PARTICIPANT_UNCONNECTED));
    }

    public function testTheOrganisersLinkOfSomebodyElseSupersedesTheRequest(): void
    {
        $this->policy('approval');
        $this->claim(PlayerFixture::PLAYER_ADMIN, Wjpc::PARTICIPANT_UNCONNECTED);

        $applied = $this->applySheetChanges([self::sheetGroup(self::playerChange(Wjpc::PARTICIPANT_UNCONNECTED, null, PlayerFixture::PLAYER_WITH_STRIPE))], competitionId: self::EVENT, actingPlayerId: self::ORGANISER);

        self::assertSame(['applied'], self::groupStatuses($applied));
        self::assertSame('organiser', $this->connectedVia(Wjpc::PARTICIPANT_UNCONNECTED));
        self::assertSame('superseded', $this->requests()[0]['status']);
    }

    public function testARemovedParticipantsRequestsAreSuperseded(): void
    {
        $this->policy('approval');
        $this->claim(PlayerFixture::PLAYER_ADMIN, Wjpc::PARTICIPANT_UNCONNECTED);

        $this->applySheetChanges([self::sheetGroup(['op' => 'remove', 'participant' => Wjpc::PARTICIPANT_UNCONNECTED])], competitionId: self::EVENT, actingPlayerId: self::ORGANISER);

        self::assertSame('superseded', $this->requests()[0]['status']);
    }

    // ---------------------------------------------------------------- the organiser's decisions (§5.3, R1)

    public function testApprovingIsTheLinkToTheClaimant(): void
    {
        $this->policy('approval');
        $this->claim(PlayerFixture::PLAYER_ADMIN, Wjpc::PARTICIPANT_UNCONNECTED);

        $applied = $this->applySheetChanges([self::sheetGroup(self::playerChange(Wjpc::PARTICIPANT_UNCONNECTED, null, PlayerFixture::PLAYER_ADMIN))], competitionId: self::EVENT, actingPlayerId: self::ORGANISER);

        self::assertSame(['applied'], self::groupStatuses($applied));
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $this->linkedPlayer(Wjpc::PARTICIPANT_UNCONNECTED));
        self::assertSame('claim_approved', $this->connectedVia(Wjpc::PARTICIPANT_UNCONNECTED));
        self::assertSame('approved', $this->requests()[0]['status']);

        // Undo of an approval = the unlink
        $applied = $this->applySheetChanges([self::sheetGroup(self::playerChange(Wjpc::PARTICIPANT_UNCONNECTED, PlayerFixture::PLAYER_ADMIN, null))], competitionId: self::EVENT, actingPlayerId: self::ORGANISER);
        self::assertSame(['applied'], self::groupStatuses($applied));
        self::assertNull($this->linkedPlayer(Wjpc::PARTICIPANT_UNCONNECTED));
    }

    /**
     * R1: the claimant tapped "I'm going" before - their own self-joined row goes, the approval is never refused with
     * player_linked_elsewhere
     */
    public function testApprovingAClaimantWithTheirOwnRowReleasesItFirst(): void
    {
        $this->policy('approval');
        self::assertSame(ClaimOutcome::Requested, $this->claim(PlayerFixture::PLAYER_WITH_FAVORITES, Wjpc::PARTICIPANT_UNCONNECTED));
        // Still going as their own row meanwhile
        self::assertSame(PlayerFixture::PLAYER_WITH_FAVORITES, $this->linkedPlayer(Wjpc::PARTICIPANT_SELF_JOINED));

        $applied = $this->applySheetChanges([self::sheetGroup(self::playerChange(Wjpc::PARTICIPANT_UNCONNECTED, null, PlayerFixture::PLAYER_WITH_FAVORITES))], competitionId: self::EVENT, actingPlayerId: self::ORGANISER);

        self::assertSame(['applied'], self::groupStatuses($applied));
        self::assertSame(PlayerFixture::PLAYER_WITH_FAVORITES, $this->linkedPlayer(Wjpc::PARTICIPANT_UNCONNECTED));
        // Their own row is their "I left" record: soft-deleted, still theirs, still self-joined (I'm going revives it)
        $own = $this->row(Wjpc::PARTICIPANT_SELF_JOINED);
        self::assertNotNull($own['deleted_at']);
        self::assertSame('self_joined', $own['source']);
    }

    public function testApprovingAClaimantOnAnotherOrganisersRowUnlinksThatRow(): void
    {
        $this->policy('approval');
        self::assertSame(ClaimOutcome::Requested, $this->claim(PlayerFixture::PLAYER_REGULAR, Wjpc::PARTICIPANT_UNCONNECTED));

        $applied = $this->applySheetChanges([self::sheetGroup(self::playerChange(Wjpc::PARTICIPANT_UNCONNECTED, null, PlayerFixture::PLAYER_REGULAR))], competitionId: self::EVENT, actingPlayerId: self::ORGANISER);

        self::assertSame(['applied'], self::groupStatuses($applied));
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $this->linkedPlayer(Wjpc::PARTICIPANT_UNCONNECTED));
        $organisersRow = $this->row(Wjpc::PARTICIPANT_CONNECTED);
        self::assertNull($organisersRow['player_id']);
        self::assertNull($organisersRow['deleted_at']);
    }

    public function testWithoutARequestAPlayerLinkedElsewhereStaysRefused(): void
    {
        $applied = $this->applySheetChanges([self::sheetGroup(self::playerChange(Wjpc::PARTICIPANT_UNCONNECTED, null, PlayerFixture::PLAYER_WITH_FAVORITES))], competitionId: self::EVENT, actingPlayerId: self::ORGANISER);

        self::assertSame(['refused:player_linked_elsewhere'], self::changeStatuses($applied));
        self::assertNull($this->row(Wjpc::PARTICIPANT_SELF_JOINED)['deleted_at']);
    }

    public function testApprovalKeepsAPaidOwnRegistrationWithoutTheClaimantsYes(): void
    {
        $this->policy('approval');
        $this->database->executeStatement('UPDATE competition SET registration_managed = true WHERE id = :id', ['id' => self::EVENT]);
        self::assertSame(ClaimOutcome::Requested, $this->join(PlayerFixture::PLAYER_WITH_FAVORITES, Wjpc::PARTICIPANT_UNCONNECTED));
        // Paid after the claim
        $this->database->executeStatement("UPDATE competition_participant SET registration_status = 'paid' WHERE id = :id", ['id' => Wjpc::PARTICIPANT_SELF_JOINED]);

        $applied = $this->applySheetChanges([self::sheetGroup(self::playerChange(Wjpc::PARTICIPANT_UNCONNECTED, null, PlayerFixture::PLAYER_WITH_FAVORITES))], competitionId: self::EVENT, actingPlayerId: self::ORGANISER);

        self::assertSame(['refused:claimant_registration_paid'], self::changeStatuses($applied));
        self::assertNull($this->row(Wjpc::PARTICIPANT_SELF_JOINED)['deleted_at']);
        self::assertSame('pending', $this->requests()[0]['status']);
    }

    public function testApprovalReleasesAPaidOwnRegistrationOnTheClaimantsYes(): void
    {
        $this->policy('approval');
        $this->managedWithPaidOwnRow();
        self::assertSame(ClaimOutcome::Requested, $this->join(PlayerFixture::PLAYER_WITH_FAVORITES, Wjpc::PARTICIPANT_UNCONNECTED, releasesOwnRegistration: true));

        $applied = $this->applySheetChanges([self::sheetGroup(self::playerChange(Wjpc::PARTICIPANT_UNCONNECTED, null, PlayerFixture::PLAYER_WITH_FAVORITES))], competitionId: self::EVENT, actingPlayerId: self::ORGANISER);

        self::assertSame(['applied'], self::groupStatuses($applied));
        self::assertNotNull($this->row(Wjpc::PARTICIPANT_SELF_JOINED)['deleted_at']);
    }

    public function testApprovingADisputeTellsTheOldLinkItIsNotThisPerson(): void
    {
        self::assertSame(ClaimOutcome::DisputeSent, $this->claim(PlayerFixture::PLAYER_ADMIN, Wjpc::PARTICIPANT_CONNECTED));

        $applied = $this->applySheetChanges([self::sheetGroup(self::playerChange(Wjpc::PARTICIPANT_CONNECTED, PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_ADMIN))], competitionId: self::EVENT, actingPlayerId: self::ORGANISER);

        self::assertSame(['applied'], self::groupStatuses($applied));
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $this->linkedPlayer(Wjpc::PARTICIPANT_CONNECTED));
        self::assertSame('claim_approved', $this->connectedVia(Wjpc::PARTICIPANT_CONNECTED));
        self::assertContains(['action' => 'not_this_person', 'player_id' => PlayerFixture::PLAYER_REGULAR], $this->log(Wjpc::PARTICIPANT_CONNECTED));

        // The player the organiser said no to cannot claim it back
        self::assertSame(ClaimOutcome::AskOrganiser, $this->claim(PlayerFixture::PLAYER_REGULAR, Wjpc::PARTICIPANT_CONNECTED));
    }

    public function testDecliningIsARefusedPair(): void
    {
        $this->policy('approval');
        $this->claim(PlayerFixture::PLAYER_ADMIN, Wjpc::PARTICIPANT_UNCONNECTED);
        $requestId = $this->requests()[0]['id'];

        $applied = $this->applySheetChanges([self::sheetGroup(self::declineChange($requestId, 'pending'))], competitionId: self::EVENT, actingPlayerId: self::ORGANISER);

        self::assertSame(['applied'], self::groupStatuses($applied));
        self::assertSame('declined', $this->requests()[0]['status']);
        self::assertContains(['action' => 'declined', 'player_id' => PlayerFixture::PLAYER_ADMIN], $this->log(Wjpc::PARTICIPANT_UNCONNECTED));

        // Three-way: the same decline again is unchanged, a decline of what changed meanwhile is a conflict
        $again = $this->applySheetChanges([self::sheetGroup(self::declineChange($requestId, 'pending'))], competitionId: self::EVENT, actingPlayerId: self::ORGANISER);
        self::assertSame(['unchanged'], self::groupStatuses($again));

        self::assertSame(ClaimOutcome::AskOrganiser, $this->claim(PlayerFixture::PLAYER_ADMIN, Wjpc::PARTICIPANT_UNCONNECTED));
    }

    public function testADeclineOfAWithdrawnRequestIsAConflict(): void
    {
        $this->policy('approval');
        $this->claim(PlayerFixture::PLAYER_ADMIN, Wjpc::PARTICIPANT_UNCONNECTED);
        $requestId = $this->requests()[0]['id'];
        $this->messageBus->dispatch(new WithdrawParticipantLinkRequest(self::EVENT, PlayerFixture::PLAYER_ADMIN));

        $applied = $this->applySheetChanges([self::sheetGroup(self::declineChange($requestId, 'pending'))], competitionId: self::EVENT, actingPlayerId: self::ORGANISER);

        self::assertSame(['conflict:changed_meanwhile'], self::changeStatuses($applied));
        self::assertSame('withdrawn', $applied->groups[0]->changes[0]->current);
        self::assertSame('withdrawn', $this->requests()[0]['status']);
    }

    public function testARequestOfAnotherEventIsNotFound(): void
    {
        $this->policy('approval');
        $this->claim(PlayerFixture::PLAYER_ADMIN, Wjpc::PARTICIPANT_UNCONNECTED);

        $applied = $this->applySheetChanges([self::sheetGroup(self::declineChange($this->requests()[0]['id'], 'pending'))], competitionId: CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024, actingPlayerId: self::ORGANISER);

        self::assertSame(['refused:request_not_found'], self::changeStatuses($applied));
        self::assertSame('pending', $this->requests()[0]['status']);
    }

    public function testNotThisPersonIsARefusedPair(): void
    {
        $applied = $this->applySheetChanges([self::sheetGroup(self::playerChange(Wjpc::PARTICIPANT_CONNECTED, PlayerFixture::PLAYER_REGULAR, null, notThem: true))], competitionId: self::EVENT, actingPlayerId: self::ORGANISER);

        self::assertSame(['applied'], self::groupStatuses($applied));
        self::assertNull($this->linkedPlayer(Wjpc::PARTICIPANT_CONNECTED));
        self::assertContains(['action' => 'not_this_person', 'player_id' => PlayerFixture::PLAYER_REGULAR], $this->log(Wjpc::PARTICIPANT_CONNECTED));
        self::assertSame(ClaimOutcome::AskOrganiser, $this->claim(PlayerFixture::PLAYER_REGULAR, Wjpc::PARTICIPANT_CONNECTED));

        // The organiser's explicit link ignores the refusal
        $applied = $this->applySheetChanges([self::sheetGroup(self::playerChange(Wjpc::PARTICIPANT_CONNECTED, null, PlayerFixture::PLAYER_REGULAR))], competitionId: self::EVENT, actingPlayerId: self::ORGANISER);
        self::assertSame(['applied'], self::groupStatuses($applied));
    }

    public function testAPlainUnlinkIsNoRefusal(): void
    {
        $this->applySheetChanges([self::sheetGroup(self::playerChange(Wjpc::PARTICIPANT_CONNECTED, PlayerFixture::PLAYER_REGULAR, null))], competitionId: self::EVENT, actingPlayerId: self::ORGANISER);

        self::assertSame(ClaimOutcome::Linked, $this->claim(PlayerFixture::PLAYER_REGULAR, Wjpc::PARTICIPANT_CONNECTED));
    }

    // ---------------------------------------------------------------- helpers

    private function claim(string $playerId, string $participantId): ClaimOutcome
    {
        $outcome = $this->messageBus->dispatch(new ClaimParticipant(self::EVENT, $playerId, $participantId))->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(ClaimOutcome::class, $outcome);

        return $outcome;
    }

    private function join(string $playerId, string $participantId, bool $releasesOwnRegistration = false): ClaimOutcome
    {
        $outcome = $this->messageBus->dispatch(new JoinCompetition(self::EVENT, $playerId, $participantId, $releasesOwnRegistration))->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(ClaimOutcome::class, $outcome);

        return $outcome;
    }

    private function policy(string $policy): void
    {
        $this->database->executeStatement('UPDATE competition SET participant_claims = :policy WHERE id = :id', ['policy' => $policy, 'id' => self::EVENT]);
    }

    private function managedWithPaidOwnRow(): void
    {
        $this->database->executeStatement('UPDATE competition SET registration_managed = true WHERE id = :id', ['id' => self::EVENT]);
        $this->database->executeStatement("UPDATE competition_participant SET registration_status = 'paid' WHERE id = :id", ['id' => Wjpc::PARTICIPANT_SELF_JOINED]);
    }

    private function newParticipant(string $name): string
    {
        $participant = new CompetitionParticipant(
            id: Uuid::uuid7(),
            name: $name,
            country: 'cz',
            competition: self::getContainer()->get(CompetitionRepository::class)->get(self::EVENT),
        );
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($participant);
        $entityManager->flush();

        return $participant->id->toString();
    }

    private function refusal(string $participantId, string $playerId, string $action): void
    {
        $this->database->executeStatement(
            "INSERT INTO participant_link_log (id, competition_id, participant_id, participant_name, player_id, player_name, action, method, by_player_id, at)
             VALUES (:id, :competition, :participant, 'x', :player, 'y', :action, NULL, NULL, now())",
            ['id' => Uuid::uuid7()->toString(), 'competition' => self::EVENT, 'participant' => $participantId, 'player' => $playerId, 'action' => $action],
        );
    }

    private function linkedPlayer(string $participantId): null|string
    {
        $playerId = $this->database->fetchOne('SELECT player_id FROM competition_participant WHERE id = :id', ['id' => $participantId]);

        return is_string($playerId) ? $playerId : null;
    }

    private function connectedVia(string $participantId): null|string
    {
        $via = $this->database->fetchOne('SELECT connected_via FROM competition_participant WHERE id = :id', ['id' => $participantId]);

        return is_string($via) ? $via : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $participantId): array
    {
        return self::participantRow($this->database, $participantId);
    }

    /**
     * @return list<array{id: string, participant_id: string, player_id: string, status: string, kind: string, player_name: string}>
     */
    private function requests(): array
    {
        /** @var list<array{id: string, participant_id: string, player_id: string, status: string, kind: string, player_name: string}> $rows */
        $rows = $this->database->fetchAllAssociative(
            'SELECT id, participant_id, player_id, status, kind, player_name FROM participant_link_request WHERE competition_id = :id ORDER BY created_at, id',
            ['id' => self::EVENT],
        );

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function logActions(string $participantId): array
    {
        return array_column($this->log($participantId), 'action');
    }

    /**
     * @return list<array{action: string, player_id: string}>
     */
    private function log(string $participantId): array
    {
        /** @var list<array{action: string, player_id: string}> $rows */
        $rows = $this->database->fetchAllAssociative(
            'SELECT action, player_id FROM participant_link_log WHERE participant_id = :id ORDER BY at, id',
            ['id' => $participantId],
        );

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private static function playerChange(string $participantId, null|string $from, null|string $to, bool $notThem = false): array
    {
        $change = ['op' => 'player', 'participant' => $participantId, 'from' => $from, 'to' => $to];

        if ($notThem) {
            $change['notThem'] = true;
        }

        return $change;
    }

    /**
     * @return array<string, mixed>
     */
    private static function declineChange(string $requestId, string $from): array
    {
        return ['op' => 'declineLinkRequest', 'request' => $requestId, 'from' => $from];
    }
}
