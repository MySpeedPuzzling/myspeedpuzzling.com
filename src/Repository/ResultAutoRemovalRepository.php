<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ResultAutoRemoval;
use SpeedPuzzling\Web\Exceptions\AutoRemovalNotFound;

readonly final class ResultAutoRemovalRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(ResultAutoRemoval $removal): void
    {
        $this->entityManager->persist($removal);
    }

    /**
     * @throws AutoRemovalNotFound
     */
    public function get(string $removalId): ResultAutoRemoval
    {
        if (!Uuid::isValid($removalId)) {
            throw new AutoRemovalNotFound();
        }

        return $this->entityManager->find(ResultAutoRemoval::class, $removalId) ?? throw new AutoRemovalNotFound();
    }

    /**
     * The copy that stayed when this result was removed automatically - links to the removed id lead there.
     */
    public function findKeptTimeIdOf(string $removedTimeId): null|string
    {
        if (!Uuid::isValid($removedTimeId)) {
            return null;
        }

        $removal = $this->entityManager->getRepository(ResultAutoRemoval::class)->findOneBy([
            'removedTimeId' => $removedTimeId,
            'undoneAt' => null,
        ]);

        return $removal?->keptTimeId->toString();
    }
}
