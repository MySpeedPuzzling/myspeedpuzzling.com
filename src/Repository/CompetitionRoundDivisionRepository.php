<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionRoundDivision;

readonly final class CompetitionRoundDivisionRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Every round's division of the event.
     *
     * @return list<CompetitionRoundDivision>
     */
    public function ofCompetition(Competition $competition): array
    {
        /** @var list<CompetitionRoundDivision> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('roundDivision')
            ->from(CompetitionRoundDivision::class, 'roundDivision')
            ->innerJoin('roundDivision.division', 'division')
            ->where('division.competition = :competition')
            ->setParameter('competition', $competition)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * @return list<CompetitionRoundDivision>
     */
    public function ofRound(CompetitionRound $round): array
    {
        /** @var list<CompetitionRoundDivision> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('roundDivision', 'division')
            ->from(CompetitionRoundDivision::class, 'roundDivision')
            ->innerJoin('roundDivision.division', 'division')
            ->where('roundDivision.round = :round')
            ->setParameter('round', $round->id->toString())
            ->orderBy('division.position')
            ->addOrderBy('division.name')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function save(CompetitionRoundDivision $roundDivision): void
    {
        $this->entityManager->persist($roundDivision);
    }

    public function delete(CompetitionRoundDivision $roundDivision): void
    {
        $this->entityManager->remove($roundDivision);
    }
}
