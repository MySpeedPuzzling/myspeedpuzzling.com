<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Exceptions\PuzzleSolvingTimeNotFound;

readonly final class PuzzleSolvingTimeRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws PuzzleSolvingTimeNotFound
     */
    public function get(string $puzzleSolvingTimeId): PuzzleSolvingTime
    {
        if (!Uuid::isValid($puzzleSolvingTimeId)) {
            throw new PuzzleSolvingTimeNotFound();
        }

        $puzzle = $this->entityManager->find(PuzzleSolvingTime::class, $puzzleSolvingTimeId);

        return $puzzle ?? throw new PuzzleSolvingTimeNotFound();
    }
}
