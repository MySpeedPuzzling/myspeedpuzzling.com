<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\Manufacturer;
use SpeedPuzzling\Web\Entity\ManufacturerSlugRedirect;

readonly final class ManufacturerSlugRedirectRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(ManufacturerSlugRedirect $redirect): void
    {
        $this->entityManager->persist($redirect);
    }

    public function slugExists(string $slug): bool
    {
        return $this->entityManager->find(ManufacturerSlugRedirect::class, $slug) !== null;
    }

    /**
     * @return array<ManufacturerSlugRedirect>
     */
    public function findByManufacturer(Manufacturer $manufacturer): array
    {
        return $this->entityManager->getRepository(ManufacturerSlugRedirect::class)
            ->findBy(['manufacturer' => $manufacturer]);
    }
}
