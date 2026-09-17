<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Exceptions\CompetitionParticipantAlreadyConnectedToDifferentPlayer;
use SpeedPuzzling\Web\Exceptions\CompetitionParticipantNotFound;
use SpeedPuzzling\Web\Message\JoinCompetition;
use SpeedPuzzling\Web\Message\RecordPlayerActivity;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionParticipantFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

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

    public function testPickingParticipantOfAnotherPlayerKeepsOwnRowEvenWhenSomethingFlushesLater(): void
    {
        try {
            $this->messageBus->dispatch(new JoinCompetition(
                competitionId: CompetitionFixture::COMPETITION_WJPC_2024,
                playerId: PlayerFixture::PLAYER_WITH_FAVORITES,
                participantId: CompetitionParticipantFixture::PARTICIPANT_CONNECTED,
            ));
            self::fail('Picking a participant connected to another player must fail');
        } catch (HandlerFailedException $e) {
            self::assertInstanceOf(CompetitionParticipantAlreadyConnectedToDifferentPlayer::class, $e->getPrevious());
        }

        // The same request goes on — PlayerActivitySubscriber dispatches this on kernel.terminate,
        // and its transaction flushes whatever the failed handler left changed in the entity manager
        $this->messageBus->dispatch(new RecordPlayerActivity('auth0|fav004'));

        self::assertSame(
            [CompetitionParticipantFixture::PARTICIPANT_SELF_JOINED],
            $this->activeParticipantIds(PlayerFixture::PLAYER_WITH_FAVORITES),
        );
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
        $this->expectException(CompetitionParticipantNotFound::class);

        $this->messageBus->dispatch(new JoinCompetition(
            competitionId: CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024,
            playerId: PlayerFixture::PLAYER_ADMIN,
            participantId: CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED,
        ));
    }

    public function testPickingSoftDeletedParticipantIsRejected(): void
    {
        $this->expectException(CompetitionParticipantNotFound::class);

        $this->messageBus->dispatch(new JoinCompetition(
            competitionId: CompetitionFixture::COMPETITION_WJPC_2024,
            playerId: PlayerFixture::PLAYER_ADMIN,
            participantId: CompetitionParticipantFixture::PARTICIPANT_DELETED,
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
