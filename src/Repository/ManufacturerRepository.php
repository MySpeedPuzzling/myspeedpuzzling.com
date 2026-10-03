<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Entity\Manufacturer;
use SpeedPuzzling\Web\Exceptions\ManufacturerNotFound;

readonly final class ManufacturerRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws ManufacturerNotFound
     */
    public function get(string $manufacturerId): Manufacturer
    {
        $uuid = Uuid::fromString($manufacturerId);

        $manufacturer = $this->entityManager->find(Manufacturer::class, $uuid);

        return $manufacturer ?? throw new ManufacturerNotFound();
    }

    public function slugExists(string $slug): bool
    {
        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(manufacturer.id)')
            ->from(Manufacturer::class, 'manufacturer')
            ->where('manufacturer.slug = :slug')
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }

    /**
     * Is there an approved brand of this name (case-insensitive) other than the given ones?
     *
     * @param list<UuidInterface> $exceptIds
     */
    public function approvedNameExists(string $name, array $exceptIds): bool
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('COUNT(manufacturer.id)')
            ->from(Manufacturer::class, 'manufacturer')
            ->where('manufacturer.approved = true')
            ->andWhere('LOWER(TRIM(manufacturer.name)) = :name')
            ->setParameter('name', mb_strtolower(trim($name)));

        if ($exceptIds !== []) {
            $queryBuilder
                ->andWhere('manufacturer.id NOT IN (:exceptIds)')
                ->setParameter(
                    'exceptIds',
                    array_map(static fn (UuidInterface $id): string => $id->toString(), $exceptIds),
                    ArrayParameterType::STRING,
                );
        }

        return (int) $queryBuilder->getQuery()->getSingleScalarResult() > 0;
    }

    public function delete(Manufacturer $manufacturer): void
    {
        $this->entityManager->remove($manufacturer);
    }
}
