<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
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
}
