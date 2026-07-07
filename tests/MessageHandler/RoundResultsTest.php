<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\DeleteRoundResult;
use SpeedPuzzling\Web\Message\PublishRoundResults;
use SpeedPuzzling\Web\Message\UnpublishRoundResults;
use SpeedPuzzling\Web\Message\UpsertRoundResult;
use SpeedPuzzling\Web\Query\GetRoundResults;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\RoundResultRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionParticipantFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class RoundResultsTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private RoundResultRepository $resultRepository;
    private CompetitionRoundRepository $roundRepository;
    private GetRoundResults $getRoundResults;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->resultRepository = self::getContainer()->get(RoundResultRepository::class);
        $this->roundRepository = self::getContainer()->get(CompetitionRoundRepository::class);
        $this->getRoundResults = self::getContainer()->get(GetRoundResults::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testUpsertCreatesResultForExistingParticipant(): void
    {
        $resultId = Uuid::uuid7()->toString();

        $this->messageBus->dispatch(new UpsertRoundResult(
            resultId: $resultId,
            roundId: CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION,
            participantId: CompetitionParticipantFixture::PARTICIPANT_CONNECTED,
            teamId: null,
            entrantName: null,
            secondsToSolve: 3600,
            missingPieces: null,
        ));

        $result = $this->resultRepository->get($resultId);

        self::assertNotNull($result->participant);
        self::assertSame(CompetitionParticipantFixture::PARTICIPANT_CONNECTED, $result->participant->id->toString());
        self::assertSame(3600, $result->secondsToSolve);
    }

    public function testUpsertByNameCreatesParticipantAndAssignsToSoloRound(): void
    {
        $resultId = Uuid::uuid7()->toString();

        $this->messageBus->dispatch(new UpsertRoundResult(
            resultId: $resultId,
            roundId: CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION,
            participantId: null,
            teamId: null,
            entrantName: 'Freshly Created Solver',
            secondsToSolve: 2400,
            missingPieces: null,
        ));

        $result = $this->resultRepository->get($resultId);

        self::assertNotNull($result->participant);
        self::assertSame('Freshly Created Solver', $result->participant->name);

        // Participant was auto-assigned to the round
        $assigned = $this->database->executeQuery(
            'SELECT 1 FROM competition_participant_round WHERE participant_id = :pid AND round_id = :rid',
            ['pid' => $result->participant->id->toString(), 'rid' => CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION],
        )->fetchOne();

        self::assertNotFalse($assigned);
    }

    public function testUpsertByNameCreatesTeamForDuoRound(): void
    {
        $resultId = Uuid::uuid7()->toString();

        $this->messageBus->dispatch(new UpsertRoundResult(
            resultId: $resultId,
            roundId: CompetitionRoundFixture::ROUND_WJPC_PAIRS,
            participantId: null,
            teamId: null,
            entrantName: 'Team Awesome',
            secondsToSolve: 5000,
            missingPieces: null,
        ));

        $result = $this->resultRepository->get($resultId);

        self::assertNull($result->participant);
        self::assertNotNull($result->team);
        self::assertSame('Team Awesome', $result->team->name);
    }

    public function testUpsertByNameIsIdempotentAcrossDifferentResultIds(): void
    {
        $firstId = Uuid::uuid7()->toString();
        $secondId = Uuid::uuid7()->toString();

        $this->messageBus->dispatch(new UpsertRoundResult(
            resultId: $firstId,
            roundId: CompetitionRoundFixture::ROUND_WJPC_PAIRS,
            participantId: null,
            teamId: null,
            entrantName: 'Sunday Slackers',
            secondsToSolve: 4000,
            missingPieces: null,
        ));

        // Same entrant name again with a different client id — must update, not duplicate
        $this->messageBus->dispatch(new UpsertRoundResult(
            resultId: $secondId,
            roundId: CompetitionRoundFixture::ROUND_WJPC_PAIRS,
            participantId: null,
            teamId: null,
            entrantName: 'Sunday Slackers',
            secondsToSolve: 4100,
            missingPieces: null,
        ));

        $standings = $this->getRoundResults->standings(CompetitionRoundFixture::ROUND_WJPC_PAIRS);
        $matching = array_filter($standings, static fn ($row): bool => $row->entrantName === 'Sunday Slackers');

        self::assertCount(1, $matching);
        $result = $this->resultRepository->get($firstId);
        self::assertSame(4100, $result->secondsToSolve);
    }

    public function testUpsertReplayWithSameResultIdUpdates(): void
    {
        $resultId = Uuid::uuid7()->toString();

        $message = new UpsertRoundResult(
            resultId: $resultId,
            roundId: CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION,
            participantId: CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED,
            teamId: null,
            entrantName: null,
            secondsToSolve: 3000,
            missingPieces: null,
        );

        $this->messageBus->dispatch($message);
        // Offline replay of the identical op
        $this->messageBus->dispatch($message);

        $standings = $this->getRoundResults->standings(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);
        $matching = array_filter($standings, static fn ($row): bool => $row->resultId === $resultId);

        self::assertCount(1, $matching);
    }

    public function testRankingOrdersFinishedThenDnfByMissingPieces(): void
    {
        $roundId = CompetitionRoundFixture::ROUND_WJPC_PAIRS;

        $dispatch = function (string $name, null|int $seconds, null|int $missing) use ($roundId): void {
            $this->messageBus->dispatch(new UpsertRoundResult(
                resultId: Uuid::uuid7()->toString(),
                roundId: $roundId,
                participantId: null,
                teamId: null,
                entrantName: $name,
                secondsToSolve: $seconds,
                missingPieces: $missing,
            ));
        };

        $dispatch('Slow Finishers', 7200, null);
        $dispatch('Fast Finishers', 3600, null);
        $dispatch('Almost Done', null, 10);
        $dispatch('Barely Started', null, 400);
        $dispatch('Tied Finishers', 3600, null);

        $standings = $this->getRoundResults->standings($roundId);
        $names = array_map(static fn ($row): null|string => $row->entrantName, $standings);
        $ranks = array_map(static fn ($row): int => $row->rank, $standings);

        // Two teams tied at 3600 share rank 1, next finisher is rank 3, DNFs after by missing pieces
        self::assertSame(['Fast Finishers', 'Tied Finishers', 'Slow Finishers', 'Almost Done', 'Barely Started'], $names);
        self::assertSame([1, 1, 3, 4, 5], $ranks);
    }

    public function testDeleteResultIsIdempotent(): void
    {
        $resultId = Uuid::uuid7()->toString();

        $this->messageBus->dispatch(new UpsertRoundResult(
            resultId: $resultId,
            roundId: CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION,
            participantId: CompetitionParticipantFixture::PARTICIPANT_CONNECTED,
            teamId: null,
            entrantName: null,
            secondsToSolve: 3600,
            missingPieces: null,
        ));

        $this->messageBus->dispatch(new DeleteRoundResult(resultId: $resultId));
        // Replay must be a no-op
        $this->messageBus->dispatch(new DeleteRoundResult(resultId: $resultId));

        self::assertNull($this->resultRepository->find($resultId));
    }

    public function testPublishAndUnpublishRoundResults(): void
    {
        $roundId = CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION;

        $this->messageBus->dispatch(new UpsertRoundResult(
            resultId: Uuid::uuid7()->toString(),
            roundId: $roundId,
            participantId: CompetitionParticipantFixture::PARTICIPANT_CONNECTED,
            teamId: null,
            entrantName: null,
            secondsToSolve: 3600,
            missingPieces: null,
        ));

        $this->messageBus->dispatch(new PublishRoundResults(roundId: $roundId, notifyParticipants: false));

        $round = $this->roundRepository->get($roundId);
        self::assertTrue($round->areResultsPublished());
        self::assertTrue($this->getRoundResults->hasPublishedResults(CompetitionFixture::COMPETITION_WJPC_2024));

        $published = $this->getRoundResults->publishedStandingsForCompetition(CompetitionFixture::COMPETITION_WJPC_2024);
        self::assertCount(1, $published);
        self::assertSame($roundId, $published[0]['roundId']);

        $this->messageBus->dispatch(new UnpublishRoundResults(roundId: $roundId));

        $round = $this->roundRepository->get($roundId);
        self::assertFalse($round->areResultsPublished());
        self::assertSame([], $this->getRoundResults->publishedStandingsForCompetition(CompetitionFixture::COMPETITION_WJPC_2024));
    }
}
