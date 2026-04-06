<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\TableSpot;
use SpeedPuzzling\Web\Exceptions\TableSpotNotFound;

readonly final class TableSpotRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws TableSpotNotFound
     */
    public function get(string $spotId): TableSpot
    {
        if (!Uuid::isValid($spotId)) {
            throw new TableSpotNotFound();
        }

        $spot = $this->entityManager->find(TableSpot::class, $spotId);

        return $spot ?? throw new TableSpotNotFound();
    }

    public function save(TableSpot $spot): void
    {
        $this->entityManager->persist($spot);
    }

    public function delete(TableSpot $spot): void
    {
        $this->entityManager->remove($spot);
    }
}
