<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\Organization;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;

readonly final class CompetitionRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws CompetitionNotFound
     */
    public function get(string $competitionId): Competition
    {
        if (!Uuid::isValid($competitionId)) {
            throw new CompetitionNotFound();
        }

        $competition = $this->entityManager->find(Competition::class, $competitionId);

        return $competition ?? throw new CompetitionNotFound();
    }

    public function save(Competition $competition): void
    {
        $this->entityManager->persist($competition);
    }

    /**
     * @throws CompetitionNotFound
     */
    public function getBySeriesAndEditionSlug(string $seriesSlug, string $editionSlug): Competition
    {
        /** @var null|Competition $competition */
        $competition = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Competition::class, 'c')
            ->join('c.series', 's')
            ->where('s.slug = :seriesSlug')
            ->andWhere('c.slug = :editionSlug')
            ->setParameter('seriesSlug', $seriesSlug)
            ->setParameter('editionSlug', $editionSlug)
            ->getQuery()
            ->getOneOrNullResult();

        return $competition ?? throw new CompetitionNotFound();
    }

    public function delete(Competition $competition): void
    {
        $this->entityManager->remove($competition);
    }

    /**
     * The organization's one-time events (editions are under it through their series)
     *
     * @return list<Competition>
     */
    public function listOneTimeByOrganization(Organization $organization): array
    {
        /** @var list<Competition> $competitions */
        $competitions = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Competition::class, 'c')
            ->where('c.organization = :organization')
            ->andWhere('c.series IS NULL')
            ->setParameter('organization', $organization)
            ->orderBy('c.name')
            ->getQuery()
            ->getResult();

        return $competitions;
    }
}
