<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionDivision;

readonly final class CompetitionDivisionRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function find(string $divisionId): null|CompetitionDivision
    {
        if (!Uuid::isValid($divisionId)) {
            return null;
        }

        return $this->entityManager->find(CompetitionDivision::class, $divisionId);
    }

    /**
     * @return list<CompetitionDivision> by position, then name
     */
    public function ofCompetition(Competition $competition): array
    {
        /** @var list<CompetitionDivision> $divisions */
        $divisions = $this->entityManager->createQueryBuilder()
            ->select('division')
            ->from(CompetitionDivision::class, 'division')
            ->where('division.competition = :competition')
            ->setParameter('competition', $competition)
            ->orderBy('division.position')
            ->addOrderBy('division.name')
            ->addOrderBy('division.id')
            ->getQuery()
            ->getResult();

        return $divisions;
    }

    public function save(CompetitionDivision $division): void
    {
        $this->entityManager->persist($division);
    }

    public function delete(CompetitionDivision $division): void
    {
        $this->entityManager->remove($division);
    }
}
