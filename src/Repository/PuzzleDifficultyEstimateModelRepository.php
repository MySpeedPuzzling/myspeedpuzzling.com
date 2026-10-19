<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\PuzzleDifficultyEstimateModel;

readonly final class PuzzleDifficultyEstimateModelRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(PuzzleDifficultyEstimateModel $model): void
    {
        $this->entityManager->persist($model);
    }
}
