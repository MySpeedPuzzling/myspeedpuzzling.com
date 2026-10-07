<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionParticipantRound;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\CompetitionParticipantNotFound;
use SpeedPuzzling\Web\Message\EditCompetitionParticipant;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionParticipantFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class EditCompetitionParticipantHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private CompetitionParticipantRepository $participantRepository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->participantRepository = self::getContainer()->get(CompetitionParticipantRepository::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testUpdatesParticipantFields(): void
    {
        $this->messageBus->dispatch($this->edit(name: 'Updated Name', country: 'de', externalId: 'EXT-UPDATED'));

        $this->entityManager->clear();
        $participant = $this->participantRepository->get(CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED);

        self::assertSame('Updated Name', $participant->name);
        self::assertSame('de', $participant->country);
        self::assertSame('EXT-UPDATED', $participant->externalId);
        // No round toggled - the entries are untouched
        self::assertSame([CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION], $this->roundIdsOf(CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED));
    }

    public function testConnectsPlayer(): void
    {
        $this->messageBus->dispatch($this->edit(changePlayer: true, playerId: PlayerFixture::PLAYER_ADMIN));

        $this->entityManager->clear();
        $participant = $this->participantRepository->get(CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED);

        self::assertNotNull($participant->player);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $participant->player->id->toString());
    }

    /**
     * The organiser opened the row while nobody was connected; the player picked their name meanwhile. Fixing a typo
     * must not disconnect them - the edit did not touch the connection.
     */
    public function testAnEditThatDidNotTouchThePlayerKeepsAConnectionMadeMeanwhile(): void
    {
        $participant = $this->participantRepository->get(CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED);
        $participant->connect($this->entityManager->find(Player::class, PlayerFixture::PLAYER_REGULAR) ?? throw new LogicException(), new DateTimeImmutable());
        $this->entityManager->flush();

        $this->messageBus->dispatch($this->edit(name: 'Jane Typo Fixed'));

        $this->entityManager->clear();
        $participant = $this->participantRepository->get(CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED);

        self::assertSame('Jane Typo Fixed', $participant->name);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $participant->player?->id->toString());
    }

    public function testDisconnectsWhenTheEditClearedThePlayer(): void
    {
        $this->messageBus->dispatch($this->edit(changePlayer: true, playerId: PlayerFixture::PLAYER_ADMIN));
        $this->messageBus->dispatch($this->edit(changePlayer: true, playerId: null));

        $this->entityManager->clear();

        self::assertNull($this->participantRepository->get(CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED)->player);
    }

    public function testAppliesTheRoundTogglesOfTheEdit(): void
    {
        // PARTICIPANT_UNCONNECTED is in the qualification - the edit unticks it and ticks the final
        $this->messageBus->dispatch($this->edit(
            addRoundIds: [CompetitionRoundFixture::ROUND_WJPC_FINAL],
            removeRoundIds: [CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION],
        ));

        $this->entityManager->clear();

        self::assertSame([CompetitionRoundFixture::ROUND_WJPC_FINAL], $this->roundIdsOf(CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED));
    }

    public function testIgnoresRoundsOfAnotherCompetitionDuplicatesAndRoundsAlreadyEntered(): void
    {
        $this->messageBus->dispatch($this->edit(addRoundIds: [
            CompetitionRoundFixture::ROUND_WJPC_FINAL,
            CompetitionRoundFixture::ROUND_WJPC_FINAL,
            CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION,
            CompetitionRoundFixture::ROUND_CZECH_FINAL,
            'not-a-uuid',
        ]));

        $this->entityManager->clear();

        self::assertSame(
            [CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, CompetitionRoundFixture::ROUND_WJPC_FINAL],
            $this->roundIdsOf(CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED),
        );
    }

    /**
     * Event day (review 2, A-F3): a helper opens Jane's row to fix a typo. Meanwhile the results desk advances her to the
     * final and seats her at table 7. The helper's save (it toggled no round) must not take her out of the final, and
     * the seat stays.
     */
    public function testAStaleRowNeverRemovesAnEntryAddedMeanwhile(): void
    {
        // What the row was opened with: the qualification only. Then the desk adds the final entry, seated at table 7
        /** @var Connection $database */
        $database = self::getContainer()->get(Connection::class);
        $finalEntryId = Uuid::uuid7()->toString();
        $database->insert('competition_participant_round', [
            'id' => $finalEntryId,
            'participant_id' => CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED,
            'round_id' => CompetitionRoundFixture::ROUND_WJPC_FINAL,
            'table_number' => 7,
            'result_did_not_start' => 'false',
        ]);

        $this->messageBus->dispatch($this->edit(name: 'Jane Unconnected (typo fixed)'));

        self::assertEquals(7, $database->fetchOne('SELECT table_number FROM competition_participant_round WHERE id = :id', ['id' => $finalEntryId]));
        self::assertSame(
            [CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, CompetitionRoundFixture::ROUND_WJPC_FINAL],
            $this->roundIdsOf(CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED),
        );

        // Unticking a round the person left meanwhile changes nothing either
        $database->delete('competition_participant_round', ['id' => $finalEntryId]);
        $this->messageBus->dispatch($this->edit(removeRoundIds: [CompetitionRoundFixture::ROUND_WJPC_FINAL]));

        self::assertSame([CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION], $this->roundIdsOf(CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED));
    }

    public function testAParticipantOfAnotherEventIsNotFound(): void
    {
        $this->expectException(CompetitionParticipantNotFound::class);

        try {
            $this->messageBus->dispatch(new EditCompetitionParticipant(
                competitionId: CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024,
                participantId: CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED,
                name: 'Hijacked',
                country: null,
                externalId: null,
            ));
        } finally {
            $this->entityManager->clear();
            self::assertNotSame('Hijacked', $this->participantRepository->get(CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED)->name);
        }
    }

    /**
     * @param list<string> $addRoundIds
     * @param list<string> $removeRoundIds
     */
    private function edit(
        string $name = 'Jane Unconnected',
        null|string $country = 'us',
        null|string $externalId = null,
        bool $changePlayer = false,
        null|string $playerId = null,
        array $addRoundIds = [],
        array $removeRoundIds = [],
    ): EditCompetitionParticipant {
        return new EditCompetitionParticipant(
            competitionId: CompetitionFixture::COMPETITION_WJPC_2024,
            participantId: CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED,
            name: $name,
            country: $country,
            externalId: $externalId,
            changePlayer: $changePlayer,
            playerId: $playerId,
            addRoundIds: $addRoundIds,
            removeRoundIds: $removeRoundIds,
        );
    }

    /**
     * @return list<string> sorted
     */
    private function roundIdsOf(string $participantId): array
    {
        $this->entityManager->clear();

        $roundIds = array_map(
            static fn (CompetitionParticipantRound $entry): string => $entry->round->id->toString(),
            $this->entityManager->getRepository(CompetitionParticipantRound::class)->findBy(['participant' => $participantId]),
        );
        sort($roundIds);

        return $roundIds;
    }
}
