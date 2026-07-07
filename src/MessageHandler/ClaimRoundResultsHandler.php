<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\RoundResult;
use SpeedPuzzling\Web\Message\ClaimRoundResults;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\RoundResultRepository;
use SpeedPuzzling\Web\Services\CompetitionTeamGroupBuilder;
use SpeedPuzzling\Web\Value\PuzzlersGroup;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class ClaimRoundResultsHandler
{
    public function __construct(
        private RoundResultRepository $resultRepository,
        private PlayerRepository $playerRepository,
        private CompetitionTeamGroupBuilder $groupBuilder,
        private EntityManagerInterface $entityManager,
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ClaimRoundResults $message): void
    {
        $player = $this->playerRepository->get($message->playerId);

        foreach ($message->resultIds as $resultId) {
            $result = $this->resultRepository->find($resultId);

            if ($result === null) {
                continue;
            }

            if ($result->participant !== null) {
                $this->claimSoloResult($result, $player);
            } elseif ($result->team !== null) {
                $this->claimTeamResult($result, $player);
            }
        }
    }

    private function claimSoloResult(RoundResult $result, Player $player): void
    {
        // Only the connected player of the participant can claim
        if ($result->participant?->player?->id->equals($player->id) !== true) {
            return;
        }

        if ($result->solvingTime !== null) {
            return;
        }

        // Dedupe: the player may have logged this round themselves (app stopwatch + API)
        $existingTimeId = $this->findPlayerTimeForRound($player->id->toString(), $result->round->id->toString());

        if ($existingTimeId !== null) {
            $existingTime = $this->entityManager->find(PuzzleSolvingTime::class, $existingTimeId);

            if ($existingTime !== null) {
                $result->linkSolvingTime($existingTime, claimCreated: false);
            }

            return;
        }

        $solvingTime = $this->materialize($result, $player, team: null);

        if ($solvingTime !== null) {
            $result->linkSolvingTime($solvingTime, claimCreated: true);
        }
    }

    private function claimTeamResult(RoundResult $result, Player $player): void
    {
        $team = $result->team;

        if ($team === null || !$this->isConnectedTeamMember($team->id->toString(), $player->id->toString())) {
            return;
        }

        $group = $this->groupBuilder->buildGroup($team->id->toString());

        if ($group === null) {
            return;
        }

        if ($result->solvingTime !== null) {
            // Subsequent claimer: no new row — their group entry upgrades to a real player
            $result->solvingTime->replaceTeam($group);

            return;
        }

        $solvingTime = $this->materialize($result, $player, team: $group);

        if ($solvingTime !== null) {
            $result->linkSolvingTime($solvingTime, claimCreated: true);
        }
    }

    private function materialize(RoundResult $result, Player $player, null|PuzzlersGroup $team): null|PuzzleSolvingTime
    {
        $round = $result->round;
        $roundPuzzle = $round->roundPuzzles->first();

        // A solving time needs a puzzle — rounds without one are not claimable
        if ($roundPuzzle === false) {
            return null;
        }

        $solvingTime = new PuzzleSolvingTime(
            id: Uuid::uuid7(),
            secondsToSolve: $result->secondsToSolve,
            player: $player,
            puzzle: $roundPuzzle->puzzle,
            trackedAt: $this->clock->now(),
            verified: true,
            team: $team,
            finishedAt: $round->startsAt,
            comment: null,
            finishedPuzzlePhoto: null,
            firstAttempt: !$this->playerHasTimeForPuzzle($player->id->toString(), $roundPuzzle->puzzle->id->toString()),
            unboxed: false,
            competitionRound: $round,
            competition: $round->competition,
            missingPieces: $result->missingPieces,
        );

        $this->entityManager->persist($solvingTime);

        return $solvingTime;
    }

    private function findPlayerTimeForRound(string $playerId, string $roundId): null|string
    {
        /** @var false|string $result */
        $result = $this->database->executeQuery(
            'SELECT id FROM puzzle_solving_time WHERE player_id = :playerId AND competition_round_id = :roundId LIMIT 1',
            ['playerId' => $playerId, 'roundId' => $roundId],
        )->fetchOne();

        return $result !== false ? $result : null;
    }

    private function playerHasTimeForPuzzle(string $playerId, string $puzzleId): bool
    {
        return $this->database->executeQuery(
            'SELECT 1 FROM puzzle_solving_time WHERE player_id = :playerId AND puzzle_id = :puzzleId LIMIT 1',
            ['playerId' => $playerId, 'puzzleId' => $puzzleId],
        )->fetchOne() !== false;
    }

    private function isConnectedTeamMember(string $teamId, string $playerId): bool
    {
        $query = <<<SQL
SELECT 1
FROM competition_participant_round cpr
INNER JOIN competition_participant cp ON cp.id = cpr.participant_id AND cp.deleted_at IS NULL
WHERE cpr.team_id = :teamId
AND cp.player_id = :playerId
LIMIT 1
SQL;

        return $this->database->executeQuery($query, [
            'teamId' => $teamId,
            'playerId' => $playerId,
        ])->fetchOne() !== false;
    }
}
