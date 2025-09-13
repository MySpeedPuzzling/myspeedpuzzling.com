<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Collection;
use SpeedPuzzling\Web\Exceptions\CollectionNotFound;

readonly final class CollectionRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws CollectionNotFound
     */
    public function get(string $collectionId): Collection
    {
        if (!Uuid::isValid($collectionId)) {
            throw new CollectionNotFound();
        }

        $collection = $this->entityManager->find(Collection::class, $collectionId);

        if ($collection === null) {
            throw new CollectionNotFound();
        }

        return $collection;
    }

    public function save(Collection $collection): void
    {
        $this->entityManager->persist($collection);
    }

    public function delete(Collection $collection): void
    {
        $this->entityManager->remove($collection);
    }
}
