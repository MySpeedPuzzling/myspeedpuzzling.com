<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\RoundResult;

/**
 * Reverses result materialization when a player disconnects from a participant
 * (leave event, switch connection, organizer unlink).
 *
 * Official RoundResults are never touched — only the player's side:
 * - claim-created solo times are deleted, linked self-logged times are kept (unlinked)
 * - team times downgrade the player's group entry back to name-only; if the player
 *   owns the row, ownership transfers to another claimed member, else the row is
 *   deleted (when claim-created)
 */
readonly final class ClaimedResultReverter
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Connection $database,
        private CompetitionTeamGroupBuilder $groupBuilder,
        private SolvingTimeRemover $solvingTimeRemover,
    ) {
    }

    public function revertForPlayerInCompetition(string $playerId, string $competitionId): void
    {
        $resultIds = $this->findLinkedResultIds($playerId, $competitionId);

        foreach ($resultIds as $resultId) {
            $result = $this->entityManager->find(RoundResult::class, $resultId);

            if ($result === null || $result->solvingTime === null) {
                continue;
            }

            if ($result->participant !== null) {
                $this->revertSolo($result, $playerId);
            } elseif ($result->team !== null) {
                $this->revertTeamMembership($result, $playerId);
            }
        }
    }

    private function revertSolo(RoundResult $result, string $playerId): void
    {
        $solvingTime = $result->solvingTime;

        if ($solvingTime === null || $solvingTime->player->id->toString() !== $playerId) {
            return;
        }

        $claimCreated = $result->claimCreatedSolvingTime;
        $result->unlinkSolvingTime();

        if ($claimCreated) {
            $this->solvingTimeRemover->remove($solvingTime);
        }
    }

    private function revertTeamMembership(RoundResult $result, string $playerId): void
    {
        $solvingTime = $result->solvingTime;
        $team = $result->team;

        if ($solvingTime === null || $team === null) {
            return;
        }

        // Rebuild the group with the leaving player downgraded to name-only
        $group = $this->groupBuilder->buildGroup($team->id->toString(), treatAsNameOnlyPlayerIds: [$playerId]);

        if ($solvingTime->player->id->toString() !== $playerId) {
            // Not the owner — just downgrade their entry
            if ($group !== null) {
                $solvingTime->replaceTeam($group);
            }

            return;
        }

        // Owner is leaving — transfer to another claimed member if one exists
        $newOwnerId = $this->findOtherConnectedMember($team->id->toString(), $playerId);

        if ($newOwnerId !== null) {
            $newOwner = $this->entityManager->find(Player::class, $newOwnerId);

            if ($newOwner !== null && $group !== null) {
                $solvingTime->transferOwnership($newOwner, $group);

                return;
            }
        }

        $claimCreated = $result->claimCreatedSolvingTime;
        $result->unlinkSolvingTime();

        if ($claimCreated) {
            $this->solvingTimeRemover->remove($solvingTime);
        }
    }

    /**
     * Results in the competition whose linked solving time involves this player,
     * either as row owner or inside the team JSON.
     *
     * @return array<string>
     */
    private function findLinkedResultIds(string $playerId, string $competitionId): array
    {
        $query = <<<SQL
SELECT rr.id
FROM round_result rr
INNER JOIN competition_round r ON r.id = rr.round_id
INNER JOIN puzzle_solving_time pst ON pst.id = rr.solving_time_id
WHERE r.competition_id = :competitionId
AND (
    pst.player_id = :playerId
    OR (pst.team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID)))
)
SQL;

        /** @var array<string> $ids */
        $ids = $this->database->executeQuery($query, [
            'competitionId' => $competitionId,
            'playerId' => $playerId,
        ])->fetchFirstColumn();

        return $ids;
    }

    private function findOtherConnectedMember(string $teamId, string $excludePlayerId): null|string
    {
        $query = <<<SQL
SELECT cp.player_id
FROM competition_participant_round cpr
INNER JOIN competition_participant cp ON cp.id = cpr.participant_id AND cp.deleted_at IS NULL
WHERE cpr.team_id = :teamId
AND cp.player_id IS NOT NULL
AND cp.player_id != :excludePlayerId
LIMIT 1
SQL;

        /** @var false|string $result */
        $result = $this->database->executeQuery($query, [
            'teamId' => $teamId,
            'excludePlayerId' => $excludePlayerId,
        ])->fetchOne();

        return $result !== false ? $result : null;
    }
}
