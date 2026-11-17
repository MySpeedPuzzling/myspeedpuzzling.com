<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionStanding;
use SpeedPuzzling\Web\Exceptions\CompetitionStandingNotFound;

readonly final class CompetitionStandingRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws CompetitionStandingNotFound
     */
    public function get(string $standingId): CompetitionStanding
    {
        if (!Uuid::isValid($standingId)) {
            throw new CompetitionStandingNotFound();
        }

        $standing = $this->entityManager->find(CompetitionStanding::class, $standingId);

        return $standing ?? throw new CompetitionStandingNotFound();
    }

    /**
     * @return list<CompetitionStanding> by position, then creation
     */
    public function ofCompetition(Competition $competition): array
    {
        /** @var list<CompetitionStanding> $standings */
        $standings = $this->entityManager->createQueryBuilder()
            ->select('standing')
            ->from(CompetitionStanding::class, 'standing')
            ->where('standing.competition = :competition')
            ->setParameter('competition', $competition)
            ->orderBy('standing.position')
            ->addOrderBy('standing.createdAt')
            ->addOrderBy('standing.id')
            ->getQuery()
            ->getResult();

        return $standings;
    }

    public function save(CompetitionStanding $standing): void
    {
        $this->entityManager->persist($standing);
    }

    public function delete(CompetitionStanding $standing): void
    {
        $this->entityManager->remove($standing);
    }
}
