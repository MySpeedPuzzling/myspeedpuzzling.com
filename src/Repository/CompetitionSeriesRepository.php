<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionSeries;
use SpeedPuzzling\Web\Entity\Organization;
use SpeedPuzzling\Web\Exceptions\CompetitionSeriesNotFound;

readonly final class CompetitionSeriesRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws CompetitionSeriesNotFound
     */
    public function get(string $seriesId): CompetitionSeries
    {
        if (!Uuid::isValid($seriesId)) {
            throw new CompetitionSeriesNotFound();
        }

        $series = $this->entityManager->find(CompetitionSeries::class, $seriesId);

        return $series ?? throw new CompetitionSeriesNotFound();
    }

    public function save(CompetitionSeries $series): void
    {
        $this->entityManager->persist($series);
    }

    public function delete(CompetitionSeries $series): void
    {
        $this->entityManager->remove($series);
    }

    /**
     * @return list<CompetitionSeries>
     */
    public function listByOrganization(Organization $organization): array
    {
        /** @var list<CompetitionSeries> $series */
        $series = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(CompetitionSeries::class, 's')
            ->where('s.organization = :organization')
            ->setParameter('organization', $organization)
            ->orderBy('s.name')
            ->getQuery()
            ->getResult();

        return $series;
    }
}
