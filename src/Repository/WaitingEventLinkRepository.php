<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionSeries;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\WaitingEventLink;

readonly final class WaitingEventLinkRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function ofTime(PuzzleSolvingTime $solvingTime): null|WaitingEventLink
    {
        return $this->entityManager->getRepository(WaitingEventLink::class)->findOneBy(['solvingTime' => $solvingTime]);
    }

    public function get(string $id): null|WaitingEventLink
    {
        return $this->entityManager->find(WaitingEventLink::class, $id);
    }

    /**
     * @return list<WaitingEventLink>
     */
    public function ofCompetition(Competition $competition): array
    {
        /** @var list<WaitingEventLink> $links */
        $links = $this->entityManager->createQueryBuilder()
            ->select('w')
            ->from(WaitingEventLink::class, 'w')
            ->where('IDENTITY(w.competition) = :competition')
            ->setParameter('competition', $competition->id->toString())
            ->orderBy('w.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $links;
    }

    /**
     * The series' own (series picks) and those of its dates (editions) - approving a series makes its dates public
     *
     * @return list<WaitingEventLink>
     */
    public function ofSeriesAndItsEditions(CompetitionSeries $series): array
    {
        /** @var list<WaitingEventLink> $links */
        $links = $this->entityManager->createQueryBuilder()
            ->select('w')
            ->from(WaitingEventLink::class, 'w')
            ->leftJoin('w.competition', 'c')
            ->where('IDENTITY(w.series) = :series OR IDENTITY(c.series) = :series')
            ->setParameter('series', $series->id->toString())
            ->orderBy('w.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $links;
    }

    public function save(WaitingEventLink $link): void
    {
        $this->entityManager->persist($link);
    }

    public function delete(WaitingEventLink $link): void
    {
        $this->entityManager->remove($link);
    }
}
