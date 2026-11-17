<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionRound;
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

    public function find(string $teamId): null|CompetitionTeam
    {
        if (!Uuid::isValid($teamId)) {
            return null;
        }

        return $this->entityManager->find(CompetitionTeam::class, $teamId);
    }

    /**
     * @return list<CompetitionTeam>
     */
    public function findByRound(CompetitionRound $round): array
    {
        return $this->entityManager->getRepository(CompetitionTeam::class)->findBy(['round' => $round]);
    }

    /**
     * The event's pairs/teams that carry a division (shared-puzzles-and-divisions.md D1).
     *
     * @return list<CompetitionTeam>
     */
    public function withDivisionInCompetition(Competition $competition): array
    {
        /** @var list<CompetitionTeam> $teams */
        $teams = $this->entityManager->createQueryBuilder()
            ->select('team')
            ->from(CompetitionTeam::class, 'team')
            ->innerJoin('team.round', 'round')
            ->where('round.competition = :competition')
            ->andWhere('team.division IS NOT NULL')
            ->setParameter('competition', $competition->id->toString())
            ->getQuery()
            ->getResult();

        return $teams;
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
