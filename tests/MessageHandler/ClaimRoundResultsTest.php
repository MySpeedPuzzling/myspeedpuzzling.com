<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\ClaimRoundResults;
use SpeedPuzzling\Web\Message\DeleteRoundResult;
use SpeedPuzzling\Web\Message\JoinCompetition;
use SpeedPuzzling\Web\Message\LeaveCompetition;
use SpeedPuzzling\Web\Message\UpsertRoundResult;
use SpeedPuzzling\Web\Query\GetClaimableResultsForPlayer;
use SpeedPuzzling\Web\Message\PublishRoundResults;
use SpeedPuzzling\Web\Repository\RoundResultRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class ClaimRoundResultsTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private RoundResultRepository $resultRepository;
    private GetClaimableResultsForPlayer $getClaimableResults;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->resultRepository = self::getContainer()->get(RoundResultRepository::class);
        $this->getClaimableResults = self::getContainer()->get(GetClaimableResultsForPlayer::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testTeamClaimEndToEnd(): void
    {
        // Organizer enters a team result by name only (Minnesota flow)
        $resultId = Uuid::uuid7()->toString();
        $this->messageBus->dispatch(new UpsertRoundResult(
            resultId: $resultId,
            roundId: CompetitionRoundFixture::ROUND_WJPC_PAIRS,
            participantId: null,
            teamId: null,
            entrantName: 'Team Awesome',
            secondsToSolve: 5400,
            missingPieces: null,
        ));
        $this->messageBus->dispatch(new PublishRoundResults(
            roundId: CompetitionRoundFixture::ROUND_WJPC_PAIRS,
            notifyParticipants: false,
        ));

        $result = $this->resultRepository->get($resultId);
        self::assertNotNull($result->team);
        $teamId = $result->team->id->toString();

        // A player claims their team spot via the join flow
        $this->messageBus->dispatch(new JoinCompetition(
            competitionId: CompetitionFixture::COMPETITION_WJPC_2024,
            playerId: PlayerFixture::PLAYER_REGULAR,
            teamId: $teamId,
        ));

        // Their result is claimable
        $claimable = $this->getClaimableResults->inCompetition(
            CompetitionFixture::COMPETITION_WJPC_2024,
            PlayerFixture::PLAYER_REGULAR,
        );
        self::assertContains($resultId, array_column($claimable, 'resultId'));

        // Claim materializes a verified team solving time owned by the claimer
        $this->messageBus->dispatch(new ClaimRoundResults(
            playerId: PlayerFixture::PLAYER_REGULAR,
            resultIds: [$resultId],
        ));

        $result = $this->resultRepository->get($resultId);
        self::assertNotNull($result->solvingTime);
        self::assertTrue($result->claimCreatedSolvingTime);
        self::assertTrue($result->solvingTime->verified);
        self::assertSame(5400, $result->solvingTime->secondsToSolve);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $result->solvingTime->player->id->toString());
        self::assertNotNull($result->solvingTime->team);
        self::assertNotNull($result->solvingTime->competitionRound);

        // Already claimed — not claimable anymore
        $claimable = $this->getClaimableResults->inCompetition(
            CompetitionFixture::COMPETITION_WJPC_2024,
            PlayerFixture::PLAYER_REGULAR,
        );
        self::assertNotContains($resultId, array_column($claimable, 'resultId'));
    }

    public function testSecondTeamMemberClaimUpgradesGroupWithoutNewRow(): void
    {
        $resultId = Uuid::uuid7()->toString();
        $this->messageBus->dispatch(new UpsertRoundResult(
            resultId: $resultId,
            roundId: CompetitionRoundFixture::ROUND_WJPC_PAIRS,
            participantId: null,
            teamId: null,
            entrantName: 'Duo Dynamo',
            secondsToSolve: 4321,
            missingPieces: null,
        ));
        $this->messageBus->dispatch(new PublishRoundResults(
            roundId: CompetitionRoundFixture::ROUND_WJPC_PAIRS,
            notifyParticipants: false,
        ));

        $result = $this->resultRepository->get($resultId);
        self::assertNotNull($result->team);
        $teamId = $result->team->id->toString();

        // First member joins + claims
        $this->messageBus->dispatch(new JoinCompetition(
            competitionId: CompetitionFixture::COMPETITION_WJPC_2024,
            playerId: PlayerFixture::PLAYER_REGULAR,
            teamId: $teamId,
        ));
        $this->messageBus->dispatch(new ClaimRoundResults(
            playerId: PlayerFixture::PLAYER_REGULAR,
            resultIds: [$resultId],
        ));

        // Second member joins the same team + claims
        $this->messageBus->dispatch(new JoinCompetition(
            competitionId: CompetitionFixture::COMPETITION_WJPC_2024,
            playerId: PlayerFixture::PLAYER_WITH_FAVORITES,
            teamId: $teamId,
        ));
        $this->messageBus->dispatch(new ClaimRoundResults(
            playerId: PlayerFixture::PLAYER_WITH_FAVORITES,
            resultIds: [$resultId],
        ));

        $result = $this->resultRepository->get($resultId);
        self::assertNotNull($result->solvingTime);
        self::assertNotNull($result->solvingTime->team);

        $playerIds = array_map(
            static fn ($puzzler): null|string => $puzzler->playerId,
            $result->solvingTime->team->puzzlers,
        );

        self::assertContains(PlayerFixture::PLAYER_REGULAR, $playerIds);
        self::assertContains(PlayerFixture::PLAYER_WITH_FAVORITES, $playerIds);

        // Still exactly one solving time row for this round result
        /** @var int|string $count */
        $count = $this->database->executeQuery(
            'SELECT COUNT(*) FROM puzzle_solving_time WHERE competition_round_id = :roundId',
            ['roundId' => CompetitionRoundFixture::ROUND_WJPC_PAIRS],
        )->fetchOne();
        self::assertSame(1, (int) $count);
    }

    public function testLeaveCompetitionRevertsClaimCreatedTime(): void
    {
        $resultId = Uuid::uuid7()->toString();
        $this->messageBus->dispatch(new UpsertRoundResult(
            resultId: $resultId,
            roundId: CompetitionRoundFixture::ROUND_WJPC_PAIRS,
            participantId: null,
            teamId: null,
            entrantName: 'Leavers',
            secondsToSolve: 3333,
            missingPieces: null,
        ));
        $this->messageBus->dispatch(new PublishRoundResults(
            roundId: CompetitionRoundFixture::ROUND_WJPC_PAIRS,
            notifyParticipants: false,
        ));

        $result = $this->resultRepository->get($resultId);
        self::assertNotNull($result->team);

        $this->messageBus->dispatch(new JoinCompetition(
            competitionId: CompetitionFixture::COMPETITION_WJPC_2024,
            playerId: PlayerFixture::PLAYER_REGULAR,
            teamId: $result->team->id->toString(),
        ));
        $this->messageBus->dispatch(new ClaimRoundResults(
            playerId: PlayerFixture::PLAYER_REGULAR,
            resultIds: [$resultId],
        ));

        $result = $this->resultRepository->get($resultId);
        self::assertNotNull($result->solvingTime);

        // Leaving un-claims: the claim-created row is deleted, the official result stays
        $this->messageBus->dispatch(new LeaveCompetition(
            competitionId: CompetitionFixture::COMPETITION_WJPC_2024,
            playerId: PlayerFixture::PLAYER_REGULAR,
        ));

        $result = $this->resultRepository->get($resultId);
        self::assertNull($result->solvingTime);
        self::assertSame(3333, $result->secondsToSolve);

        /** @var int|string $count */
        $count = $this->database->executeQuery(
            'SELECT COUNT(*) FROM puzzle_solving_time WHERE competition_round_id = :roundId',
            ['roundId' => CompetitionRoundFixture::ROUND_WJPC_PAIRS],
        )->fetchOne();
        self::assertSame(0, (int) $count);
    }

    public function testOrganizerEditPropagatesToClaimedTime(): void
    {
        $resultId = Uuid::uuid7()->toString();
        $this->messageBus->dispatch(new UpsertRoundResult(
            resultId: $resultId,
            roundId: CompetitionRoundFixture::ROUND_WJPC_PAIRS,
            participantId: null,
            teamId: null,
            entrantName: 'Corrected Crew',
            secondsToSolve: 4000,
            missingPieces: null,
        ));
        $this->messageBus->dispatch(new PublishRoundResults(
            roundId: CompetitionRoundFixture::ROUND_WJPC_PAIRS,
            notifyParticipants: false,
        ));

        $result = $this->resultRepository->get($resultId);
        self::assertNotNull($result->team);

        $this->messageBus->dispatch(new JoinCompetition(
            competitionId: CompetitionFixture::COMPETITION_WJPC_2024,
            playerId: PlayerFixture::PLAYER_REGULAR,
            teamId: $result->team->id->toString(),
        ));
        $this->messageBus->dispatch(new ClaimRoundResults(
            playerId: PlayerFixture::PLAYER_REGULAR,
            resultIds: [$resultId],
        ));

        // Organizer corrects the time
        $this->messageBus->dispatch(new UpsertRoundResult(
            resultId: $resultId,
            roundId: CompetitionRoundFixture::ROUND_WJPC_PAIRS,
            participantId: null,
            teamId: $result->team->id->toString(),
            entrantName: null,
            secondsToSolve: 4444,
            missingPieces: null,
        ));

        $result = $this->resultRepository->get($resultId);
        self::assertNotNull($result->solvingTime);
        self::assertSame(4444, $result->solvingTime->secondsToSolve);

        // Organizer deletes the result — the claim-created time falls with it
        $this->messageBus->dispatch(new DeleteRoundResult(resultId: $resultId));

        /** @var int|string $count */
        $count = $this->database->executeQuery(
            'SELECT COUNT(*) FROM puzzle_solving_time WHERE competition_round_id = :roundId',
            ['roundId' => CompetitionRoundFixture::ROUND_WJPC_PAIRS],
        )->fetchOne();
        self::assertSame(0, (int) $count);
    }

    public function testDraftResultsAreNotClaimable(): void
    {
        $resultId = Uuid::uuid7()->toString();
        $this->messageBus->dispatch(new UpsertRoundResult(
            resultId: $resultId,
            roundId: CompetitionRoundFixture::ROUND_WJPC_PAIRS,
            participantId: null,
            teamId: null,
            entrantName: 'Draft Dodgers',
            secondsToSolve: 2222,
            missingPieces: null,
        ));

        $result = $this->resultRepository->get($resultId);
        self::assertNotNull($result->team);

        $this->messageBus->dispatch(new JoinCompetition(
            competitionId: CompetitionFixture::COMPETITION_WJPC_2024,
            playerId: PlayerFixture::PLAYER_REGULAR,
            teamId: $result->team->id->toString(),
        ));

        // Round results are not published → nothing claimable
        $claimable = $this->getClaimableResults->inCompetition(
            CompetitionFixture::COMPETITION_WJPC_2024,
            PlayerFixture::PLAYER_REGULAR,
        );

        self::assertNotContains($resultId, array_column($claimable, 'resultId'));
    }
}
