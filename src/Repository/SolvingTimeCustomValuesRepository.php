<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\SolvingTimeCustomValues;

/**
 * Write side of a player's own values on a result (docs/features/custom-fields.md). The read side is GetCustomValues
 * alone - nothing else may show them.
 */
readonly final class SolvingTimeCustomValuesRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(SolvingTimeCustomValues $values): void
    {
        $this->entityManager->persist($values);
    }

    public function delete(SolvingTimeCustomValues $values): void
    {
        $this->entityManager->remove($values);
    }

    public function find(PuzzleSolvingTime $solvingTime, Player $player): null|SolvingTimeCustomValues
    {
        return $this->entityManager->getRepository(SolvingTimeCustomValues::class)->findOneBy([
            'solvingTime' => $solvingTime,
            'player' => $player,
        ]);
    }

    /**
     * The rows of everybody on one result - for taking away the row of somebody who is no longer on it.
     *
     * @return list<SolvingTimeCustomValues>
     */
    public function ofSolvingTime(PuzzleSolvingTime $solvingTime): array
    {
        return $this->entityManager->getRepository(SolvingTimeCustomValues::class)->findBy([
            'solvingTime' => $solvingTime,
        ]);
    }
}
