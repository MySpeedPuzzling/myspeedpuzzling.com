<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionTeam;
use SpeedPuzzling\Web\Exceptions\CompetitionTeamNotFound;

readonly final class CompetitionTeamRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws CompetitionTeamNotFound
     */
    public function get(string $teamId): CompetitionTeam
    {
        if (!Uuid::isValid($teamId)) {
            throw new CompetitionTeamNotFound();
        }

        $team = $this->entityManager->find(CompetitionTeam::class, $teamId);

        return $team ?? throw new CompetitionTeamNotFound();
    }

    public function save(CompetitionTeam $team): void
    {
        $this->entityManager->persist($team);
    }

    public function delete(CompetitionTeam $team): void
    {
        $this->entityManager->remove($team);
    }
}
