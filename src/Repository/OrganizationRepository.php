<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionSeries;
use SpeedPuzzling\Web\Entity\Organization;
use SpeedPuzzling\Web\Exceptions\OrganizationNotFound;

readonly final class OrganizationRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws OrganizationNotFound
     */
    public function get(string $organizationId): Organization
    {
        if (!Uuid::isValid($organizationId)) {
            throw new OrganizationNotFound();
        }

        $organization = $this->entityManager->find(Organization::class, $organizationId);

        return $organization ?? throw new OrganizationNotFound();
    }

    public function save(Organization $organization): void
    {
        $this->entityManager->persist($organization);
    }

    public function delete(Organization $organization): void
    {
        $this->entityManager->remove($organization);
    }

    /**
     * What is under the organization: its series and its one-time events (an edition never has its own)
     *
     * @return array{series: int, events: int}
     */
    public function countItems(Organization $organization): array
    {
        $series = $this->entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(CompetitionSeries::class, 's')
            ->where('s.organization = :organization')
            ->setParameter('organization', $organization)
            ->getQuery()
            ->getSingleScalarResult();

        $events = $this->entityManager->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(Competition::class, 'c')
            ->where('c.organization = :organization')
            ->setParameter('organization', $organization)
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'series' => is_numeric($series) ? (int) $series : 0,
            'events' => is_numeric($events) ? (int) $events : 0,
        ];
    }
}
