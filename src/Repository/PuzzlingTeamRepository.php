<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzlingTeam;
use SpeedPuzzling\Web\Exceptions\PuzzlingTeamNotFound;

readonly final class PuzzlingTeamRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws PuzzlingTeamNotFound
     */
    public function get(string $teamId): PuzzlingTeam
    {
        if (Uuid::isValid($teamId) === false) {
            throw new PuzzlingTeamNotFound();
        }

        $team = $this->entityManager->find(PuzzlingTeam::class, $teamId);

        if ($team === null) {
            throw new PuzzlingTeamNotFound();
        }

        return $team;
    }

    public function remove(PuzzlingTeam $team): void
    {
        $this->entityManager->remove($team);
    }

    /**
     * Guests have no account, so only a registered member ever matches.
     */
    public function isMember(PuzzlingTeam $team, string $playerId): bool
    {
        return $this->entityManager->getConnection()->fetchOne(
            'SELECT 1 FROM puzzling_team_member WHERE team_id = :teamId AND player_id = :playerId',
            ['teamId' => $team->id->toString(), 'playerId' => $playerId],
        ) !== false;
    }

    /**
     * @return list<string>
     */
    public function memberPlayerIds(PuzzlingTeam $team): array
    {
        /** @var list<string> $ids */
        $ids = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT player_id FROM puzzling_team_member WHERE team_id = :teamId AND player_id IS NOT NULL',
            ['teamId' => $team->id->toString()],
        );

        return $ids;
    }

    public function hasResults(PuzzlingTeam $team): bool
    {
        return $this->entityManager->getConnection()->fetchOne(
            'SELECT 1 FROM puzzle_solving_time WHERE puzzling_team_id = :teamId LIMIT 1',
            ['teamId' => $team->id->toString()],
        ) !== false;
    }
}
