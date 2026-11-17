<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Message\JoinCompetition;
use SpeedPuzzling\Web\Message\RecordPlayerActivity;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionParticipantFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\ClaimOutcome;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class JoinCompetitionHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private CompetitionParticipantRepository $participantRepository;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->participantRepository = self::getContainer()->get(CompetitionParticipantRepository::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testSelfJoinCreatesParticipantWithCorrectSource(): void
    {
        $this->messageBus->dispatch(new JoinCompetition(
            competitionId: CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024,
            playerId: PlayerFixture::PLAYER_ADMIN,
        ));

        /** @var array{source: string, player_id: string|null} $row */
        $row = $this->database->executeQuery(
            'SELECT source, player_id FROM competition_participant WHERE competition_id = :cid AND player_id = :pid AND deleted_at IS NULL',
            ['cid' => CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024, 'pid' => PlayerFixture::PLAYER_ADMIN],
        )->fetchAssociative();

        self::assertSame('self_joined', $row['source']);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $row['player_id']);
    }

    public function testJoinByPickingParticipantConnectsPlayer(): void
    {
        $this->messageBus->dispatch(new JoinCompetition(
            competitionId: CompetitionFixture::COMPETITION_WJPC_2024,
            playerId: PlayerFixture::PLAYER_ADMIN,
            participantId: CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED,
        ));

        $participant = $this->participantRepository->get(CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED);

        self::assertNotNull($participant->player);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $participant->player->id->toString());
    }

    public function testSelfJoinWhenAlreadySelfJoinedDoesNotCreateAnotherRow(): void
    {
        // PARTICIPANT_SELF_JOINED is linked to PLAYER_WITH_FAVORITES
        $this->messageBus->dispatch(new JoinCompetition(
            competitionId: CompetitionFixture::COMPETITION_WJPC_2024,
            playerId: PlayerFixture::PLAYER_WITH_FAVORITES,
        ));

        self::assertSame(
            [CompetitionParticipantFixture::PARTICIPANT_SELF_JOINED],
            $this->activeParticipantIds(PlayerFixture::PLAYER_WITH_FAVORITES),
        );
    }

    /**
     * A name linked to somebody else is a dispute for the organiser (participant-linking.md §5.1) - nothing of the
     * player's own rows changes, also when something flushes later in the same request
     */
    public function testPickingParticipantOfAnotherPlayerAsksTheOrganiserAndKeepsOwnRow(): void
    {
        $envelope = $this->messageBus->dispatch(new JoinCompetition(
            competitionId: CompetitionFixture::COMPETITION_WJPC_2024,
            playerId: PlayerFixture::PLAYER_WITH_FAVORITES,
            participantId: CompetitionParticipantFixture::PARTICIPANT_CONNECTED,
        ));

        self::assertSame(ClaimOutcome::DisputeSent, $envelope->last(HandledStamp::class)?->getResult());

        // The same request goes on — PlayerActivitySubscriber dispatches this on kernel.terminate,
        // and its transaction flushes whatever the handler left changed in the entity manager
        $this->messageBus->dispatch(new RecordPlayerActivity('auth0|fav004'));

        self::assertSame(
            [CompetitionParticipantFixture::PARTICIPANT_SELF_JOINED],
            $this->activeParticipantIds(PlayerFixture::PLAYER_WITH_FAVORITES),
        );
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $this->database->fetchOne(
            'SELECT player_id FROM competition_participant WHERE id = :id',
            ['id' => CompetitionParticipantFixture::PARTICIPANT_CONNECTED],
        ));
    }

    public function testPickingFromListReplacesSelfJoinedRow(): void
    {
        $this->messageBus->dispatch(new JoinCompetition(
            competitionId: CompetitionFixture::COMPETITION_WJPC_2024,
            playerId: PlayerFixture::PLAYER_WITH_FAVORITES,
            participantId: CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED,
        ));

        self::assertSame(
            [CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED],
            $this->activeParticipantIds(PlayerFixture::PLAYER_WITH_FAVORITES),
        );

        // Soft-deleted, not left behind as a nameless row that anyone could pick
        $selfJoined = $this->participantRepository->get(CompetitionParticipantFixture::PARTICIPANT_SELF_JOINED);
        self::assertTrue($selfJoined->isDeleted());
    }

    public function testSelfJoinWhileConnectedToImportedRowReleasesIt(): void
    {
        // PARTICIPANT_CONNECTED is imported and linked to PLAYER_REGULAR — "I'm not on the list" after all
        $this->messageBus->dispatch(new JoinCompetition(
            competitionId: CompetitionFixture::COMPETITION_WJPC_2024,
            playerId: PlayerFixture::PLAYER_REGULAR,
        ));

        $imported = $this->participantRepository->get(CompetitionParticipantFixture::PARTICIPANT_CONNECTED);
        self::assertNull($imported->player);
        self::assertFalse($imported->isDeleted());

        $activeIds = $this->activeParticipantIds(PlayerFixture::PLAYER_REGULAR);
        self::assertCount(1, $activeIds);
        self::assertNotSame(CompetitionParticipantFixture::PARTICIPANT_CONNECTED, $activeIds[0]);
    }

    public function testPickingParticipantOfDifferentCompetitionIsRejected(): void
    {
        $envelope = $this->messageBus->dispatch(new JoinCompetition(
            competitionId: CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024,
            playerId: PlayerFixture::PLAYER_ADMIN,
            participantId: CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED,
        ));

        // participant-linking.md §5.1: not_available - nothing changes
        self::assertSame(ClaimOutcome::NotAvailable, $envelope->last(HandledStamp::class)?->getResult());
        self::assertNull($this->participantRepository->get(CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED)->player);
    }

    public function testPickingSoftDeletedParticipantIsRejected(): void
    {
        $envelope = $this->messageBus->dispatch(new JoinCompetition(
            competitionId: CompetitionFixture::COMPETITION_WJPC_2024,
            playerId: PlayerFixture::PLAYER_ADMIN,
            participantId: CompetitionParticipantFixture::PARTICIPANT_DELETED,
        ));

        self::assertSame(ClaimOutcome::NotAvailable, $envelope->last(HandledStamp::class)?->getResult());
        self::assertNull($this->participantRepository->get(CompetitionParticipantFixture::PARTICIPANT_DELETED)->player);
    }

    /**
     * Only a publicly visible event can be joined (docs/features/organizations/README.md, P17) - not one waiting for
     * approval, not a draft
     */
    public function testAnEventThatIsNotPublicCannotBeJoined(): void
    {
        foreach ([CompetitionFixture::COMPETITION_UNAPPROVED, OrganizationFixture::COMPETITION_DRAFT_NIGHT] as $competitionId) {
            try {
                $this->messageBus->dispatch(new JoinCompetition(competitionId: $competitionId, playerId: PlayerFixture::PLAYER_ADMIN));
                self::fail('A player joined an event that is not public.');
            } catch (CompetitionNotFound) {
                // Refused
            }
        }

        self::assertSame(0, $this->database->fetchOne(
            'SELECT COUNT(*) FROM competition_participant WHERE player_id = :pid AND competition_id IN (:unapproved, :draft)',
            ['pid' => PlayerFixture::PLAYER_ADMIN, 'unapproved' => CompetitionFixture::COMPETITION_UNAPPROVED, 'draft' => OrganizationFixture::COMPETITION_DRAFT_NIGHT],
        ));
    }

    public function testANameOnTheListOfADraftCannotBePicked(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $participant = new CompetitionParticipant(
            id: Uuid::uuid7(),
            name: 'Prepared Puzzler',
            country: 'cz',
            competition: self::getContainer()->get(CompetitionRepository::class)->get(OrganizationFixture::COMPETITION_DRAFT_NIGHT),
        );
        $entityManager->persist($participant);
        $entityManager->flush();

        $this->expectException(CompetitionNotFound::class);

        $this->messageBus->dispatch(new JoinCompetition(
            competitionId: OrganizationFixture::COMPETITION_DRAFT_NIGHT,
            playerId: PlayerFixture::PLAYER_ADMIN,
            participantId: $participant->id->toString(),
        ));
    }

    /**
     * @return array<string>
     */
    private function activeParticipantIds(string $playerId): array
    {
        /** @var array<string> $ids */
        $ids = $this->database->executeQuery(
            'SELECT id FROM competition_participant WHERE competition_id = :cid AND player_id = :pid AND deleted_at IS NULL',
            ['cid' => CompetitionFixture::COMPETITION_WJPC_2024, 'pid' => $playerId],
        )->fetchFirstColumn();

        return $ids;
    }
}
