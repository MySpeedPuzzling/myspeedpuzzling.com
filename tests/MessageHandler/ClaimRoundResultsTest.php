<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\CompetitionParticipantRound;
use SpeedPuzzling\Web\Entity\CompetitionTeam;
use SpeedPuzzling\Web\Message\ClaimRoundResults;
use SpeedPuzzling\Web\Message\DeleteOfficialRoundResult;
use SpeedPuzzling\Web\Message\JoinCompetition;
use SpeedPuzzling\Web\Message\LeaveCompetition;
use SpeedPuzzling\Web\Message\UpsertOfficialRoundResult;
use SpeedPuzzling\Web\Query\GetClaimableResultsForPlayer;
use SpeedPuzzling\Web\Query\GetCompetitionParticipants;
use SpeedPuzzling\Web\Message\PublishRoundResults;
use SpeedPuzzling\Web\Repository\OfficialRoundResultRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class ClaimRoundResultsTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private OfficialRoundResultRepository $resultRepository;
    private GetClaimableResultsForPlayer $getClaimableResults;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->resultRepository = self::getContainer()->get(OfficialRoundResultRepository::class);
        $this->getClaimableResults = self::getContainer()->get(GetClaimableResultsForPlayer::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testTeamClaimEndToEnd(): void
    {
        // Organizer enters a team result by name only (Minnesota flow)
        $resultId = Uuid::uuid7()->toString();
        $this->messageBus->dispatch(new UpsertOfficialRoundResult(
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
        $this->putOnTeam(PlayerFixture::PLAYER_REGULAR, $teamId);

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
        $this->messageBus->dispatch(new UpsertOfficialRoundResult(
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
        $this->putOnTeam(PlayerFixture::PLAYER_REGULAR, $teamId);
        $this->messageBus->dispatch(new ClaimRoundResults(
            playerId: PlayerFixture::PLAYER_REGULAR,
            resultIds: [$resultId],
        ));

        // Second member joins the same team + claims
        $this->putOnTeam(PlayerFixture::PLAYER_WITH_FAVORITES, $teamId);
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

    /**
     * Leaving an event is an RSVP ("I'm not coming"), it never deletes a result from the profile
     * (architect D12 - the join flow no longer un-claims).
     */
    public function testLeaveCompetitionKeepsClaimedTime(): void
    {
        $resultId = Uuid::uuid7()->toString();
        $this->messageBus->dispatch(new UpsertOfficialRoundResult(
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

        $this->putOnTeam(PlayerFixture::PLAYER_REGULAR, $result->team->id->toString());
        $this->messageBus->dispatch(new ClaimRoundResults(
            playerId: PlayerFixture::PLAYER_REGULAR,
            resultIds: [$resultId],
        ));

        $result = $this->resultRepository->get($resultId);
        self::assertNotNull($result->solvingTime);

        $this->messageBus->dispatch(new LeaveCompetition(
            competitionId: CompetitionFixture::COMPETITION_WJPC_2024,
            playerId: PlayerFixture::PLAYER_REGULAR,
        ));

        $result = $this->resultRepository->get($resultId);
        self::assertNotNull($result->solvingTime);
        self::assertSame(3333, $result->secondsToSolve);
    }

    public function testOrganizerEditPropagatesToClaimedTime(): void
    {
        $resultId = Uuid::uuid7()->toString();
        $this->messageBus->dispatch(new UpsertOfficialRoundResult(
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

        $this->putOnTeam(PlayerFixture::PLAYER_REGULAR, $result->team->id->toString());
        $this->messageBus->dispatch(new ClaimRoundResults(
            playerId: PlayerFixture::PLAYER_REGULAR,
            resultIds: [$resultId],
        ));

        // Organizer corrects the time
        $this->messageBus->dispatch(new UpsertOfficialRoundResult(
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
        $this->messageBus->dispatch(new DeleteOfficialRoundResult(resultId: $resultId));

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
        $this->messageBus->dispatch(new UpsertOfficialRoundResult(
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

        $this->putOnTeam(PlayerFixture::PLAYER_REGULAR, $result->team->id->toString());

        // Round results are not published → nothing claimable
        $claimable = $this->getClaimableResults->inCompetition(
            CompetitionFixture::COMPETITION_WJPC_2024,
            PlayerFixture::PLAYER_REGULAR,
        );

        self::assertNotContains($resultId, array_column($claimable, 'resultId'));
    }

    /**
     * The roster spot the removed "I was in team X" join gave (architect D3 - claiming is being redesigned): the
     * player's own participant row of the event on the team of its round.
     */
    private function putOnTeam(string $playerId, string $teamId): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $team = $entityManager->find(CompetitionTeam::class, $teamId);
        assert($team instanceof CompetitionTeam);

        $connections = self::getContainer()->get(GetCompetitionParticipants::class)
            ->getPlayerConnections(CompetitionFixture::COMPETITION_WJPC_2024, $playerId);

        if ($connections === []) {
            $this->messageBus->dispatch(new JoinCompetition(
                competitionId: CompetitionFixture::COMPETITION_WJPC_2024,
                playerId: $playerId,
            ));
            $connections = self::getContainer()->get(GetCompetitionParticipants::class)
                ->getPlayerConnections(CompetitionFixture::COMPETITION_WJPC_2024, $playerId);
        }

        $participant = $entityManager->find(CompetitionParticipant::class, $connections[0]);
        assert($participant instanceof CompetitionParticipant);

        $participantRound = $entityManager->getRepository(CompetitionParticipantRound::class)->findOneBy([
            'participant' => $participant,
            'round' => $team->round,
        ]);

        if ($participantRound instanceof CompetitionParticipantRound) {
            $participantRound->assignToTeam($team);
        } else {
            $entityManager->persist(new CompetitionParticipantRound(Uuid::uuid7(), $participant, $team->round, $team));
        }

        $entityManager->flush();
    }
}
